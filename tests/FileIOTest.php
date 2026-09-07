<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\FileIO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FileIOTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/devkit_fileio_test_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->tempDir);
        parent::tearDown();
    }

    public function test_read_and_write_successfully(): void
    {
        $file = $this->tempDir.'/sub/dir/test.txt';
        FileIO::write($file, "Hello World\n");

        $this->assertFileExists($file);
        $this->assertSame("Hello World\n", FileIO::read($file));
    }

    public function test_write_preserves_windows_crlf_line_endings(): void
    {
        $file = $this->tempDir.'/crlf.txt';
        $original = "first\r\nsecond\r\n";
        FileIO::write($file, "one\ntwo\n", $original);

        $this->assertSame("one\r\ntwo\r\n", file_get_contents($file));
    }

    public function test_read_throws_exception_on_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read file');
        FileIO::read($this->tempDir.'/missing.txt');
    }

    public function test_existing_crlf_content_is_not_double_converted(): void
    {
        $file = $this->tempDir.'/crlf.txt';
        FileIO::write($file, "one\r\ntwo\r\n", "original\r\n");
        $this->assertSame("one\r\ntwo\r\n", FileIO::read($file));
    }

    public function test_atomic_replacement_updates_existing_file(): void
    {
        $file = $this->tempDir.'/manifest.json';
        FileIO::write($file, '{"old":true}');
        FileIO::write($file, '{"new":true}');
        $this->assertSame('{"new":true}', FileIO::read($file));
        $this->assertSame(['manifest.json'], array_values(array_diff(scandir($this->tempDir), ['.', '..'])));
    }

    private function deleteRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteRecursive($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
