<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\ReadmeValidator;
use Illuminate\Console\Command;
use RuntimeException;

use function Termwind\render;

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

        render(<<<HTML
            <div class="my-1">
                <span class="px-1 bg-blue-600 text-white font-bold">README VERIFICATION</span>
                <span class="ml-1 text-gray-400">{$result['path']}</span>
            </div>
        HTML);

        foreach ($result['checks'] as $check) {
            $badge = $check['status'] === 'passed'
                ? '<span class="px-1 bg-green-600 text-white font-bold">PASS</span>'
                : '<span class="px-1 bg-red-600 text-white font-bold">FAIL</span>';

            render(<<<HTML
                <div class="flex space-x-1">
                    <span>{$badge}</span>
                    <span class="font-bold text-gray-200">{$check['name']}</span>
                </div>
            HTML);

            if ($check['status'] === 'failed') {
                render(<<<HTML
                    <div class="ml-4 text-red-400 text-xs">
                        └─ {$check['message']}
                    </div>
                HTML);
            }
        }

        if ($result['status'] === 'passed') {
            render(<<<'HTML'
                <div class="mt-1 p-1 bg-green-900 text-green-100 font-bold">
                    ✔ README VERIFICATION PASSED: Fully compliant with standard.
                </div>
            HTML);
        } else {
            render(<<<HTML
                <div class="mt-1 p-1 bg-red-900 text-white font-bold">
                    ✖ README VERIFICATION FAILED: {$result['summary']['failed']} issue(s) detected.
                </div>
            HTML);
        }

        return $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
