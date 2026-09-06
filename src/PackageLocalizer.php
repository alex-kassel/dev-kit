<?php

namespace AlexKassel\DevKit;

use RuntimeException;

class PackageLocalizer
{
    public function __construct(private PackageCloner $cloner, private SourceLocator $sources) {}

    public function localize(string $root, string $package, string $branch, mixed $sources, mixed $organizations, ?string $defaultPattern = null): array
    {
        if ($branch === '') {
            throw new RuntimeException('An explicit root --branch is required.');
        }
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
        $visit = function (string $name, string $requiredBranch, string $from) use (&$visit, &$visited, &$edges, $root, $sources, $organizations, $defaultPattern): void {
            if (isset($visited[$name])) {
                if ($visited[$name]['branch'] !== $requiredBranch) {
                    throw new RuntimeException($from.' requires '.$name.' dev-'.$requiredBranch.' but local branch is '.$visited[$name]['branch'].'. Checkout was not switched.');
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
            if ($checkout['branch'] !== $requiredBranch) {
                throw new RuntimeException($from.' requires '.$name.' dev-'.$requiredBranch.' but local branch is '.$checkout['branch'].'. Checkout was not switched.');
            }
            $visited[$name] = [
                'name' => $name,
                'path' => $checkout['path'],
                'branch' => $checkout['branch'],
                'commit' => $checkout['commit'],
                'action' => $action,
            ];
            foreach ($checkout['require'] as $dependency => $constraint) {
                $owned = str_contains($dependency, '/') && in_array(explode('/', $dependency, 2)[0], $organizations, true);
                $edges[] = [
                    'from' => $name,
                    'name' => $dependency,
                    'constraint' => $constraint,
                    'localize' => $owned,
                ];
                if (! $owned) {
                    continue;
                }
                $branches = [];
                foreach (preg_split('/\s*\|\|\s*/', $constraint) as $alternative) {
                    if (preg_match('~^dev-([a-zA-Z0-9][a-zA-Z0-9._/-]*)$~D', trim($alternative), $matches)) {
                        $branches[] = $matches[1];
                    }
                }
                $branches = array_values(array_unique($branches));
                if (count($branches) !== 1) {
                    throw new RuntimeException('Explicit ref selection is required for '.$dependency.' '.$constraint.' required by '.$name.'. Expected one unambiguous dev-* alternative.');
                }
                $visit($dependency, $branches[0], $name);
            }
        };
        try {
            $visit($package, $branch, 'workspace');
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
}
