# DevKit Roadmap & Engineering Priorities

This document tracks the prioritized development roadmap for `alex-kassel/dev-kit`. It reflects the architectural vision established in [Proposal 001: Modular Laravel Development Environment](docs/proposals/001-modular-workspace-vision.md) and incorporates the empirical findings from the [Recursive Localization Audit (2026-09-07)](docs/reports/2026-09-07-recursive-localization-audit.md).

---

## 🎯 Status Overview

| Priority | ID | Feature / Component | Status | Target Milestone |
|---|---|---|---|---|
| **Critical** | **P1.1** | Package Boundary & Phantom Dependency Detection | **Evaluated (Non-Applicable to Monorepo)** | v0.1.0 |
| **Critical** | **P1.2** | Default Remote Branch Autodetection in `pkg:clone` | **Completed** | v0.1.0 |
| **Critical** | **P1.3** | SemVer Dependency Localization without Solver Duplication | **Completed** | v0.1.0 |
| **High** | **P2.1** | Clean Standalone Test Run (`--isolated` verification) | **Completed** | v0.1.1 |
| **High** | **P2.2** | Safe Package Removal (`php artisan pkg:remove`) | **Completed** | v0.1.1 |
| **High** | **P2.3** | Tooling Command Parity & Registration (`composer test:tooling`) | **Completed** | v0.1.1 |
| **High** | **P2.4** | Atomic Concurrency Locking (`flock` on `composer.json`) | **Completed** | v0.1.1 |
| **High** | **P2.5** | Upstream-Aware Rebase Guard in Git Automation | **Planned** | v0.1.1 |
| **Medium** | **P3.1** | Declarative Workspace Manifest (`workspace.yaml` & `pkg:restore`) | **Deferred to Phase 3** | v0.2.0 |
| **Medium** | **P3.2** | Native Artisan Audit Command (`php artisan pkg:audit`) | **Planned** | v0.2.0 |
| **Medium** | **P3.3** | Automated CI Matrix Scaffolding (GitHub Actions Matrix) | **Planned** | v0.2.0 |
| **Medium** | **P3.4** | Semantic Versioning & Backward Compatibility Gate (`roave/backward-compatibility-check`) | **Planned** | v0.2.0 |
| **Medium** | **P3.5** | Windows Symlink / Junction Diagnostics & Fallback Detection | **Planned** | v0.2.0 |
| **Low** | **P4.1** | Frontend Resources & Vite Path Aliases | **Deferred** | Backlog |

---

## 🚀 Detailed Work Items

### 1. Critical Priority (Reliability & Foundational Ergonomics)

#### P1.1: Package Boundary & Phantom Dependency Detection
- **Rationale**: In a monorepo sharing the host `vendor/`, packages can inadvertently use classes declared only by the host skeleton. Such packages pass local tests and static analysis, but break immediately when installed standalone via Packagist.
- **Evaluation & Empirical Finding**:
  - `maglnet/composer-require-checker` was evaluated. However, it mandates a physical `vendor/` and `installed.json` inside each package's local directory, violating the fundamental monorepo invariant of shared host `vendor/`. Artificial workaround hacks (such as synthetic `vendor-dir` manifests) introduce brittle file coupling.
  - The phantom dependency and standalone verification gate is cleanly addressed at the release stage via **P2.1 (Isolated Package Verification)** using a current-checkout export including tests and clean temporary installation.
- **Outcome**: Concluded that external AST require-checking tool is architecturally incompatible with clean monorepo constraints; superseded by clean standalone export testing in P2.1.

#### P1.2: Default Remote Branch Autodetection in `pkg:clone`
- **Rationale**: Currently, `--branch` is mandatory in `PackageCloner`. If omitted, execution fails. Git natively resolves remote `HEAD` (whether `main`, `master`, or `develop`).
- **Approach**:
  - Make `--branch` optional in `pkg:clone`.
  - When `--branch` is omitted, perform `git clone` using remote `HEAD`, then inspect the active checked out branch name.
