<dev-kit-guidelines>
# AGENTS.MD — Repository Guidelines & Invariants

Welcome to the project repository. Any AI agent (Antigravity, Cursor, Copilot, etc.) working in this environment MUST strictly adhere to the project invariants, skills, and automation tooling indexed below.

---

## 🔒 1. Core Invariants (Non-Negotiable)

1. **Language & Communication Protocol**:
   - **User Communication**: Always communicate with the user in **Russian**.
   - **Sequential Numbering**: In every user response, the agent MUST format all paragraphs, findings, proposals, and action items with a **single continuous sequential numbering (1, 2, 3, ... N)** from top to bottom, enabling the user to respond simply by referencing item numbers.
   - **Code & Docs**: All code, comments, documentation, and commits in **English**.
2. **Architecture & Code Isolation**:
   - **Code Isolation**: All domain logic resides exclusively in `packages/<vendor>/<package-name>/`.
   - **Independent Repositories**: Each package in `packages/` is an independent Git repository with its own remote tracking branch.
   - **Unified Root Tooling**: Never run `composer install` inside packages. All packages share the single root `vendor/` directory. Run package tests (`php artisan test -c ...`), Pint, and PHPStan from the repository root via CLI tooling.
   - **Clean Host**: The root Laravel app remains a vanilla development skeleton.
3. **Strict No-Stubs Policy**:
   - Never use empty method stubs, placeholder dummy implementations (`// TODO`, `// pass`, empty functions), or incomplete shortcuts. All logic must be complete and production-grade.
4. **Git Hygiene, Pull-Before-Commit & Explicit Reporting**:
   - **Pull-Before-Commit**: Before staging, committing, or pushing changes, the agent MUST run `git pull --rebase` (or check remote tracking branch) in the affected repository to ensure the local copy is 100% up-to-date and prevent collisions across machines.
   - **Atomic Commits**: Maintain atomic semantic commits (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`) in the respective package repositories.
   - **Explicit Commit Reporting**: Always provide an explicit commit report to the user (repository path, commit hash, commit message).
   - **Git Config Safety**: Never modify global or local `git config` without explicit user permission.
   - **Tag Immutability**: Published release tags (`v*`) are strictly immutable (force-push is prohibited).
5. **Engineering Humility & Factual Precision**:
   - Never use hyperbolic claims ("100% bug-free", "zero bugs", "flawless"). Use factual, evidence-based statements.
6. **Critical Thinking & Best Practices Enforcement (No Yes-Man Bias)**:
   - The agent MUST NEVER blindly agree with the user or act as a passive "yes-man".
   - The agent MUST critically evaluate all technical proposals against established software engineering standards, Laravel ecosystem best practices, and long-term architectural hygiene.
   - If a user's suggestion introduces technical debt, anti-patterns, or violates industry conventions, the agent MUST respectfully challenge the idea, explain the trade-offs, present concrete counter-arguments, and propose the standard community-approved solution before taking action.
7. **Cross-Platform Engineering**:
   - Ensure all code, paths (forward slashes/`DIRECTORY_SEPARATOR`), and commands run seamlessly across Windows, Linux, and macOS.
8. **Dev-Kit Toolchain Packaging Invariant**:
   - `alex-kassel/dev-kit` is intentionally designed as an **Opinionated Meta-Toolchain (DevKit)**.
   - Because Composer strictly ignores transitive `require-dev` dependencies, `alex-kassel/dev-kit/composer.json` deliberately declares testing and analysis engines (`phpunit/phpunit`, `orchestra/testbench`, `phpstan/phpstan`) in its **`require`** section.
   - When the host application installs dev-kit via `composer require --dev alex-kassel/dev-kit`, this guarantees immediate zero-config availability of the full verification suite in `vendor/bin/`.
   - **AI Agent Directive**: Agents MUST NOT flag this dependency structure as an error, anti-pattern, or code smell, and MUST NOT propose moving these dependencies to `require-dev`.

---

## 🛠️ 2. Automated CLI Tooling (Always Run from Root)

Use these deterministic Artisan and Composer CLI commands powered by `alex-kassel/dev-kit`:

| Command / Shortcut | Purpose | Options |
|---|---|---|
| `php artisan pkg:install` | Prepare local package directories, Composer repo, Git ignore & agent skills | `--dry-run`, `--force`, `--json` |
| `php artisan pkg:clone <pkg>` | Clone package from remote Git repo and auto-register in host | `--no-local-deps`, `--dry-run`, `--json` |
| `php artisan pkg:list` | Inventory of local packages and Composer installation/link status | `--json` |
| `php artisan pkg:sync` | Synchronize local packages with root `composer.json` | `--clean`, `--filter=PATTERN`, `--dump`, `--no-dump`, `--dry-run`, `--json` |
| `php artisan pkg:check <pkg>` | Run full quality checks (Composer, Pint, PHPStan Level 8, Tests) | `--all`, `--fix`, `--only=...`, `--json` |
| `php artisan pkg:make <pkg>` | Scaffold new package with standard 12 files and archetype | `--git`, `--register`, `--archetype=...`, `--dry-run`, `--json` |
| `php artisan pkg:readme <pkg>` | Validate README structure against the 7-badge standard | `--json` |
| `php artisan pkg:release-check <pkg>` | Run pre-flight release-gate checks before tagging | `--json` |
| `composer test:tooling` | Self-test the monorepo automation scripts | |

---

## 🧠 3. Operational Skills Index (`.agents/skills/`)

For procedural guidance, activate and follow the corresponding skill:

- [`.agents/skills/package-scaffolding/SKILL.md`](file:///.agents/skills/package-scaffolding/SKILL.md): Creating and initializing new packages.
- [`.agents/skills/package-verification/SKILL.md`](file:///.agents/skills/package-verification/SKILL.md): Testing, static analysis (PHPStan Level 8+), Pint formatting, and troubleshooting.
- [`.agents/skills/package-readme/SKILL.md`](file:///.agents/skills/package-readme/SKILL.md): Composing and validating enterprise README files.
- [`.agents/skills/package-audit/SKILL.md`](file:///.agents/skills/package-audit/SKILL.md): Executing 2-phase package audits via self-contained audit framework.
- [`.agents/skills/package-release/SKILL.md`](file:///.agents/skills/package-release/SKILL.md): Pre-flight release checks, SemVer versioning, and Packagist release pipeline.

---

## 📁 4. Repository Layout

```
├── .agents/
│   └── skills/          # Modular agent skills & runbooks
│       ├── package-scaffolding/
│       ├── package-verification/
│       ├── package-readme/
│       ├── package-audit/
│       └── package-release/
├── app/                 # Root Laravel skeleton (keep vanilla)
├── config/              # Root configuration
├── packages/            # Modular packages (independent git repos)
│   └── <vendor>/        # In-development packages
├── composer.json        # Root composer configuration & package path mapping
└── AGENTS.md            # Root repository guidelines & index
```

---

## 🗺️ 5. Package Roadmaps & Architectural Proposals

When developing, planning, or refactoring packages inside `packages/`:
- **Check Existing Roadmaps**: Always inspect whether the target package defines a `ROADMAP.md` (e.g. `packages/<vendor>/<package>/ROADMAP.md`) or concept proposals in `docs/proposals/` before proposing changes.
- **Priority Alignment**: Align implementation priorities with the established roadmap (Critical > High > Medium > Low).
- **Roadmap Maintenance**: Keep package roadmaps and proposal statuses synchronized whenever features are delivered or technical decisions are agreed upon.
</dev-kit-guidelines>

