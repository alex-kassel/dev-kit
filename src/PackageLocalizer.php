<?php

declare(strict_types=1);

namespace AlexKassel\DevKit;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Process;
use RuntimeException;

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
        $packagePath = rtrim($root, '/\\').'/packages/'.$name;
        $versions = $this->detectCandidateVersions($packagePath, (string) ($checkout['branch'] ?? ''));
        foreach ($versions as $version) {
            if (Semver::satisfies($version, $constraint)) {
                return;
            }
        }
        $checkedVersions = $versions === [] ? 'unknown' : implode(', ', $versions);
        throw new RuntimeException("{$from} requires {$name} {$constraint}, but current checkout versions ({$checkedVersions}) do not satisfy the constraint. Select a compatible ref or declare a matching Composer branch alias; Composer remains the final dependency solver.");
    }

    /** @return list<string> */
    private function detectCandidateVersions(string $packagePath, string $branch): array
    {
        $versions = [];
        $manifest = [];
        if (is_file($packagePath.'/composer.json')) {
            $decoded = json_decode(FileIO::read($packagePath.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Invalid package manifest: '.$packagePath);
            }
            $manifest = $decoded;
        }
        if (isset($manifest['version']) && is_string($manifest['version'])) {
            $versions[] = $manifest['version'];
        } elseif (file_exists($packagePath.'/.git')) {
            $statusResult = Process::path($packagePath)->run(['git', 'status', '--porcelain']);
            if (! $statusResult->successful()) {
                throw new RuntimeException('Git status failed: '.$statusResult->errorOutput());
            }
            // A tag only describes HEAD, never dirty working-tree content or another commit.
            if (trim($statusResult->output()) === '') {
                $tagsResult = Process::path($packagePath)->run(['git', 'tag', '--points-at', 'HEAD']);
                if (! $tagsResult->successful()) {
                    throw new RuntimeException('Git tag failed: '.$tagsResult->errorOutput());
                }
                foreach (preg_split('/\r?\n/', trim($tagsResult->output())) ?: [] as $tag) {
                    if ($tag !== '') {
                        try {
                            (new VersionParser)->normalize($tag);
                            $versions[] = $tag;
                        } catch (\UnexpectedValueException) {
                            // Git permits descriptive tags that are not Composer versions.
                        }
                    }
                }
            }
        }
        if ($branch !== '') {
            $parser = new VersionParser;
            $branchVersion = $parser->normalizeBranch($branch);
            $versions[] = $branchVersion;
            $aliasSource = str_starts_with($branchVersion, 'dev-') ? $branchVersion : $branch.'-dev';
            $alias = $manifest['extra']['branch-alias'][$aliasSource] ?? null;
            if (is_string($alias)) {
                if (! str_ends_with($alias, '-dev')) {
                    throw new RuntimeException('Composer branch aliases must end in -dev: '.$alias);
                }
                $normalized = $parser->normalizeBranch(substr($alias, 0, -4));
                if (! str_ends_with($normalized, '-dev') || str_starts_with($normalized, 'dev-')) {
                    throw new RuntimeException('Composer branch alias must describe a numeric development version: '.$alias);
                }
                $sourcePrefix = $parser->parseNumericAliasPrefix($aliasSource);
                $targetPrefix = $parser->parseNumericAliasPrefix($alias);
                if ($sourcePrefix !== false && ($targetPrefix === false || ! str_starts_with($targetPrefix, $sourcePrefix))) {
                    throw new RuntimeException('Numeric branch aliases must stay within the source version line: '.$aliasSource.' => '.$alias);
                }
                $versions[] = $normalized;
            }
        }

        return array_values(array_unique($versions));
    }
}
