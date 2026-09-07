# Recursive Localization & Workspace Verification Audit Report

- **Date**: 2026-09-07
- **Target Package**: `alex-kassel/car-subscription`
- **Host Runtime**: Laravel 13.30.1, PHP 8.4.24 (Windows Herd NTS x64)
- **DevKit Version**: `alex-kassel/dev-kit` v0.0.4

---

## 1. Executive Summary

During testing of the multi-package development environment using `alex-kassel/dev-kit`, we performed a clean installation of the host skeleton, initialized local development mode, and recursively localized the domain package `alex-kassel/car-subscription` along with its full dependency graph.

The command executed:
```bash
php artisan pkg:clone alex-kassel/car-subscription --branch=main --recursive
```

The recursive resolver cloned 7 owned packages into `packages/`:
1. `alex-kassel/car-subscription`
2. `alex-kassel/scraper-core`
3. `alex-kassel/laravel-actionable-diagnostics`
4. `alex-kassel/laravel-domain-core`
5. `alex-kassel/stable-fingerprint`
6. `alex-kassel/roach-php-laravel`
7. `alex-kassel/roach-php-core`

A subsequent comprehensive matrix check across all 8 packages in the workspace (`php artisan pkg:check --all`) revealed that **4 out of 8 packages failed verification**, exposing critical vulnerabilities across the DevKit tooling, host test environment isolation, and individual package quality.

---

## 2. Workspace Quality Matrix

| Package | Composer Validate | Pint Style | PHPStan L8 | Tests | Overall Status |
|---|---|---|---|---|---|
| `alex-kassel/car-subscription` | PASS | **FAIL** (CRLF / ops) | PASS | PASS | **FAIL** |
| `alex-kassel/dev-kit` | PASS | PASS | PASS | PASS | **PASS** |
| `alex-kassel/laravel-actionable-diagnostics` | PASS | PASS | **FAIL** (`trait.unused`) | PASS | **FAIL** |
| `alex-kassel/laravel-domain-core` | PASS | PASS | PASS | **FAIL** (`cache_locks`) | **FAIL** |
| `alex-kassel/roach-php-core` | PASS | PASS | PASS | PASS | **PASS** |
| `alex-kassel/roach-php-laravel` | PASS | PASS | PASS | PASS | **PASS** |
| `alex-kassel/scraper-core` | PASS | PASS | PASS | **FAIL** (`cache_locks`) | **FAIL** |
| `alex-kassel/stable-fingerprint` | PASS | PASS | PASS | PASS | **PASS** |

---

## 3. Detailed Incident Breakdown & Actionable Remediations

### Category A: DevKit Tooling Defects

#### Issue DK-01: Mandatory `--branch` Option Prevents Default Remote Branch Autodetection
- **Component**: `AlexKassel\DevKit\Console\ClonePackageCommand`, `AlexKassel\DevKit\PackageCloner`, `AlexKassel\DevKit\PackageLocalizer`
- **Symptom**:
  Running `php artisan pkg:clone alex-kassel/car-subscription --recursive` fails immediately:
  ```
  An explicit root --branch is required.
  ```
- **Root Cause**:
  `PackageCloner::clonePackage()` and `PackageLocalizer::localize()` enforce a non-empty string validation on the `$branch` parameter. However, Git natively resolves the default branch (`HEAD`, e.g. `main` or `master`) when no branch flag is passed to `git clone`.
- **Actionable Remediation**:
  1. In `ClonePackageCommand`, make `--branch` optional (defaulting to `null`).
  2. In `PackageCloner::clonePackage()`, when `$branch` is null or empty, omit `--branch <branch>` from the initial `git clone` arguments.
  3. After clone completion, execute `git branch --show-current` in the clone directory to determine the active branch name.
  4. Pass the detected branch forward to `PackageLocalizer`.

```php
// In PackageCloner::clonePackage:
$cloneArgs = ['clone', '--single-branch', '--no-recurse-submodules'];
if ($branch !== null && $branch !== '') {
    $cloneArgs[] = '--branch';
    $cloneArgs[] = $branch;
}
$cloneArgs[] = '--';
$cloneArgs[] = $cloneUrl;
$cloneArgs[] = $staging;

$this->git($cloneArgs, $root);
$actualBranch = trim($this->git(['branch', '--show-current'], $staging));
```

