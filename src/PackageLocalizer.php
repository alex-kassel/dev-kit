<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Composer\Semver\Semver;
use RuntimeException;
use Symfony\Component\Process\Process;

class PackageLocalizer
{
    public function __construct(private PackageCloner $cloner, private SourceLocator $sources) {}

    /**
     * @return array{schema_version: int, status: string, composer_updated: bool, packages: list<array<string, mixed>>, requirements: list<array<string, mixed>>}
     */
    public function localize(string $root, string $package, ?string $branch = null, mixed $sources = [], mixed $organizations = [], ?string $defaultPattern = null): array
    {
        $normalizedBranch = ($branch !== null && trim($branch) !== '') ? trim($branch) : null;
        if (! is_array($organizations) || ! array_is_list($organizations)) {
            throw new RuntimeException('dev-kit.organizations must be a list.');
        }
        foreach ($organizations as $organization) {
            if (! is_string($organization) || ! preg_match('/^[a-z0-9]+(?:[_.-][a-z0-9]+)*$/D', $organization)) {
                throw new RuntimeException('Invalid organization in dev-kit.organizations.');
            }
        }
        $visited = [];
        $edges = [];
        $visit = function (string $name, ?string $requiredBranch, string $from, ?string $constraint = null) use (&$visit, &$visited, &$edges, $root, $sources, $organizations, $defaultPattern): void {
            if (isset($visited[$name])) {
                if ($requiredBranch !== null && $requiredBranch !== '' && $visited[$name]['branch'] !== $requiredBranch) {
                    throw new RuntimeException($from.' requires '.$name.' dev-'.$requiredBranch.' but local branch is '.$visited[$name]['branch'].'. Checkout was not switched.');
                }
                if ($constraint !== null) {
                    $this->assertSemverSatisfied($root, $name, $visited[$name], $constraint, $from);
                }

                return;
            }
            $this->sources->resolve($name, $sources, $defaultPattern);
            $path = rtrim($root, '/\\').'/packages/'.$name;
            if (file_exists($path) || is_link($path)) {
                $checkout = $this->cloner->inspectCheckout($root, $name);
                $action = 'reused';
            } else {
                $checkout = $this->cloner->clonePackage($root, $name, $requiredBranch, $sources, $defaultPattern);
                $action = 'cloned';
            }
            if ($requiredBranch !== null && $requiredBranch !== '' && $checkout['branch'] !== $requiredBranch) {
                throw new RuntimeException($from.' requires '.$name.' dev-'.$requiredBranch.' but local branch is '.$checkout['branch'].'. Checkout was not switched.');
            }
            if ($constraint !== null) {
                $this->assertSemverSatisfied($root, $name, $checkout, $constraint, $from);
            }
            $visited[$name] = [
                'name' => $name,
                'path' => $checkout['path'],
                'branch' => $checkout['branch'],
                'commit' => $checkout['commit'],
                'action' => $action,
            ];
            /** @var array<string, string> $requires */
            $requires = (array) $checkout['require'];
            foreach ($requires as $dependency => $depConstraint) {
                $owned = str_contains($dependency, '/') && in_array(explode('/', $dependency, 2)[0], $organizations, true);
                $edges[] = [
                    'from' => $name,
                    'name' => $dependency,
                    'constraint' => $depConstraint,
                    'localize' => $owned,
                ];
                if (! $owned) {
                    continue;
                }
                $branches = [];
                $alternatives = preg_split('/\s*\|\|\s*/', $depConstraint) ?: [];
                foreach ($alternatives as $alternative) {
                    if (preg_match('~^dev-([a-zA-Z0-9][a-zA-Z0-9._/-]*)$~D', trim($alternative), $matches)) {
                        $branches[] = $matches[1];
                    }
                }
                $branches = array_values(array_unique($branches));
                if (count($branches) > 1) {
                    throw new RuntimeException('Ambiguous ref selection for '.$dependency.' '.$depConstraint.' required by '.$name.'. Expected at most one dev-* alternative.');
                }
                $targetBranch = count($branches) === 1 ? $branches[0] : null;
                $visit($dependency, $targetBranch, $name, $depConstraint);
            }
        };
        try {
            $visit($package, $normalizedBranch, 'workspace');
        } catch (RuntimeException $exception) {
            throw new RuntimeException($exception->getMessage().' Previously localized checkouts are retained; no remote fallback was attempted.', 0, $exception);
        }

        return [
            'schema_version' => 1,
            'status' => 'localized',
            'composer_updated' => false,
            'packages' => array_values($visited),
            'requirements' => $edges,
        ];
    }

    /**
     * @param  array<string, mixed>  $checkout
     */
    private function assertSemverSatisfied(string $root, string $name, array $checkout, string $constraint, string $from): void
    {
        $trimmedConstraint = trim($constraint);
        if ($trimmedConstraint === '*' || str_contains($trimmedConstraint, '@dev')) {
            return;
        }

        $packagePath = rtrim($root, '/\\').'/packages/'.$name;
        $versions = $this->detectCandidateVersions($packagePath, (string) ($checkout['branch'] ?? ''));

        foreach ($versions as $version) {
            try {
                if (Semver::satisfies($version, $trimmedConstraint)) {
                    return;
                }
            } catch (\UnexpectedValueException) {
                // If version string is malformed for Semver, continue
            }
        }

        $hasExplicitVersion = $this->hasExplicitVersion($packagePath);
        if (! $hasExplicitVersion) {
            return;
        }

        $checkedVersions = empty($versions) ? 'none' : implode(', ', $versions);
        throw new RuntimeException("{$from} requires {$name} {$constraint}, but local package versions ({$checkedVersions}) do not satisfy the constraint.");
    }

    /**
     * @return list<string>
     */
    private function detectCandidateVersions(string $packagePath, string $branch): array
    {
        $versions = [];

        $manifestPath = $packagePath.'/composer.json';
        if (file_exists($manifestPath)) {
            $content = @file_get_contents($manifestPath);
            if ($content !== false) {
                /** @var array<string, mixed>|null $json */
                $json = json_decode($content, true);
                if (is_array($json) && isset($json['version']) && is_string($json['version'])) {
                    $versions[] = ltrim($json['version'], 'v');
                }
            }
        }

        if (is_dir($packagePath.'/.git')) {
            $process = new Process(['git', 'tag', '-l', '--sort=-v:refname'], $packagePath);
            $process->run();
            if ($process->getExitCode() === 0) {
                $lines = preg_split('/\r?\n/', trim($process->getOutput())) ?: [];
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed !== '') {
                        $versions[] = ltrim($trimmed, 'v');
                    }
                }
            }
        }

        if ($branch !== '') {
            $versions[] = 'dev-'.$branch;
        }

        return array_values(array_unique($versions));
    }

    private function hasExplicitVersion(string $packagePath): bool
    {
        $manifestPath = $packagePath.'/composer.json';
        if (file_exists($manifestPath)) {
            $content = @file_get_contents($manifestPath);
            if ($content !== false) {
                /** @var array<string, mixed>|null $json */
                $json = json_decode($content, true);
                if (is_array($json) && isset($json['version']) && is_string($json['version']) && trim($json['version']) !== '') {
                    return true;
                }
            }
        }

        if (is_dir($packagePath.'/.git')) {
            $process = new Process(['git', 'tag', '-l'], $packagePath);
            $process->run();
            if ($process->getExitCode() === 0 && trim($process->getOutput()) !== '') {
                return true;
            }
        }

        return false;
    }
}
