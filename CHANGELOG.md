# Changelog

All notable changes to `alex-kassel/dev-kit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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