---

#### Issue DK-02: Missing Composer Update / Package Linking on Recursive Localization
- **Component**: `AlexKassel\DevKit\Console\ClonePackageCommand` (Lines 67–95)
- **Symptom**:
  After running `pkg:clone --recursive`, all 7 cloned packages were added to the root `composer.json`, but running `php artisan pkg:list` showed:
  ```
  | alex-kassel/car-subscription | packages/alex-kassel/car-subscription | yes | not installed | - |
  ... (all 7 packages not installed)
  ```
  Third-party dependencies required by these packages (`spatie/laravel-package-tools`, `nyholm/psr7`, `league/container`, etc.) were missing from `vendor/`.
- **Root Cause**:
  In `ClonePackageCommand::handle()`, the `--recursive` branch executes `PackageLocalizer::localize()` and `PackageSynchronizer::sync()`, but completely skips the `--update` flag processing and does not invoke `composer update`.
- **Actionable Remediation**:
  Update `ClonePackageCommand` so that recursive localization automatically runs `composer update` for all newly localized packages or executes a workspace-wide `composer update` when `--update` (or default recursive flow) is engaged:

```php
if ($this->option('recursive')) {
    // ... localization & sync ...
    if ($shouldUpdate || ! $this->option('no-update')) {
        $packageNames = array_column($result['packages'], 'name');
        $process = new Process(['composer', 'update', ...$packageNames, '--no-interaction'], $root);
        $process->setTimeout(600.0);
        $process->run();
    }
}
```

---

#### Issue DK-03: Fragile SemVer Parsing in `PackageLocalizer`
- **Component**: `AlexKassel\DevKit\PackageLocalizer` (Lines 71–83)
- **Symptom**:
  Recursive localization only succeeded because packages contained an explicit fallback `|| dev-main` in their `composer.json` constraints (e.g. `"^2.0 || dev-main"`). Any standard package depending strictly on SemVer (e.g. `"^1.0"` or `"~2.1"`) crashes `PackageLocalizer`:
  ```
  Explicit ref selection is required for <pkg> <constraint>. Expected one unambiguous dev-* alternative.
  ```
- **Root Cause**:
  The regex in `PackageLocalizer` only searches for alternatives matching `dev-*`. It does not support resolving standard semantic versioning constraints.
- **Actionable Remediation**:
  Use `Composer\Semver\Semver`:
  1. When owned packages have SemVer constraints, clone their default branch (`HEAD`).
  2. Inspect the local package version / tags.
  3. Validate compatibility with `Semver::satisfies()`.
  4. Link via root path repository `@dev`.

---

### Category B: Host Environment & Cross-Platform Isolation

#### Issue ENV-01: Host Environment Leakage into Package Tests (`CACHE_STORE=database` vs SQLite `:memory:`)
- **Component**: `AlexKassel\DevKit\PackageVerifier`, Host Laravel `.env`, Package `phpunit.xml`
- **Symptom**:
  Running `pkg:check` on `alex-kassel/laravel-domain-core` and `alex-kassel/scraper-core` failed automated tests with:
  ```
  SQLSTATE[HY000]: General error: 1 no such table: cache_locks (Connection: testing, Database: :memory:, SQL: update "cache_locks" set "owner" = ... where "key" = ...)
  ```
- **Root Cause**:
  `PackageVerifier::checkTests()` invokes `[PHP_BINARY, 'artisan', 'test', '-c', $xmlRel]`.
  Because `artisan` belongs to the host Laravel skeleton, it loads the host environment (`.env`), which defaults to `CACHE_STORE=database`.
  The package `phpunit.xml` sets `<env name="DB_CONNECTION" value="sqlite"/>` and `<env name="DB_DATABASE" value=":memory:"/>`, but does **not** override `CACHE_STORE`. Consequently, Laravel attempts to write locks and cache items into SQLite's memory database where table `cache_locks` does not exist.
