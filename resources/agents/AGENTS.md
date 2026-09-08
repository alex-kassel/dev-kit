<dev-kit-guidelines>
# AGENTS.MD — Repository Guidelines

## 1. Core Rules

1. **Language & Communication Protocol**:
   - **User Communication**: Always communicate with the user in **Russian**.
   - **Sequential Numbering**: In every user response, format all paragraphs, findings, proposals, and action items with a **single continuous sequential numbering (1, 2, 3, ... N)** from top to bottom, enabling the user to reference item numbers directly.
   - **Code & Docs**: All code, comments, documentation, and commits in **English**.
2. **Architecture & Code Isolation**:
   - **Code Isolation**: All domain logic resides exclusively in `packages/<vendor>/<package-name>/`.
   - **Independent Repositories**: Each package in `packages/` is an independent Git repository with its own remote tracking branch.
   - **Unified Root Tooling**: Never run `composer install` inside packages. All packages share the single root `vendor/` directory. Run package tests, Pint, and PHPStan from the repository root via CLI tooling.
   - **Clean Host Skeleton**: The root Laravel app remains a vanilla development skeleton and testing harness.
3. **Complete Implementations**:
   - Provide complete, production-ready code. Do not leave empty method stubs, placeholder dummy implementations (`// TODO`, `// pass`, empty functions), or incomplete shortcuts.
4. **Git Hygiene & Explicit Reporting**:
   - **Pull-Before-Commit**: Before staging or committing changes, run `git pull --rebase` (or check the remote tracking branch) in the affected repository to prevent collisions.
   - **Atomic Commits**: Maintain atomic semantic commits (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`) in the respective package repositories.
   - **Explicit Reporting**: Always provide an explicit commit report to the user (repository path, commit hash, commit message). When changes are pushed to remote, always include direct clickable Markdown web links to the GitHub repository, commits, and release tags (e.g. `[hash](https://github.com/<vendor>/<package>/commit/<hash>)`).
   - **Git Safety**: Do not modify global or local `git config` without explicit user permission, and never force-push release tags (`v*`).
   - **Audit Certificate Invariant**: `RELEASE-GATE.md` is strictly an audit artifact produced exclusively by `composer pkg:audit`. Never manually edit versions, commit hashes, or verdicts in `RELEASE-GATE.md` during routine releases or patch commits.
5. **Technical Rigor & Critical Review**:
   - Base technical statements on verifiable facts, code inspection, and test results. Avoid unsubstantiated claims.
   - Critically evaluate technical decisions against established software engineering standards and Laravel ecosystem conventions. If an approach introduces technical debt or anti-patterns, explain the trade-offs and propose standard alternatives.
6. **Cross-Platform Engineering**:
   - Ensure all code, paths (use `DIRECTORY_SEPARATOR` or `/`), and commands execute reliably across Windows, Linux, and macOS.
7. **Toolchain Dependencies**:
   - `alex-kassel/dev-kit` deliberately declares verification engines (`phpunit/phpunit`, `orchestra/testbench`, `phpstan/phpstan`) in its `require` section so that installing it as a dev dependency (`--dev`) in the host makes the full toolchain immediately available in `vendor/bin/`. Do not move these dependencies to `require-dev`.

---

## 2. Automated CLI Tooling (Run from Root)

| Command / Shortcut | Purpose | Options |
|---|---|---|
| `php artisan pkg:install` | Prepare local package directories, Composer repo, Git ignore & agent skills | `--local`, `--branch=...`, `--dry-run`, `--force`, `--json` |
| `php artisan pkg:clone <pkg>` | Clone package from remote Git repo and auto-register in host | `--branch=...`, `--recursive`, `--org=...`, `--dry-run`, `--json` |
| `php artisan pkg:list` | Inventory of local packages and Composer installation/link status | `--json` |
| `php artisan pkg:sync` | Synchronize local packages with root `composer.json` | `--clean`, `--filter=PATTERN`, `--dry-run`, `--json` |
| `php artisan pkg:check <pkg>` | Run full quality checks (Composer, Pint, PHPStan Level 8, Tests) | `--all`, `--fix`, `--only=...`, `--json` |
| `php artisan pkg:make <pkg>` | Scaffold new package with standard files and archetype | `--git`, `--register`, `--archetype=...`, `--dry-run`, `--json` |
| `php artisan pkg:readme <pkg>` | Validate README structure against the 7-badge standard | `--json` |
| `php artisan pkg:release-check <pkg>` | Run pre-flight release-gate checks before tagging | `--json` |
| `composer test:tooling` | Self-test the monorepo automation scripts | |

---

## 3. Operational Skills (`.agents/skills/`)

- [`.agents/skills/package-scaffolding/SKILL.md`](file:///.agents/skills/package-scaffolding/SKILL.md): Scaffolding and initializing new packages.
- [`.agents/skills/package-verification/SKILL.md`](file:///.agents/skills/package-verification/SKILL.md): Testing, static analysis (PHPStan Level 8+), Pint formatting, and troubleshooting.
- [`.agents/skills/package-readme/SKILL.md`](file:///.agents/skills/package-readme/SKILL.md): Composing and validating README files against the standard badge and section schema.
- [`.agents/skills/package-audit/SKILL.md`](file:///.agents/skills/package-audit/SKILL.md): Executing package quality audits.
- [`.agents/skills/package-release/SKILL.md`](file:///.agents/skills/package-release/SKILL.md): Pre-flight release checks and SemVer release pipeline.

---

## 4. Repository Layout

```
├── .agents/
│   └── skills/          # Modular agent skills & runbooks
├── app/                 # Root Laravel skeleton (testing harness)
├── config/              # Root configuration
├── packages/            # Modular packages (independent git repos)
│   └── <vendor>/        # In-development packages
├── composer.json        # Root composer configuration & package path mapping
└── AGENTS.md            # Root repository guidelines & index
```

---

## 5. Package Roadmaps & Architecture

When developing or planning changes inside `packages/`:
- Inspect whether the target package defines a `ROADMAP.md` (e.g. `packages/<vendor>/<package>/ROADMAP.md`) or proposals in `docs/proposals/`.
- Align implementation priorities with the roadmap (Critical > High > Medium > Low).
- Keep roadmaps and proposal documents synchronized when delivering features or agreeing on technical decisions.
</dev-kit-guidelines>

