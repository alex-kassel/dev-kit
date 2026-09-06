<?php

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\ReleaseChecker;
use Illuminate\Console\Command;
use RuntimeException;

class ReleaseCheckPackageCommand extends Command
{
    protected $signature = 'pkg:release-check
        {package : The vendor/package name or relative package path}
        {--json : Output machine-readable JSON summary}';

    protected $aliases = ['package:release-check'];

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

        $this->newLine();
        $this->line('============================================================');
        $this->line(" 🚀 Release-Gate Pre-Flight: {$result['path']}");
        $this->line('============================================================');
        $this->newLine();

        $this->line("Latest Git Tag: {$result['latest_tag']}");
        $this->newLine();

        foreach ($result['checks'] as $check) {
            $badge = match ($check['status']) {
                'passed' => ' <info>✔ PASS</info>',
                'failed' => ' <error>✖ FAIL</error>',
                'action_required' => ' <comment>▲ WARN</comment>',
                'not_configured' => ' <fg=gray>○ NONE</fg=gray>',
                'skipped' => ' <fg=gray>- SKIP</fg=gray>',
                default => ' ? UNK ',
            };

            $this->line(sprintf('%-8s | %-38s', $badge, $check['name']));
            if ($check['status'] !== 'passed') {
                $this->line("         └─ {$check['message']}");
            }
        }

        $this->newLine();
        $this->line('------------------------------------------------------------');
        if ($result['verdict'] === 'READY') {
            $this->info('✔ RELEASE GATE: READY TO PUBLISH');
        } elseif ($result['verdict'] === 'ACTION_REQUIRED') {
            $this->warn('▲ RELEASE GATE: ACTION / DECISION REQUIRED');
        } else {
            $this->error('✖ RELEASE GATE: BLOCKED BY FAILURES');
        }
        $this->line('============================================================');

        return match ($result['verdict']) {
            'READY' => self::SUCCESS,
            'ACTION_REQUIRED' => 2,
            default => self::FAILURE,
        };
    }
}
