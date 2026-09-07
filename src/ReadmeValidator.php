<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Illuminate\Support\Facades\File;
use RuntimeException;

class ReadmeValidator
{
    public function __construct(
        private readonly PackagePathResolver $pathResolver = new PackagePathResolver
    ) {}

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

        $packagePath = $this->pathResolver->resolve($root, $package);
        $relPackagePath = str_replace([$root.DIRECTORY_SEPARATOR, $root.'/'], '', $packagePath);
        $relPackagePath = str_replace('\\', '/', $relPackagePath);

        $readmePath = $packagePath.DIRECTORY_SEPARATOR.'README.md';
        $releaseGatePath = $packagePath.DIRECTORY_SEPARATOR.'RELEASE-GATE.md';
        $hasReleaseGate = File::exists($releaseGatePath);

        $checks = [];

        // 1. File existence
        if (! File::exists($readmePath)) {
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
            $content = File::get($readmePath);

            // 2. Hero Title Check (Centered HTML or standard Markdown #)
            $hasCenteredHero = preg_match('/<h1\s+align=["\']center["\']>/i', $content) === 1;
            $hasMarkdownHero = preg_match('/^#\s+[^\r\n]+/m', $content) === 1;
            if ($hasCenteredHero || $hasMarkdownHero) {
                $checks['hero_header'] = [
                    'name' => 'Hero Header (Centered HTML or Markdown #)',
                    'status' => 'passed',
                    'message' => $hasCenteredHero ? 'Hero header uses cross-platform HTML container.' : 'Hero header uses standard Markdown # heading.',
                ];
            } else {
                $checks['hero_header'] = [
                    'name' => 'Hero Header (Centered HTML or Markdown #)',
                    'status' => 'failed',
                    'message' => 'Missing hero header (requires <h1 align="center"> or Markdown # Heading).',
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

            // 4. Badges formatting & Double Pipe Rule (excluding code blocks & inline code)
            $contentWithoutCode = preg_replace('/```[\s\S]*?```/', '', $content) ?? $content;
            $contentWithoutCode = preg_replace('/`[^`\r\n]*`/', '', $contentWithoutCode) ?? $contentWithoutCode;
            if (str_contains($contentWithoutCode, '||')) {
                $checks['badge_syntax'] = [
                    'name' => 'Badge Syntax & Formatting',
                    'status' => 'failed',
                    'message' => 'Found raw double pipes "||" in text/badges outside code blocks. Use single pipe "|" or comma list.',
                ];
            } else {
                $checks['badge_syntax'] = [
                    'name' => 'Badge Syntax & Formatting',
                    'status' => 'passed',
                    'message' => 'Clean badge and prose syntax (no double pipes outside code blocks).',
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

            // 6. Canonical Badge Palette Check
            $standardBadges = [
                'Version' => '/packagist(?:\.org\/packages|\/v\/)/i',
                'Laravel' => '/badge\/Laravel/i',
                'PHP' => '/badge\/PHP/i',
                'PHPStan' => '/badge\/PHPStan/i',
            ];

            $missingBadges = [];
            foreach ($standardBadges as $badgeName => $pattern) {
                if (! preg_match($pattern, $content)) {
                    $missingBadges[] = $badgeName;
                }
            }

            if (! empty($missingBadges)) {
                $checks['standard_badges'] = [
                    'name' => 'Standard Badge Palette',
                    'status' => 'failed',
                    'message' => 'Missing canonical badge(s): '.implode(', ', $missingBadges).'. Required: Version, Laravel, PHP, PHPStan.',
                ];
            } else {
                $checks['standard_badges'] = [
                    'name' => 'Standard Badge Palette',
                    'status' => 'passed',
                    'message' => 'All standard palette badges present (Version, Laravel, PHP, PHPStan).',
                ];
            }

            // 7. Required Canonical Sections Check
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
}
