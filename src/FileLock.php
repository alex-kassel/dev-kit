<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Closure;
use RuntimeException;

/**
 * @internal
 */
class FileLock
{
    /**
     * Acquire an exclusive advisory lock on the given file path and execute the callback.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(string $lockFilePath, Closure $callback, int $timeoutSeconds = 10): mixed
    {
        $dir = dirname($lockFilePath);
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create directory for lock file: {$dir}");
        }

        $handle = @fopen($lockFilePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Cannot open or create lock file: {$lockFilePath}");
        }

        $startTime = microtime(true);
        $acquired = false;

        while ((microtime(true) - $startTime) < $timeoutSeconds) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }
            usleep(random_int(10000, 50000));
        }

        if (! $acquired) {
            fclose($handle);
            throw new RuntimeException("Failed to acquire exclusive lock on {$lockFilePath} within {$timeoutSeconds}s.");
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
