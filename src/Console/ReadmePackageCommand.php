<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\ReadmeValidator;
use Illuminate\Console\Command;
use RuntimeException;

class ReadmePackageCommand extends Command
{
    protected $signature = 'pkg:readme
        {package : The vendor/package name or relative package path}
        {--json : Output machine-readable JSON summary}';

    protected $description = 'Validate package README.md compliance with unified enterprise standard';

    public function handle(ReadmeValidator $validator): int
    {
        $rawPackage = $this->argument('package');
        $package = is_string($rawPackage) ? $rawPackage : '';
        $isJson = (bool) $this->option('json');

        try {
            $result = $validator->validate($this->laravel->basePath(), $package);
        } catch (RuntimeException $e) {
            if ($isJson) {
                $this->line(json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Error: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('============================================================');
        $this->line(" 📄 Package README Verification: {$result['path']}");
        $this->line('============================================================');
        $this->newLine();

        foreach ($result['checks'] as $check) {
            $badge = $check['status'] === 'passed' ? ' <info>✔ PASS</info>' : ' <error>✖ FAIL</error>';
            $this->line(sprintf('%-8s | %-38s', $badge, $check['name']));
            if ($check['status'] === 'failed') {
                $this->line("         └─ {$check['message']}");
            }
        }

        $this->newLine();
        $this->line('------------------------------------------------------------');
        if ($result['status'] === 'passed') {
            $this->info('✔ README VERIFICATION PASSED: Fully compliant with standard.');
        } else {
            $this->error("✖ README VERIFICATION FAILED: {$result['summary']['failed']} issue(s) detected.");
        }
        $this->line('============================================================');

        return $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