- **Actionable Remediation**:
  1. In `PackageScaffolder`, update the template `phpunit.xml` to explicitly set safe, memory-only drivers:
     ```xml
     <php>
         <env name="APP_ENV" value="testing"/>
         <env name="BCRYPT_ROUNDS" value="4"/>
         <env name="CACHE_STORE" value="array"/>
         <env name="DB_CONNECTION" value="sqlite"/>
         <env name="DB_DATABASE" value=":memory:"/>
         <env name="MAIL_MAILER" value="array"/>
         <env name="PULSE_ENABLED" value="false"/>
         <env name="QUEUE_CONNECTION" value="sync"/>
         <env name="SESSION_DRIVER" value="array"/>
         <env name="TELESCOPE_ENABLED" value="false"/>
     </php>
     ```
  2. Add `<env name="CACHE_STORE" value="array"/>` to `packages/alex-kassel/laravel-domain-core/phpunit.xml` and `packages/alex-kassel/scraper-core/phpunit.xml`.

---

#### Issue ENV-02: Windows Line Endings (CRLF) and Missing `.gitattributes`
- **Component**: Package Repository Scaffolding, `laravel/pint`
- **Symptom**:
  `alex-kassel/car-subscription` failed Pint verification with:
  ```json
  {"path":"...","fixers":["line_ending","unary_operator_spaces","not_operator_with_successor_space"]}
  ```
- **Root Cause**:
  Git on Windows checks out repositories with CRLF endings by default if `core.autocrlf` is true and no `.gitattributes` file specifies EOL normalization. Pint expects standard UNIX LF endings.
- **Actionable Remediation**:
  1. Ensure every package contains a `.gitattributes` file in its root with:
     ```gitattributes
     * text=auto eol=lf
     *.php text eol=lf
     ```
  2. In `PackageScaffolder`, verify `.gitattributes` is always generated.
  3. Run `vendor/bin/pint packages/alex-kassel/car-subscription` to repair current violations.

---

### Category C: Target Package Code Issues

#### Issue PKG-01: PHPStan Level 8 `trait.unused` Error on Public Library Traits
- **Component**: `packages/alex-kassel/laravel-actionable-diagnostics/src/Exceptions/HasActionableRemediation.php`
- **Symptom**:
  PHPStan fails with 1 error:
  ```
  Trait AlexKassel\LaravelActionableDiagnostics\Exceptions\HasActionableRemediation is used zero times and is not analysed. (identifier: trait.unused)
  ```
- **Root Cause**:
  In PHPStan 2.x, unreferenced traits trigger the `trait.unused` check unless they are consumed in the codebase or tested. In a standalone utility package, traits are intended for external consumers.
- **Actionable Remediation**:
  Option A (Recommended): Create a dummy consumer class inside `tests/Fixtures/` or use the trait in a test case to allow PHPStan to analyze it.
  Option B: In `packages/alex-kassel/laravel-actionable-diagnostics/phpstan.neon`, ignore the rule:
  ```neon
  parameters:
      ignoreErrors:
          - identifier: trait.unused
  ```

---

## 4. Action Plan for Upcoming Session

1. **Step 1 — DevKit Core Fixes**:
   - Implement optional `--branch` with auto-detected remote `HEAD` in `PackageCloner` (P1.2).
   - Add automatic Composer update execution to `ClonePackageCommand` under `--recursive`.
   - Add concurrency locking (`flock`) on `composer.json` (P2.4).
   - Register `test:tooling` in `WorkspaceInstaller` (P2.3).

2. **Step 2 — Package Test Isolation Fixes**:
   - Add `<env name="CACHE_STORE" value="array"/>` to `laravel-domain-core/phpunit.xml` and `scraper-core/phpunit.xml`.
   - Re-run `php artisan pkg:check alex-kassel/laravel-domain-core` and verify test suite passes.
   - Re-run `php artisan pkg:check alex-kassel/scraper-core` and verify test suite passes.

3. **Step 3 — Package Code Quality Fixes**:
   - Address `trait.unused` in `alex-kassel/laravel-actionable-diagnostics` (add test fixture or PHPStan rule exception).
   - Add `.gitattributes` to `alex-kassel/car-subscription` and run `vendor/bin/pint packages/alex-kassel/car-subscription` to fix formatting and line endings.
   - Re-run `php artisan pkg:check --all` to achieve a 100% passing matrix (8 of 8 packages PASS).
