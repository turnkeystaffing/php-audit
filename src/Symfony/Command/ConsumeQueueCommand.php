<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Turnkey\AuditClient\Consumer\RedisQueueConsumer;
use Turnkey\AuditClient\Support\Backoff;

/**
 * Drains the Redis fallback queue to the audit service.
 *
 *     bin/console audit:consume-queue --time-limit=55          # cron (every minute)
 *     bin/console audit:consume-queue --loop --time-limit=3600 # supervisor worker
 *
 * Several instances may run in parallel (RPOP is atomic).
 */
#[AsCommand(name: 'audit:consume-queue', description: 'Deliver audit events from the Redis fallback queue to the audit service')]
final class ConsumeQueueCommand extends Command implements SignalableCommandInterface
{
    private ?LoopControl $loop = null;

    public function __construct(private readonly RedisQueueConsumer $consumer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Keep running and poll the queue when it is empty')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when the queue is empty (loop mode)', '5')
            ->addOption('max-backoff', null, InputOption::VALUE_REQUIRED, 'Maximum seconds to back off while the audit service is unavailable', '300')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Stop after this many batches (0 = unlimited)', '0')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds (0 = unlimited)', '0')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Stop when memory usage reaches this many MB (0 = unlimited)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $loopMode = (bool) $input->getOption('loop');
        $intervalMs = max(1, (int) $input->getOption('interval')) * 1000;
        $maxBackoffMs = max(1, (int) $input->getOption('max-backoff')) * 1000;
        $maxBatches = max(0, (int) $input->getOption('max-batches'));

        $this->loop = $loop = new LoopControl(
            max(0, (int) $input->getOption('time-limit')),
            max(0, (int) $input->getOption('memory-limit')) * 1024 * 1024,
        );

        $batches = 0;
        $attempt = 0;
        $totals = ['delivered' => 0, 'requeued' => 0, 'dropped' => 0];

        while (!$loop->shouldStop() && ($maxBatches === 0 || $batches < $maxBatches)) {
            try {
                $result = $this->consumer->consumeBatch();
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>audit:consume-queue: redis error: %s</error>', $e->getMessage()));
                if (!$loopMode) {
                    return Command::FAILURE;
                }
                $loop->sleep(Backoff::delay($intervalMs, $maxBackoffMs, $attempt++));
                continue;
            }

            if ($result->isEmpty()) {
                if (!$loopMode) {
                    break;
                }
                $attempt = 0;
                $loop->sleep($intervalMs);
                continue;
            }

            $batches++;
            $totals['delivered'] += $result->delivered;
            $totals['requeued'] += $result->requeued;
            $totals['dropped'] += $result->dropped;

            if ($result->serviceUnavailable()) {
                if (!$loopMode) {
                    $output->writeln('audit:consume-queue: audit service unavailable, events returned to queue');
                    break;
                }
                $loop->sleep(Backoff::delay($intervalMs, $maxBackoffMs, $attempt++));
                continue;
            }

            $attempt = 0;
        }

        $output->writeln(sprintf(
            'audit:consume-queue: %d batch(es), delivered %d, requeued %d, dropped %d',
            $batches,
            $totals['delivered'],
            $totals['requeued'],
            $totals['dropped'],
        ), OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // Finish the current batch (deliver or requeue), then exit the loop.
        $this->loop?->requestStop();

        return false;
    }
}
