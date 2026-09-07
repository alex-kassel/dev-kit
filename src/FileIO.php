<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
class FileIO
{
    /** Remove a validated directory without following links or invoking a shell. */
    public static function removeDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Refusing to recursively remove a linked directory: '.$path);
        }
        if (PHP_OS_FAMILY === 'Windows' && is_dir($path)) {
            // Git objects are read-only on Windows. Symfony handles traversal and removal,
            // but requires these file attributes to be cleared beforehand.
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($files as $file) {
                if (! $file instanceof \SplFileInfo) {
                    continue;
                }
                $filePath = $file->getPathname();
                $resolved = $file->getRealPath();
                // PHP on Windows may report both isLink() and isDir() false for a
                // junction. Its resolved target still identifies the redirection.
                $redirected = $resolved !== false && strcasecmp(str_replace('\\', '/', $resolved), str_replace('\\', '/', $filePath)) !== 0;
                $directoryLink = $resolved !== false && $redirected && is_dir($resolved);
                if ($directoryLink || ($file->isLink() && ! file_exists($filePath)) || (! $file->isFile() && ! $file->isDir())) {
                    // Windows directory links/junctions require rmdir, not unlink.
                    // The iterator does not follow links; only the link itself is removed.
                    if (! @rmdir($filePath) && $directoryLink) {
                        throw new RuntimeException('Cannot remove directory link: '.$filePath);
                    }
                } elseif ($file->isFile() && ! $file->isLink() && ! $redirected && ! $file->isWritable()) {
                    (new Filesystem)->chmod($filePath, 0666);
                }
            }
            unset($files);
        }
        (new Filesystem)->remove($path);
    }

    public static function read(string $path): string
    {
        if (! File::exists($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read file: {$path}");
        }

        try {
            return File::get($path);
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to read file: {$path}", 0, $e);
        }
    }

    public static function write(string $path, string $contents, ?string $originalForLineEndings = null): void
    {
        $dir = dirname($path);
        try {
            File::ensureDirectoryExists($dir);
        } catch (\Throwable) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }

        if ($originalForLineEndings !== null) {
            $newline = str_contains($originalForLineEndings, "\r\n") ? "\r\n" : "\n";
            $contents = str_replace("\n", $newline, str_replace("\r\n", "\n", $contents));
        }

        if (is_link($path)) {
            throw new RuntimeException("Refusing to replace a linked file: {$path}");
        }

        try {
            (new Filesystem)->dumpFile($path, $contents);
        } catch (IOExceptionInterface $exception) {
            throw new RuntimeException("Cannot write file: {$path}", 0, $exception);
        }
    }
}