- **Outcome**: Seamless cloning ergonomics without guessing remote trunk branch names.

#### P1.3: SemVer Dependency Localization without Composer Duplication
- **Rationale**: Currently, `PackageLocalizer` throws an error if an owned dependency constraint is not `dev-*`. Real packages depend on each other via SemVer constraints (e.g. `^1.0`). We must avoid writing a home-grown SAT solver or duplicating Composer logic.
- **Approach**:
  - When localizing owned dependencies specified with SemVer constraints (`^1.0`, `~2.1`), clone the repository's default branch.
  - Rely on host `composer.json` path repository (`@dev` symlink) for local resolution.
  - Use official `composer/semver` (`Semver::satisfies()`) to verify that the local checked-out package version/tag satisfies the dependency constraint.
- **Outcome**: Recursive localization works with standard SemVer constraints without reinventing Composer.

---

### 2. High Priority (Release Quality & Hygiene)

#### P2.1: Clean Standalone Package Verification
- **Rationale**: Before tagging a release, the package must be verified to install and run its test suite in an isolated environment devoid of host dependencies.
- **Approach**:
  - Add an `--isolated` mode to `pkg:release-check` (or `pkg:check`).
  - Create a temporary staging directory, export the package, execute `composer install --prefer-dist`, and run package tests via Orchestra Testbench.
- **Outcome**: Evidence that the package installs and its declared tests pass without host dependencies; untested behavior remains outside this check.

#### P2.2: Safe Package Removal (`php artisan pkg:remove`)
- **Rationale**: Removing a package currently requires manual directory removal and running `pkg:sync --clean`.
- **Approach**:
  - Implement `php artisan pkg:remove <vendor/package>`.
  - Check `git status` within the package repository to prevent accidental deletion of uncommitted or unpushed work.
  - Remove path from host `composer.json`, unlink symlink, and prompt for confirmation if `--force` is not provided.
- **Outcome**: Safe, bidirectional workspace lifecycle management.

#### P2.3: Tooling Command Parity & Registration (`composer test:tooling`)
- **Rationale**: `AGENTS.md` and monorepo documentation specify `composer test:tooling` as the standard command to run tests for dev-kit tooling, but the script is not registered in root `composer.json` or `WorkspaceInstaller::prepareScripts`.
- **Approach**:
  - Add `test:tooling` script mapping (`@php artisan test packages/alex-kassel/dev-kit`) to `WorkspaceInstaller::prepareScripts`.
  - Ensure root `composer.json` receives this script upon `pkg:install` and `pkg:sync`.
- **Outcome**: Seamless parity between repository guidelines and executable Composer scripts.

#### P2.4: Atomic Concurrency Locking (`flock` on `composer.json`)
- **Rationale**: When multiple agents or concurrent processes execute commands mutating root `composer.json` (such as `pkg:sync`, `pkg:clone`, or `pkg:make`), uncoordinated file writes can cause race conditions and corrupted JSON manifests.
- **Approach**:
  - Introduce advisory file locking using `flock(LOCK_EX)` with bounded randomized retry around all reads and writes to `composer.json` in `PackageSynchronizer` and `WorkspaceInstaller`.
- **Outcome**: Concurrency-safe operations across multi-agent workflows.

#### P2.5: Upstream-Aware Rebase Guard in Git Automation
- **Rationale**: The core invariant requiring `git pull --rebase` before commits fails on newly created feature branches that do not yet have an upstream tracking branch configured (`@{u}`).
- **Approach**:
  - Implement a safety check (`git rev-parse --verify --quiet @{u}`) in verification routines and agent runbooks before executing `git pull --rebase`.
  - Fall back gracefully to local commit validation if no remote tracking branch is established.
- **Outcome**: Eliminates pipeline interruptions when starting work on new feature branches.

---

