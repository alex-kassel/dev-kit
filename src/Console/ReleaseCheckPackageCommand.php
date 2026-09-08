<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\ReleaseChecker;
use Illuminate\Console\Command;
use RuntimeException;

use function Termwind\render;

class ReleaseCheckPackageCommand extends Command
{
    protected $signature = 'pkg:release-check
        {package : The vendor/package name or relative package path}
        {--json : Output machine-readable JSON summary}';

    protected $description = 'Run pre-flight release-gate checks (clean tree, audit freshness, quality, README)';

    public function handle(ReleaseChecker $checker): int
    {
        $rawPackage = $this->argument('package');
        $package = is_string($rawPackage) ? $rawPackage : '';
        $isJson = (bool) $this->option('json');

        try {
            $result = $checker->check($this->laravel->basePath(), $package);
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

            return match ($result['verdict']) {
                'READY' => self::SUCCESS,
                'ACTION_REQUIRED' => 2,
                default => self::FAILURE,
            };
        }

        render(<<<HTML
            <div class="my-1">
                <span class="px-1 bg-purple-600 text-white font-bold">RELEASE-GATE PRE-FLIGHT</span>
                <span class="ml-1 text-gray-400">{$result['path']}</span>
                <span class="ml-2 text-gray-500">Tag: {$result['latest_tag']}</span>
            </div>
        HTML);

        foreach ($result['checks'] as $check) {
            $badge = match ($check['status']) {
                'passed' => '<span class="px-1 bg-green-600 text-white font-bold">PASS</span>',
                'failed' => '<span class="px-1 bg-red-600 text-white font-bold">FAIL</span>',
                'action_required' => '<span class="px-1 bg-yellow-500 text-black font-bold">WARN</span>',
                'not_configured' => '<span class="px-1 bg-gray-600 text-white font-bold">NONE</span>',
                'skipped' => '<span class="px-1 bg-gray-600 text-white font-bold">SKIP</span>',
                default => '<span class="px-1 bg-zinc-600 text-white font-bold">UNK</span>',
            };

            render(<<<HTML
                <div class="flex space-x-1">
                    <span>{$badge}</span>
                    <span class="font-bold text-gray-200">{$check['name']}</span>
                </div>
            HTML);

            if ($check['status'] !== 'passed') {
                render(<<<HTML
                    <div class="ml-4 text-zinc-400">
                        └─ {$check['message']}
                    </div>
                HTML);
            }
        }

        if ($result['verdict'] === 'READY') {
            render(<<<'HTML'
                <div class="mt-1 p-1 bg-green-900 text-green-100 font-bold">
                    ✔ RELEASE GATE: READY TO PUBLISH
                </div>
            HTML);
        } elseif ($result['verdict'] === 'ACTION_REQUIRED') {
            render(<<<'HTML'
                <div class="mt-1 p-1 bg-yellow-900 text-yellow-100 font-bold">
                    ▲ RELEASE GATE: ACTION / DECISION REQUIRED
                </div>
            HTML);
        } else {
            render(<<<'HTML'
                <div class="mt-1 p-1 bg-red-900 text-white font-bold">
                    ✖ RELEASE GATE: BLOCKED BY FAILURES
                </div>
            HTML);
        }

        return match ($result['verdict']) {
            'READY' => self::SUCCESS,
            'ACTION_REQUIRED' => 2,
            default => self::FAILURE,
        };
    }
}
