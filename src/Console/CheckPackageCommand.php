<?php

declare(strict_types=1);

namespace AlexKassel\DevKit\Console;

use AlexKassel\DevKit\OrganizationResolver;
use AlexKassel\DevKit\PackageInventory;
use AlexKassel\DevKit\PackageVerifier;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

use function Laravel\Prompts\select;

class CheckPackageCommand extends Command
{
    protected $signature = 'pkg:check
        {package? : Composer package name (e.g. alex-kassel/history-engine)}
        {--all : Verify all discovered local packages in workspace}
        {--fix : Automatically fix code style issues with Pint}
        {--only= : Comma-separated list of checks to run (composer,pint,phpstan,tests,isolated)}
        {--isolated : Install and test an independent temporary package copy}
        {--parallel : Run workspace package matrix checks concurrently}
        {--no-parallel : Run workspace checks sequentially}
        {--json : Emit a machine-readable result}';

    protected $description = 'Run full quality verification suite (Composer, Pint, PHPStan, Tests, Isolated)';

    public function handle(PackageVerifier $verifier, PackageInventory $inventory, OrganizationResolver $orgResolver): int
    {
        $rawPackage = $this->argument('package');
        $package = is_string($rawPackage) && $rawPackage !== '' ? $rawPackage : null;
        $all = (bool) $this->option('all');
        $fix = (bool) $this->option('fix');
        $isJson = (bool) $this->option('json');
        $isolated = (bool) $this->option('isolated');
        $noParallel = (bool) $this->option('no-parallel');
        $parallel = ! $noParallel;
        $onlyOption = $this->option('only');
        $only = is_string($onlyOption) ? array_map('trim', explode(',', $onlyOption)) : null;

        $root = $this->laravel->basePath();

        if ($all) {
            try {
                $result = $verifier->verifyAll($root, $fix, $only, $parallel, $isolated);
            } catch (RuntimeException $exception) {
                if ($isJson) {
                    $this->line(json_encode(['schema_version' => 1, 'status' => 'error', 'error' => ['code' => 'VERIFICATION_ERROR', 'message' => $exception->getMessage()]], JSON_THROW_ON_ERROR));
                } else {
                    $this->error($exception->getMessage());
                }

                return self::FAILURE;
            }
            if ($isJson) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->renderAllSummary($result);
            }

            return $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        if ($package === null) {
            if ($isJson) {
                $this->line(json_encode([
                    'schema_version' => 1,
                    'status' => 'error',
                    'error' => ['code' => 'MISSING_PACKAGE', 'message' => 'Missing package argument. Use --all or specify package name.'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }

            // Interactive prompt with dynamic organization resolution
            /** @var Repository $configRepository */
            $configRepository = $this->laravel->make('config');
            $configuredOrgs = $configRepository->get('dev-kit.organizations', []);
            $organizations = $orgResolver->resolve(
                $root,
                null,
                null,
                is_array($configuredOrgs) ? $configuredOrgs : []
            );

            $packages = $inventory->inspect($root, $organizations, []);
            if ($packages === []) {
                $this->error('No local packages found in workspace.');

                return self::FAILURE;
            }

            $options = array_column($packages, 'name');
            $selected = select('Select package to verify:', $options);
            $package = is_string($selected) ? $selected : (string) $selected;
        }

        try {
            $result = $verifier->verify($root, $package, $fix, $only, $isolated);
        } catch (RuntimeException $exception) {
            if ($isJson) {
                $this->line(json_encode([
                    'schema_version' => 1,
                    'status' => 'error',
                    'error' => ['code' => 'VERIFICATION_ERROR', 'message' => $exception->getMessage()],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderSingleResult($result);
        }

        return $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderSingleResult(array $result): void
    {
        $this->line('============================================================');
        $this->line(' 📦 Package Verification: '.$result['path']);
        $this->line('============================================================');
        $this->newLine();

        /** @var array<string, array{name: string, status: string, exit_code: int, command: string, output: string, duration_ms: int}> $checks */
        $checks = $result['checks'];

        foreach ($checks as $check) {
            $badge = match ($check['status']) {
                'passed' => '<fg=green>✔ PASS</>',
                'failed' => '<fg=red>✖ FAIL</>',
                'skipped' => '<fg=yellow>- SKIP</>',
                'not_configured' => '<fg=gray>○ NONE</>',
                default => '<fg=white>? UNK</>',
            };

            $this->line(sprintf(' %-15s | %-30s (%d ms)', $badge, $check['name'], $check['duration_ms']));
            if ($check['status'] === 'failed' && ! empty($check['output'])) {
                $this->line('------------------------------------------------------------');
                $this->line($check['output']);
                $this->line('------------------------------------------------------------');
            }
        }

        $this->newLine();
        $this->line('------------------------------------------------------------');
        /** @var array{passed: int, failed: int} $summary */
        $summary = $result['summary'];

        if ($result['status'] === 'passed') {
            $this->info("✔ VERIFICATION PASSED: All {$summary['passed']} active check(s) passed.");
        } elseif ($result['status'] === 'incomplete') {
            $this->error('VERIFICATION INCOMPLETE: required tools or configuration are missing.');
        } else {
            $this->error("✖ VERIFICATION FAILED: {$summary['failed']} check(s) failed.");
        }
        $this->line('============================================================');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderAllSummary(array $result): void
    {
        $this->line('============================================================');
        $this->line(" 📦 Monorepo Workspace Verification Matrix ({$result['total']} packages)");
        $this->line('============================================================');
        $this->newLine();

        $rows = [];
        foreach ($result['results'] as $item) {
            $checks = $item['checks'];
            $fmt = fn (string $key): string => match ($checks[$key]['status'] ?? 'skipped') {
                'passed' => '<fg=green>✔</>',
                'failed' => '<fg=red>✖</>',
                'not_configured' => '<fg=gray>○</>',
                default => '<fg=yellow>-</>',
            };

            $rows[] = [
                $item['package'],
                $fmt('composer'),
                $fmt('pint'),
                $fmt('phpstan'),
                $fmt('tests'),
                $fmt('isolated'),
                $item['status'] === 'passed' ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
            ];
        }

        $this->table(['Package', 'Composer', 'Pint', 'PHPStan', 'Tests', 'Isolated', 'Status'], $rows);

        $this->newLine();
        if ($result['status'] === 'passed') {
            $this->info("✔ All {$result['total']} packages passed verification.");
        } elseif ($result['status'] === 'incomplete') {
            $this->error('VERIFICATION INCOMPLETE: no packages were checked.');
        } else {
            $this->error("✖ {$result['failed']} of {$result['total']} packages failed verification.");
        }
        $this->line('============================================================');
    }
}
