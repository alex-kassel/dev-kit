<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Tests;

use AlexKassel\DevKit\FileLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FileLockTest extends TestCase
{
    private string $lockFile;

    protected function setUp(): void
    {
        $this->lockFile = sys_get_temp_dir().'/test-lock-'.bin2hex(random_bytes(8)).'.lock';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    public function test_executes_callback_and_returns_result(): void
    {
        $result = FileLock::run($this->lockFile, function (): string {
            return 'success';
        });

        $this->assertSame('success', $result);
    }

    public function test_releases_lock_on_exception(): void
    {
        try {
            FileLock::run($this->lockFile, function (): void {
                throw new RuntimeException('Inside lock');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('Inside lock', $e->getMessage());
        }

        // Must be able to re-acquire immediately
        $result = FileLock::run($this->lockFile, fn (): bool => true);
        $this->assertTrue($result);
    }
}
