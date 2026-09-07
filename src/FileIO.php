<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use RuntimeException;

class FileIO
{
    public static function read(string $path): string
    {
        if (! file_exists($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read file: {$path}");
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        return $contents;
    }

    public static function write(string $path, string $contents, ?string $originalForLineEndings = null): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }

        if ($originalForLineEndings !== null) {
            $newline = str_contains($originalForLineEndings, "\r\n") ? "\r\n" : "\n";
            $contents = str_replace(["\r\n", "\n"], $newline, $contents);
        }

        $result = @file_put_contents($path, $contents);
        if ($result === false) {
            throw new RuntimeException("Cannot write file: {$path}");
        }
    }
}
