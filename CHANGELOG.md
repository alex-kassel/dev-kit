# Changelog

All notable changes to `alex-kassel/dev-kit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.6] - 2026-09-09

### Added
- Automated modular workspace `README.md` scaffolding in `WorkspaceInstaller` (`pkg:install`), replacing default Laravel skeleton README files with a tailored developer and AI agent guide.
- Codified explicit Performance & Rapid Verification principles across `AGENTS.md` and skills (`package-release`, `package-scaffolding`, `package-verification`).

### Added
- Added `--fast` option to `pkg:release-check` (`ReleaseCheckPackageCommand` and `ReleaseChecker`) to enable rapid local pre-flight checks by skipping the isolated sandbox when testing minor patches.
- Added `--update` option to `pkg:make` (`MakePackageCommand`) to automatically run `composer update` on the host after registering a newly scaffolded package.
- Automatic initial Git commit (`feat: scaffold initial <package> package`) in `PackageScaffolder` when `--git` is passed.
- Declared testing dependencies (`orchestra/testbench` and `phpunit/phpunit`) in `require-dev` of scaffolded packages to guarantee isolated test runner availability.

### Performance
- Enabled Composer cache directory reuse (`COMPOSER_CACHE_DIR`) in `IsolatedPackageVerifier`, preventing repeated downloads of 80+ packages from the network and dramatically accelerating standalone package verification.

## [0.2.4] - 2026-09-09

### Fixed
- Replaced unsupported Termwind class `bg-red-950` with `bg-red-900` in `CheckPackageCommand` to prevent `ColorNotFound` exception when check output is displayed.
- Fixed string concatenation formatting in `stubs/bootstrap.php.stub` to ensure newly scaffolded packages pass Pint `concat_space` verification out of the box.
- Removed non-existent `tests/Feature` suite from `stubs/phpunit.xml.stub` to prevent PHPUnit 11/12 fatal directory errors on freshly scaffolded packages.

## [0.2.3] - 2026-09-08

### Added
- Complete Laravel Boost integration: automatic manifest generation and MCP configuration with skills installation during `pkg:install`.

### Fixed
- Restored certified `RELEASE-GATE.md` audit snapshot (`0.2.0` / `d5a5f6e`) upholding the audit certificate immutability invariant.
- Codified explicit clickable Markdown reporting protocol (GitHub repository, commit, tag, and Packagist URLs) across repository guidelines and release skills.

## [0.2.1] - 2026-09-08

### Fixed
- Streamlined `pkg:install` CLI output to display prepared files as a clean bulleted list instead of a comma-separated string.
- Preserved dev dependencies in `require-dev` during `pkg:sync` without migrating them to `require`.
- Resolved PHPStan Level 8 type analysis warning in `PackageCloner`.

### Changed
- Configured Laravel Boost integration (`boost.json`) with `"agents": ["antigravity"]` and `"guidelines": false` to route Boost skills into `.agents/skills` and keep `AGENTS.md` lean.
- Streamlined `resources/agents/AGENTS.md` guidelines template to clean, professional engineering rules.

## [0.2.0] - 2026-09-08

### Added
- Native `laravel/boost` integration (`^2.0`): automatic dependency ingestion and invocation of `boost:install` during `pkg:install`.
- Seamless AI guidelines composition: `<dev-kit-guidelines>` and `<laravel-boost-guidelines>` co-exist and merge safely in `AGENTS.md`.
- Customizable package scaffolding stubs: extracted 11 archetype templates into `stubs/*.stub` with `vendor:publish --tag=dev-kit-stubs` override support.
- Modernized CLI output powered by Termwind (`nunomaduro/termwind` `^2.0`) across all console commands (`pkg:check`, `pkg:list`, `pkg:make`, `pkg:readme`, `pkg:release-check`).

### Changed
- Replaced Symfony Process execution with Laravel's `Illuminate\Support\Facades\Process` and concurrent `Process::pool()`.
- Replaced direct PHP file operations with Laravel's `Illuminate\Support\Facades\File` (`Illuminate\Filesystem\Filesystem`).
- Replaced direct directory management in `FileLock` with `File::ensureDirectoryExists()`.

### Fixed
- Fixed unsupported text sizing utility classes in Termwind console templates.

## [0.1.0] - 2026-09-07

### Fixed
- Validate package names with canonical Composer regex and reject path traversal in `PackageScaffolder`.
- Harden path containment check and delegate clone staging cleanup to `FileIO::removeDirectory` in `PackageCloner`.
- Reject traversal and linked package paths before removal, including with `--force`.
- Stop removal on Git errors, missing upstreams, local unpublished work and invalid manifests; require explicit noninteractive confirmation.
- Use Symfony Filesystem atomic writes and handle read-only Git objects on Windows without shell deletion.
- Report unavailable verification as incomplete, reject invalid selections, fail empty test suites, and require all release checks.
- Validate SemVer against current checkout versions and branch aliases instead of unrelated historical tags.

### Changed
- Replace the regex-based phantom dependency detector with a real optional standalone Composer installation and package test run (`--isolated`). This removes `PhantomDependencyDetector` and changes the first `PackageVerifier` constructor dependency to `IsolatedPackageVerifier`.
- Add Windows and macOS CI jobs and regression tests for preservation of user work, version selection and isolation.
- Update `ROADMAP.md` tracking completed milestones and empirical findings regarding standalone require checking.
- Update `README.md` documenting `pkg:remove`, concurrency protection, testing command, and `composer test:tooling`.
- Add `@internal` annotations to internal utility classes `FileIO`, `FileLock`, and `PackagePathResolver`.

### Added
- Advisory concurrency locking (`AlexKassel\DevKit\FileLock`) using non-blocking `flock(LOCK_EX | LOCK_NB)` with bounded randomized retry, wrapping `composer.json` and manifest mutations in `PackageSynchronizer` and `WorkspaceInstaller`.
- Safe package removal command (`php artisan pkg:remove`) and `PackageRemover` service with Git working tree cleanliness and unpushed commits guards.
- Tooling test runner shortcut `"test:tooling"` in root `composer.json` and `WorkspaceInstaller`.
- Automatic remote default branch autodetection (remote `HEAD`) in `PackageCloner` when `--branch` is omitted.
- Seamless SemVer dependency constraint localization (`^1.0`, `~2.0`) in `PackageLocalizer` without solver duplication.
- Automatic streaming `composer update` on recursive package cloning with `--no-update` opt-out in `pkg:clone`.

## [0.0.4] - 2026-09-07

### Added
- Architectural Proposal 001 (`docs/proposals/001-modular-workspace-vision.md`) capturing full vision and concept brief.
- Prioritized development roadmap (`ROADMAP.md`) tracking upcoming critical, high, and medium milestones.
- Section 5 ("Package Roadmaps & Architectural Proposals") in `AGENTS.md` and template, instructing agents to inspect package roadmaps before planning changes.
- Added `phpstan/phpstan` (`^1.12 || ^2.0`) to core dependencies to guarantee static analysis tool availability in host workspaces.

### Changed
- Promoted development tooling (`orchestra/testbench` and `phpunit/phpunit`) from `require-dev` to `require` to guarantee automatic installation in host environments.

## [0.0.3] - 2026-09-07

### Added
- Smart protocol fallback (SSH -> HTTPS) in `PackageCloner` when SSH authentication or host key verification fails.
- Automatic overwriting of default skeleton placeholders (`<laravel-boost-guidelines>`) in `WorkspaceInstaller` without requiring `--force`.
- Seamless constraint reconciliation in `PackageSynchronizer`: removes localized packages from `require-dev` and converts existing SemVer tags to `@dev` to prevent solver conflicts during path repository linking.

## [0.0.2] - 2026-09-07

### Added
- `pkg:install --local`: single-step host workspace setup and immediate local cloning of `dev-kit`.
- `pkg:clone`: auto-sync with root `composer.json` upon cloning, with optional `--no-sync` and `--update` flags.
- `pkg:clone`: unqualified package name resolution (e.g. `dev-kit` automatically resolves to `alex-kassel/dev-kit`).
- Comprehensive `README.md` documentation for package cloning, local development workflow, and AI agent integration.

### Fixed
- GitHub Actions CI matrix dependency resolution for `prefer-lowest` across Laravel 11/12/13 and PHP 8.3/8.4.

## [0.0.1] - 2026-09-07

### Added
- Initial development release of `alex-kassel/dev-kit` package.
- `OrganizationResolver` service with multi-tiered discovery (contextual, CLI comma-separated, host manifest, local disk, config).
- `WorkspaceInstaller` and `pkg:install` command with `--dry-run` and `--force`.
- `PackageScaffolder` and `pkg:make` command with library, engine, and domain archetypes.
- `PackageCloner` and `pkg:clone` command with `--recursive` and `--org` options.
- `PackageSynchronizer` and `pkg:sync` command.
- `PackageVerifier` and `pkg:check` command (Composer, Pint, PHPStan Level 8, Tests).
- `PackageInventory` and `pkg:list` command.
- `ReadmeValidator` and `pkg:readme` command.
- `ReleaseChecker` and `pkg:release-check` command.
- Bundled agent skills and canonical `AGENTS.md`.
