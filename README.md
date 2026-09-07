<h1 align="center">🛠️ DevKit</h1>

<p align="center">
  <strong>Comprehensive development workspace automation and package engineering toolkit for modular Laravel monorepos</strong>
</p>

<p align="center">
  <a href="#key-features">Key Features</a> •
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a> •
  <a href="#usage">Usage</a> •
  <a href="#testing">Testing</a> •
  <a href="CHANGELOG.md">Changelog</a>
</p>

<p align="center">
  <a href="RELEASE-GATE.md"><img src="https://img.shields.io/badge/Audit-Verified-10b981?logo=shield" alt="Audit Verified"></a>
  <a href="https://packagist.org/packages/alex-kassel/dev-kit"><img src="https://img.shields.io/packagist/v/alex-kassel/dev-kit?color=f59e0b&logo=packagist&logoColor=white" alt="Latest Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?logo=laravel&logoColor=white" alt="Laravel Support"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.3+-777bb4?logo=php&logoColor=white" alt="PHP Support"></a>
  <a href="phpstan.neon"><img src="https://img.shields.io/badge/PHPStan-Level%208-8b5cf6?logo=php&logoColor=white" alt="PHPStan Level 8"></a>
</p>

---

## Key Features

* **Deterministic Workspace Setup (pkg:install):** Prepares host path repositories, gitignore rules, Artisan command shortcuts, and synchronizes agent instructions with optional --force overwrite.
* **Smart Package Generator (pkg:make):** Scaffolds enterprise packages with archetype presets (library, engine, domain), strict typing, and full test suites.
* **Remote Git Ingestion (pkg:clone):** Clones standalone packages from remote Git repositories with automatic default branch detection, recursive SemVer dependency localization, and streaming `composer update`.
* **Safe Package Removal (pkg:remove):** Safely removes local packages with uncommitted/unpushed Git hygiene guards, removes the root dependency declaration; Composer installation state is updated separately.
* **Concurrency File Locking:** Advisory `flock` protection with bounded randomized retry on all workspace manifest operations, coordinating cooperating DevKit commands; atomic file replacement protects individual writes.
* **Comprehensive Quality Gate (pkg:check):** Executes 4-stage quality verification (Composer validation, Pint style fixing, PHPStan Level 8, PHPUnit tests).
* **Package Inventory (pkg:list):** Scans the workspace, reports versioning and path repository registration status.
* **Automated Monorepo Sync (pkg:sync):** Detects local packages and synchronizes them with root dependencies and path repositories.
* **Standard README Validator (pkg:readme):** Validates enterprise documentation against the standard 7-badge palette and canonical section schema.
* **Release Gate Pre-flight (pkg:release-check):** Verifies working tree cleanliness, independent Git history, audit certificates, and export-ignore directives before release.

---

## Requirements

* **PHP:** 8.3+ (tested on 8.3, 8.4)
* **Laravel Framework:** 11.x | 12.x | 13.x
* **Composer:** 2.2+ (with runtime API 2.1+)

---

## Installation

Require `alex-kassel/dev-kit` as a development dependency in your Laravel project:

```bash
composer require alex-kassel/dev-kit --dev
```

Initialize your workspace and install AI agent tooling:

```bash
php artisan pkg:install
```

To set up a fresh environment and immediately localize `dev-kit` for package development:

```bash
php artisan pkg:install --local
```

---

## Usage

### Workspace Preparation & Package Discovery

```bash
# Initialize workspace path repositories and agent skills
php artisan pkg:install

# Initialize workspace and clone dev-kit into packages/
php artisan pkg:install --local

# List all local packages and their linking state
php artisan pkg:list

# Synchronize local packages into composer.json
php artisan pkg:sync
```

### Ingesting & Localizing Packages (pkg:clone)

```bash
# Clone a package repository (automatically detects remote default branch HEAD)
php artisan pkg:clone alex-kassel/my-package

# Clone a specific branch explicitly
php artisan pkg:clone alex-kassel/my-package --branch=develop

# Support for unqualified package name (defaults to configured organization)
php artisan pkg:clone dev-kit

# Recursively clone and localize owned dependencies across multiple organizations
# (Automatically runs streaming composer update upon completion; bypass with --no-update)
php artisan pkg:clone acme/billing --recursive --org=acme,partner-org
```

### Safe Package Removal (pkg:remove)

```bash
# Safely remove a package (checks for uncommitted and unpushed Git changes)
php artisan pkg:remove alex-kassel/my-package

# Force removal of modified or unpushed work
php artisan pkg:remove alex-kassel/my-package --force
```

### Scaffolding & Quality Assurance

```bash
# Scaffold a new library or engine package interactively
php artisan pkg:make my-vendor/my-package --archetype=engine

# Run the complete quality verification suite
php artisan pkg:check alex-kassel/dev-kit

# Automatically fix code style violations
php artisan pkg:check alex-kassel/dev-kit --fix
```

### Documentation & Release Pre-flight