### 3. Medium Priority (Workspace State & Automation)

#### P3.1: Declarative Workspace Manifest (`workspace.yaml` & `pkg:restore`)
- **Rationale**: To fulfill the "Disposable Host" concept, the workspace should be reproducible from a single declarative manifest on a fresh machine.
- **Decision**: Deferred until package cloning and dependency localization undergo extensive field testing with real packages.
- **Scope**:
  - Export active local package list and branches to `workspace.yaml`.
  - Provide `php artisan pkg:restore` to re-clone and link the entire workspace in one command.

#### P3.2: Native Artisan Audit Command (`php artisan pkg:audit`)
- **Rationale**: Multi-agent audit workflows are currently run via `.agents/skills/package-audit/`. A native artisan command will provide non-agent CLI parity.
- **Scope**: Expose orchestrator report generator via CLI with `--format=json` and `--output=...`.

#### P3.3: Automated CI Matrix Scaffolding (GitHub Actions Matrix)
- **Rationale**: Local development and verification execute exclusively against the host runtime (e.g. PHP 8.4 and Laravel 13). Packages claiming support for PHP ^8.2|^8.3|^8.4 and Laravel 11|12|13 suffer from a local matrix blind spot.
- **Approach**:
  - Update `PackageScaffolder` to automatically generate `.github/workflows/run-tests.yml` for newly scaffolded packages.
  - Configure matrix testing across supported PHP versions, minimum/latest dependencies (`--prefer-lowest`), and active Laravel releases.
- **Outcome**: Deterministic multi-version compatibility validation on CI.

#### P3.4: Semantic Versioning & Backward Compatibility Gate (`roave/backward-compatibility-check`)
- **Rationale**: Currently, `ReleaseChecker` validates code style, test execution, and static analysis, but does not detect unintended breaking changes in public class/method signatures compared to previous release tags.
- **Approach**:
  - Integrate `roave/backward-compatibility-check` into `php artisan pkg:release-check <pkg>`.
  - Compare the current Git HEAD against the latest Git tag, blocking non-major releases if breaking changes are found.
- **Outcome**: Automated prevention of unintended SemVer violations before tagging.

#### P3.5: Windows Symlink / Junction Diagnostics & Fallback Detection
- **Rationale**: On Windows machines without Developer Mode enabled, Composer cannot create symbolic links or NTFS junctions, falling back silently to copying files. This breaks real-time package code reflections in `vendor/`.
- **Approach**:
  - Add a diagnostics check in `pkg:install` and `pkg:check` that verifies whether linked packages in `vendor/` are active NTFS junctions or symlinks.
  - Warn the user with clear instructions to enable Windows Developer Mode if copies are detected.
- **Outcome**: Prevents subtle out-of-sync bugs during package development on Windows.

---

### 4. Low Priority / Deferred

#### P4.1: Frontend Resources & Vite Path Aliases
- **Decision**: Deferred to Backlog. Most domain and infrastructure packages are purely backend/API components. Frontend scaffolding will be revisited when UI-centric packages are introduced.

## Reliability Review Follow-up

- P1.3: version checks now use clean HEAD tags and current branch aliases, reject unknown/incompatible versions, and leave final solving to Composer.
- P2.1: implemented as an explicit standalone installation/test mode. The former namespace-regex approximation was removed. All mandatory release checks must pass; skipped or unconfigured work is incomplete.
- P2.2: removal now validates canonical package paths, fails closed on Git uncertainty, preserves manifests on prerequisite failures and requires explicit noninteractive confirmation. It changes the manifest only; Composer installation reconciliation remains a separate operation.
- P2.4: manifest writes use Symfony Filesystem atomic replacement under the existing advisory lock. This coordinates DevKit writers, not arbitrary external editors or Composer processes.
- Cross-platform validation: Windows and macOS jobs were added alongside the existing Ubuntu compatibility matrix. This does not complete P3.3 (generated CI workflows for new packages).
