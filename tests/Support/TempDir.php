<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Support;

final class TempDir
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/php-audit-test-' . bin2hex(random_bytes(6));
        mkdir($this->path, 0700, true);
    }

    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        @chmod($this->path, 0700);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->path);
    }

    /** @return list<string> */
    public function files(): array
    {
        $names = array_values(array_filter(scandir($this->path) ?: [], static fn ($n) => $n !== '.' && $n !== '..'));
        sort($names);

        return $names;
    }
}
