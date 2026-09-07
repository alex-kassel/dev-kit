# Changelog

All notable changes to `alex-kassel/dev-kit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Advisory concurrency locking (`AlexKassel\DevKit\FileLock`) using non-blocking `flock(LOCK_EX | LOCK_NB)` with exponential backoff, wrapping `composer.json` and manifest mutations in `PackageSynchronizer` and `WorkspaceInstaller`.
- Safe package removal command (`php artisan pkg:remove`) and `PackageRemover` service with Git working tree cleanliness and unpushed commits guards.
- Tooling test runner shortcut `"test:tooling"` in root `composer.json` and `WorkspaceInstaller`.
- Automatic remote default branch autodetection (remote `HEAD`) in `PackageCloner` when `--branch` is omitted.
- Seamless SemVer dependency constraint localization (`^1.0`, `~2.0`) in `PackageLocalizer` without solver duplication.
- Automatic streaming `composer update` on recursive package cloning with `--no-update` opt-out in `pkg:clone`.

### Changed
- Updated `ROADMAP.md` tracking completed milestones and empirical findings regarding standalone require checking.
- Updated `README.md` documenting `pkg:remove`, concurrency protection, and `composer test:tooling`.

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