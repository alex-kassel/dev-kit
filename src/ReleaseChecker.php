<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ReleaseChecker
{
    public function __construct(
        private readonly PackageVerifier $verifier = new PackageVerifier,
        private readonly ReadmeValidator $readmeValidator = new ReadmeValidator,
        private readonly PackagePathResolver $pathResolver = new PackagePathResolver
    ) {}

    /**
     * @return array{
     *     package: string,
     *     path: string,
     *     verdict: string,
     *     latest_tag: string,
     *     checks: array<string, array{name: string, status: string, message: string}>
     * }
     */
    public function check(string $root, string $package): array
    {
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }

        $packagePath = $this->pathResolver->resolve($root, $package);
        $relPackagePath = str_replace([$root.DIRECTORY_SEPARATOR, $root.'/'], '', $packagePath);
        $relPackagePath = str_replace('\\', '/', $relPackagePath);

        $checks = [];

        // 1. Independent Git Repository & Working Tree Cleanliness
        $gitDir = $packagePath.DIRECTORY_SEPARATOR.'.git';
        if (! is_dir($gitDir)) {
            $checks['git_repo'] = [
                'name' => 'Independent Git Repository',
                'status' => 'failed',
                'message' => 'No independent .git directory found inside package root.',
            ];
            $checks['git_tree'] = [
                'name' => 'Clean Git Working Tree',
                'status' => 'skipped',
                'message' => 'Skipped because git is not initialized.',
            ];
            $latestTag = 'none (not a git repo)';
        } else {
            $checks['git_repo'] = [
                'name' => 'Independent Git Repository',
                'status' => 'passed',
                'message' => 'Independent git repository verified.',
            ];

            $statusResult = Process::path($packagePath)->run(['git', 'status', '--porcelain']);
            $statusOutput = trim($statusResult->output());

            if ($statusResult->successful() && empty($statusOutput)) {
                $checks['git_tree'] = [
                    'name' => 'Clean Git Working Tree',
                    'status' => 'passed',
                    'message' => 'Working tree is clean. No uncommitted changes.',
                ];
            } else {
                $checks['git_tree'] = [
                    'name' => 'Clean Git Working Tree',
                    'status' => 'failed',
                    'message' => "Uncommitted or untracked changes detected:\n".$statusOutput,
                ];
            }

            // Latest Tag
            $tagResult = Process::path($packagePath)->run(['git', 'tag', '-l', '--sort=-v:refname']);
            $tags = array_values(array_filter(explode("\n", trim($tagResult->output()))));
            $latestTag = ! empty($tags) ? trim($tags[0]) : 'none (initial release)';
        }

        // 2. Audit Certificate Freshness Gate
        $releaseGateFile = $packagePath.DIRECTORY_SEPARATOR.'RELEASE-GATE.md';
        if (File::exists($releaseGateFile)) {
            $gateContent = File::get($releaseGateFile);
            $certifiedCommit = $this->extractCertifiedCommit($gateContent);

            if ($certifiedCommit !== null && is_dir($gitDir)) {
                $deltaResult = Process::path($packagePath)->run(
                    ['git', 'rev-list', '--count', "{$certifiedCommit}..HEAD", '--', 'src/', 'config/', 'database/', 'composer.json']
                );

                if ($deltaResult->successful()) {
                    $deltaCount = (int) trim($deltaResult->output());

                    if ($deltaCount === 0) {
                        $checks['audit_freshness'] = [
                            'name' => 'Audit Freshness Gate',
                            'status' => 'passed',
                            'message' => "Audit certificate is up to date (0 source commits since certified {$certifiedCommit}).",
                        ];
                    } else {
                        $checks['audit_freshness'] = [
                            'name' => 'Audit Freshness Gate',
                            'status' => 'action_required',
                            'message' => "Source code drift detected: {$deltaCount} commit(s) made since audit certificate at {$certifiedCommit}. Consider running full audit.",
                        ];
                    }
                } else {
                    $checks['audit_freshness'] = [
                        'name' => 'Audit Freshness Gate',
                        'status' => 'action_required',
                        'message' => "Certified commit {$certifiedCommit} not reachable in git history. Stale certificate or rebased branch.",
                    ];
                }
            } elseif ($certifiedCommit === null) {
                $checks['audit_freshness'] = [
                    'name' => 'Audit Freshness Gate',
                    'status' => 'action_required',
                    'message' => 'RELEASE-GATE.md present but missing a certified commit hash.',
                ];
            } else {
                $checks['audit_freshness'] = [
                    'name' => 'Audit Freshness Gate',
                    'status' => 'passed',
                    'message' => "RELEASE-GATE.md present with certified commit {$certifiedCommit} (git not initialized).",
                ];
            }
        } else {
            $checks['audit_freshness'] = [
                'name' => 'Audit Freshness Gate',
                'status' => 'not_configured',
                'message' => 'No RELEASE-GATE.md found (unaudited package).',
            ];
        }

        // 3. Distribution Export-Ignore in .gitattributes
        $gitattrPath = $packagePath.DIRECTORY_SEPARATOR.'.gitattributes';
        if (File::exists($gitattrPath)) {
            $attrContent = File::get($gitattrPath);
            if (str_contains($attrContent, 'export-ignore')) {
                $checks['export_ignore'] = [
                    'name' => 'Distribution Archive (.gitattributes)',
                    'status' => 'passed',
                    'message' => 'export-ignore directives configured for release hygiene.',
                ];
            } else {
                $checks['export_ignore'] = [
                    'name' => 'Distribution Archive (.gitattributes)',
                    'status' => 'failed',
                    'message' => '.gitattributes exists but missing export-ignore directives for dev files.',
                ];
            }
        } else {
            $checks['export_ignore'] = [
                'name' => 'Distribution Archive (.gitattributes)',
                'status' => 'failed',
                'message' => 'Missing .gitattributes file in package.',
            ];
        }

        // 4. Code Quality Suite (PackageVerifier with standalone installation)
        $verifyResult = $this->verifier->verify($root, $package, false, null, true);
        $qualityPassed = $verifyResult['status'] === 'passed';
        foreach (['composer', 'pint', 'phpstan', 'tests', 'isolated'] as $requiredCheck) {
            $qualityPassed = $qualityPassed && ($verifyResult['checks'][$requiredCheck]['status'] ?? null) === 'passed';
        }
        $checks['code_quality'] = [
            'name' => 'Code Quality Suite (Pint, PHPStan, Tests, Composer, Isolated)',
            'status' => $qualityPassed ? 'passed' : 'failed',
            'message' => $qualityPassed ? 'All quality, tests, and standalone installation checks passed.' : 'Required quality checks failed or are incomplete. Run php artisan pkg:check --isolated for details.',
        ];

        // 5. README Standard Compliance (ReadmeValidator)
        $readmeResult = $this->readmeValidator->validate($root, $package);
        $checks['readme_compliance'] = [
            'name' => 'README Standard Compliance',
            'status' => $readmeResult['status'] === 'passed' ? 'passed' : 'failed',
            'message' => $readmeResult['status'] === 'passed' ? 'README matches canonical standard.' : 'README has violations. Run php artisan pkg:readme for details.',
        ];

        // Calculate final verdict
        $hasHardFailures = false;
        $hasActionRequired = false;

        foreach ($checks as $c) {
            if ($c['status'] === 'failed') {
                $hasHardFailures = true;
            } elseif ($c['status'] === 'action_required') {
                $hasActionRequired = true;
            }
        }

        $verdict = 'READY';
        if ($hasHardFailures) {
            $verdict = 'BLOCKED';
        } elseif ($hasActionRequired) {
            $verdict = 'ACTION_REQUIRED';
        }

        return [
            'package' => $package,
            'path' => $relPackagePath,
            'verdict' => $verdict,
            'latest_tag' => $latestTag,
            'checks' => $checks,
        ];
    }

    private function extractCertifiedCommit(string $content): ?string
    {
        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/commit/i', $line)) {
                if (preg_match('/\b([a-f0-9]{7,40})\b/i', $line, $matches)) {
                    return strtolower($matches[1]);
                }
            }
        }

        return null;
    }
}
