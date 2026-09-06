<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use RuntimeException;

class ReadmeValidator
{
    /**
     * @return array{
     *     package: string,
     *     path: string,
     *     status: string,
     *     summary: array{passed: int, failed: int},
     *     checks: array<string, array{name: string, status: string, message: string}>
     * }
     */
    public function validate(string $root, string $package): array
    {
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('Host directory does not exist.');
        }

        $packagePath = $this->resolvePackagePath($root, $package);
        $relPackagePath = str_replace([$root.DIRECTORY_SEPARATOR, $root.'/'], '', $packagePath);
        $relPackagePath = str_replace('\\', '/', $relPackagePath);

        $readmePath = $packagePath.DIRECTORY_SEPARATOR.'README.md';
        $releaseGatePath = $packagePath.DIRECTORY_SEPARATOR.'RELEASE-GATE.md';
        $hasReleaseGate = file_exists($releaseGatePath);

        $checks = [];

        // 1. File existence
        if (! file_exists($readmePath)) {
            $checks['file_exists'] = [
                'name' => 'README.md File Existence',
                'status' => 'failed',
                'message' => 'README.md not found in package directory.',
            ];
        } else {
            $checks['file_exists'] = [
                'name' => 'README.md File Existence',
                'status' => 'passed',
                'message' => 'README.md found.',
            ];
        }

        if ($checks['file_exists']['status'] === 'passed') {
            $content = (string) file_get_contents($readmePath);

            // 2. Centered Hero Title Check
            if (preg_match('/<h1\s+align=["\']center["\']>/i', $content)) {
                $checks['hero_header'] = [
                    'name' => 'Centered Hero Header (h1 align="center")',
                    'status' => 'passed',
                    'message' => 'Hero header uses cross-platform HTML container.',
                ];
            } else {
                $checks['hero_header'] = [
                    'name' => 'Centered Hero Header (h1 align="center")',
                    'status' => 'failed',
                    'message' => 'Missing <h1 align="center"> tag for cross-platform alignment.',
                ];
            }

            // 3. Quick Navigation Links Check
            if (preg_match('/<p\s+align=["\']center["\']>.*?<a\s+href=.*?<\/p>/is', $content)) {
                $checks['quick_links'] = [
                    'name' => 'Quick Navigation Links',
                    'status' => 'passed',
                    'message' => 'Centered quick navigation links present.',
                ];
            } else {
                $checks['quick_links'] = [
                    'name' => 'Quick Navigation Links',
                    'status' => 'failed',
                    'message' => 'Missing centered quick navigation links block.',
                ];
            }

            // 4. Badges formatting & Double Pipe Rule
            if (str_contains($content, '||')) {
                $checks['badge_syntax'] = [
                    'name' => 'Badge Syntax & Formatting',
                    'status' => 'failed',
                    'message' => 'Found raw double pipes "||" in text/badges. Use single pipe "|" or comma list.',
                ];
            } else {
                $checks['badge_syntax'] = [
                    'name' => 'Badge Syntax & Formatting',
                    'status' => 'passed',
                    'message' => 'Clean badge and prose syntax (no double pipes).',
                ];
            }

            // 5. Audit Badge Integrity
            $hasAuditBadge = preg_match('/Audit-Verified-10b981/i', $content) === 1 || preg_match('/Audit\s*Verified/i', $content) === 1;
            if ($hasReleaseGate && ! $hasAuditBadge) {
                $checks['audit_badge'] = [
                    'name' => 'Audit Badge Integrity',
                    'status' => 'failed',
                    'message' => 'RELEASE-GATE.md exists, but mandatory first badge "Audit Verified" is missing in README.',
                ];
            } elseif (! $hasReleaseGate && $hasAuditBadge) {
                $checks['audit_badge'] = [
                    'name' => 'Audit Badge Integrity',
                    'status' => 'failed',
                    'message' => 'Audit Verified badge found, but no certified RELEASE-GATE.md exists in package.',
                ];
            } else {
                $checks['audit_badge'] = [
                    'name' => 'Audit Badge Integrity',
                    'status' => 'passed',
                    'message' => $hasReleaseGate ? 'Audit Verified badge correctly reflects RELEASE-GATE.md certification.' : 'No premature audit badge found.',
                ];
            }

            // 6. Required Canonical Sections Check
            $requiredSections = [
                'Requirements' => '/##\s+Requirements/i',
                'Installation' => '/##\s+Installation/i',
                'Usage' => '/##\s+Usage/i',
                'Testing' => '/##\s+Testing/i',
                'License' => '/##\s+License/i',
            ];

            $missingSections = [];
            foreach ($requiredSections as $secName => $pattern) {
                if (! preg_match($pattern, $content)) {
                    $missingSections[] = $secName;
                }
            }

            if (! empty($missingSections)) {
                $checks['canonical_sections'] = [
                    'name' => 'Canonical Sections Sequence',
                    'status' => 'failed',
                    'message' => 'Missing required canonical section(s): '.implode(', ', $missingSections),
                ];
            } else {
                $checks['canonical_sections'] = [
                    'name' => 'Canonical Sections Sequence',
                    'status' => 'passed',
                    'message' => 'All required standard sections present.',
                ];
            }

            // 7. Unfilled Placeholders Check
            if (preg_match('/<vendor>\/<package>/i', $content) === 1 || preg_match('/Vendor\\\\Package\\\\/i', $content) === 1) {
                $checks['placeholders'] = [
                    'name' => 'Unfilled Template Placeholders',
                    'status' => 'failed',
                    'message' => 'Found generic placeholders (<vendor>/<package> or Vendor\Package) that need concrete names.',
                ];
            } else {
                $checks['placeholders'] = [
                    'name' => 'Unfilled Template Placeholders',
                    'status' => 'passed',
                    'message' => 'No leftover generic placeholders detected.',
                ];
            }
        }

        $passedCount = 0;
        $failedCount = 0;
        foreach ($checks as $check) {
            if ($check['status'] === 'passed') {
                $passedCount++;
            } else {
                $failedCount++;
            }
        }

        $overallStatus = $failedCount === 0 ? 'passed' : 'failed';

        return [
            'package' => $package,
            'path' => $relPackagePath,
            'status' => $overallStatus,
            'summary' => [
                'passed' => $passedCount,
                'failed' => $failedCount,
            ],
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