```bash
# Validate README structure against canonical rules
php artisan pkg:readme alex-kassel/dev-kit

# Run pre-flight checks before tagging a release
php artisan pkg:release-check alex-kassel/dev-kit
```

---

## AI Agent Integration

`dev-kit` automatically equips host repositories with standardized agent runbooks and modular skills:

* `AGENTS.md`: Repository guidelines, core architectural invariants, and command indexes.
* `.agents/skills/`: Procedural knowledge modules for autonomous agents (Antigravity, Cursor, Copilot):
  - `package-scaffolding`: Deterministic package initialization.
  - `package-verification`: Linting, PHPStan Level 8 analysis, and test suites.
  - `package-readme`: Enterprise documentation standards.
  - `package-audit`: 2-phase verification audits.
  - `package-release`: Packagist release pipeline and SemVer tagging.

---

## Configuration

Publish the configuration file to customize Git patterns and trusted organizations:

```bash
php artisan vendor:publish --tag=dev-kit-config
```

Options in `config/dev-kit.php`:
* `organizations`: List of trusted vendor organizations for recursive localization.
* `default_source_pattern`: Default Git URL template (defaults to `git@github.com:{vendor}/{package}.git` with automatic HTTPS fallback).
* `sources`: Explicit repository URL overrides for specific packages.

> [!TIP]
> **Zero-Friction Ingestion**: `pkg:clone` automatically falls back to HTTPS if SSH authentication (missing key or host verification) fails. When localizing packages previously installed with fixed SemVer constraints (e.g. `^0.0.2`), `pkg:sync` automatically unbinds the constraint to `@dev` across `require` and `require-dev` to prevent Composer solver conflicts.

---

## Verification and Removal Guarantees

`pkg:check` runs Composer validation, Pint, PHPStan and tests locally. A selected check that is unavailable or unconfigured produces `incomplete`, not `passed`, and a failing exit code. Unknown or empty `--only` selections are rejected. PHPUnit configurations may be named `phpunit.xml` or `phpunit.xml.dist`; an empty suite fails.

`pkg:check vendor/package --isolated` additionally exports the current Git checkout (tracked and non-ignored files, including tests and uncommitted changes), installs dependencies into a fresh temporary `vendor/`, and executes that package's declared PHPUnit or Pest runner. It excludes the host autoloader, global Composer configuration, package lock file, `.env`, Git metadata and existing dependency directories. Path repositories are rejected. Network access and independently resolvable package dependencies are required. Each installation/test process has a ten-minute timeout. Temporary files are removed afterward, including after failure.

This is an installation and executable-test check, not a static proof that every referenced symbol is declared directly or that untested paths are correct. The former regex-based `PhantomDependencyDetector` API was removed; `IsolatedPackageVerifier` provides the actual standalone check. `pkg:release-check` requires all five checks to pass. The standalone copy retains tests marked `export-ignore`; it is not a validation of the distribution archive's exact contents.

`pkg:remove vendor/package --yes` confirms deletion while retaining Git checks. `--json` changes output format only and does not authorize deletion. Without `--force`, removal refuses non-Git directories, Git errors, missing upstreams, detached HEAD, dirty or ignored files, stashes and local commits not covered by remote tracking refs. These refs reflect the last fetch; removal does not contact remotes. `--force` bypasses Git preservation checks, never path validation. Linked package directories and traversal arguments are rejected. A manifest failure stops before deletion; a deletion failure restores the original manifest and reports that files may have been partially removed.

`--unlink` only removes the dependency declaration, retaining files. Neither unlink nor removal runs Composer or changes `composer.lock`/`vendor`; reconcile the host installation with Composer afterward. A later `pkg:sync` can register a retained directory again.

Localization validates versions associated with the current checkout: an explicit manifest version, valid tags pointing at a clean HEAD, and the current branch/its numeric Composer branch alias. Historical tags never certify another checkout. Unknown or incompatible versions stop localization with an actionable message; stability flags such as `@dev` do not bypass numeric constraints. Composer remains responsible for final dependency resolution.

The network-backed consumer regression suite is separate from the fast local suite:

```bash
php vendor/bin/phpunit -c packages/alex-kassel/dev-kit/phpunit-standalone.xml
```

It proves both successful standalone installation with declared dependencies and failure when a grouped import uses an undeclared Laravel component. CI runs it on the PHP 8.4 / Laravel 13 stable jobs for all three operating systems.

---

## Testing

Run the dev-kit test suite via standard Composer tooling shortcut:

```bash
composer test:tooling
```

Or run directly via PHPUnit or universal verification:

```bash
vendor/bin/phpunit -c packages/alex-kassel/dev-kit/phpunit.xml
php artisan pkg:check alex-kassel/dev-kit
```

---

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for details on changes in recent releases.

---

## Security Vulnerabilities

Please review [Security Policies](https://github.com/alex-kassel/dev-kit/security/policy) on how to report vulnerabilities.

---

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for license details.
