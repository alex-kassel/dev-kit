<?php

namespace AlexKassel\DevKit;

use RuntimeException;
use Symfony\Component\Process\Process;

class ReleaseChecker
{
    public function __construct(
        private readonly PackageVerifier $verifier = new PackageVerifier,
        private readonly ReadmeValidator $readmeValidator = new ReadmeValidator
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

        $packagePath = $this->resolvePackagePath($root, $package);
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

            $statusProcess = new Process(['git', 'status', '--porcelain'], $packagePath);
            $statusProcess->run();
            $statusOutput = trim($statusProcess->getOutput());

            if ($statusProcess->getExitCode() === 0 && empty($statusOutput)) {
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
            $tagProcess = new Process(['git', 'tag', '-l', '--sort=-v:refname'], $packagePath);
            $tagProcess->run();
            $tags = array_values(array_filter(explode("\n", trim($tagProcess->getOutput()))));
            $latestTag = ! empty($tags) ? trim($tags[0]) : 'none (initial release)';
        }

        // 2. Audit Certificate Freshness Gate
        $releaseGateFile = $packagePath.DIRECTORY_SEPARATOR.'RELEASE-GATE.md';
        if (file_exists($releaseGateFile)) {
            $gateContent = (string) file_get_contents($releaseGateFile);
            preg_match('/commit[:\s`]+([a-f0-9]{7,40})/i', $gateContent, $matches);
            $certifiedCommit = $matches[1] ?? null;

            if ($certifiedCommit !== null && is_dir($gitDir)) {
                $deltaProcess = new Process(
                    ['git', 'rev-list', '--count', "{$certifiedCommit}..HEAD", '--', 'src/', 'config/', 'database/', 'composer.json'],
                    $packagePath
                );
                $deltaProcess->run();
                $deltaCount = (int) trim($deltaProcess->getOutput());

                if ($deltaCount === 0) {
                    $checks['audit_freshness'] = [
                        'name' => 'Audit Freshness Gate',
                        'status' => 'passed',
                        'message' => "Audit certificate is 100% fresh (0 source commits since certified {$certifiedCommit}).",
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
                    'status' => 'passed',
                    'message' => 'RELEASE-GATE.md present.',
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
        if (file_exists($gitattrPath)) {
            $attrContent = (string) file_get_contents($gitattrPath);
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

        // 4. Code Quality Suite (PackageVerifier)
        $verifyResult = $this->verifier->verify($root, $package);
        $checks['code_quality'] = [
            'name' => 'Code Quality Suite (Pint, PHPStan, Tests, Composer)',
            'status' => $verifyResult['status'] === 'passed' ? 'passed' : 'failed',
            'message' => $verifyResult['status'] === 'passed' ? 'All quality and test checks passed.' : 'Quality checks failed. Run php artisan pkg:check for details.',
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

    private function resolvePackagePath(string $root, string $package): string
    {
        $rawPackage = trim($package, '/\\ ');
        $candidates = [
            $root.DIRECTORY_SEPARATOR.$rawPackage,
            $root.DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.$rawPackage,
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        throw new RuntimeException("Package directory not found for '{$package}'. Checked: packages/{$rawPackage}");
    }
}
