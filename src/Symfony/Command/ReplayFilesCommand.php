<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Turnkey\AuditClient\Replay\FileReplayer;
use Turnkey\AuditClient\Replay\ReplayResult;
use Turnkey\AuditClient\Support\Backoff;

/**
 * Delivers buffered JSONL files to the audit service.
 *
 *     bin/console audit:replay                           # cron, one pass
 *     bin/console audit:replay --loop --time-limit=3600  # supervisor worker
 *
 * Only one replayer runs at a time (lock file in the audit directory); a second instance exits with 0.
 */
#[AsCommand(name: 'audit:replay', description: 'Deliver audit events buffered in JSONL files to the audit service')]
final class ReplayFilesCommand extends Command implements SignalableCommandInterface
{
    private ?LoopControl $loop = null;

    public function __construct(private readonly FileReplayer $replayer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Keep running and re-scan the directory periodically')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between passes (loop mode)', '30')
            ->addOption('max-backoff', null, InputOption::VALUE_REQUIRED, 'Maximum seconds to back off while the audit service is unavailable', '300')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds (0 = unlimited)', '0')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Stop when memory usage reaches this many MB (0 = unlimited)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $loopMode = (bool) $input->getOption('loop');
        $intervalMs = max(1, (int) $input->getOption('interval')) * 1000;
        $maxBackoffMs = max(1, (int) $input->getOption('max-backoff')) * 1000;

        $this->loop = $loop = new LoopControl(
            max(0, (int) $input->getOption('time-limit')),
            max(0, (int) $input->getOption('memory-limit')) * 1024 * 1024,
        );

        $attempt = 0;
        do {
            try {
                $result = $this->replayer->replayOnce();
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>audit:replay: %s</error>', $e->getMessage()));

                return Command::FAILURE;
            }

            if ($result->status === ReplayResult::LOCKED) {
                $output->writeln('audit:replay: already running');

                return Command::SUCCESS;
            }

            $output->writeln(sprintf(
                'audit:replay: %s, files %d, delivered %d, dropped %d',
                $result->status,
                $result->filesCompleted,
                $result->eventsDelivered,
                $result->eventsDropped,
            ), OutputInterface::VERBOSITY_VERBOSE);

            if (!$loopMode) {
                break;
            }

            $loop->sleep($result->shouldBackOff() ? Backoff::delay($intervalMs, $maxBackoffMs, $attempt++) : $intervalMs);
            if (!$result->shouldBackOff()) {
                $attempt = 0;
            }
        } while (!$loop->shouldStop());

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
        $this->loop?->requestStop();

        return false;
    }
}
