<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Support;

use Turnkey\AuthClient\TokenProviderInterface;

final class FixedTokenProvider implements TokenProviderInterface
{
    public int $calls = 0;

    /** @param list<string> $tokens returned in order; the last one repeats */
    public function __construct(private array $tokens = ['test-token'], private ?\Throwable $error = null)
    {
    }

    public static function failing(\Throwable $error): self
    {
        return new self([], $error);
    }

    public function getToken(): string
    {
        $this->calls++;
        if ($this->error !== null) {
            throw $this->error;
        }

        return count($this->tokens) > 1 ? array_shift($this->tokens) : $this->tokens[0];
    }
}
