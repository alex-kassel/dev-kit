---
name: package-audit
description: >-
  Use this skill when the user requests a full package audit
  (e.g., "проведи полный аудит пакета", "полный аудит", "audit package", "run package audit").
---

# Package Audit Skill

This skill provides the self-contained industrial audit framework for packages in the repository.

## Directory Structure & Resources

- [**Orchestrator Guide**](./references/orchestrator.md): Detailed 2-phase lifecycle and agent aggregation workflow.
- [**7 Specialized Audit Contracts**](./references/agents/):
  - [`01_architecture_api.md`](./references/agents/01_architecture_api.md) — Public API surface, BC safety, host isolation.
  - [`02_code_quality.md`](./references/agents/02_code_quality.md) — Pint, PHPStan Level 8+, exceptions taxonomy.
  - [`03_database.md`](./references/agents/03_database.md) — Migrations, table prefixes, SQLite/MySQL isolation.
  - [`04_security_isolation.md`](./references/agents/04_security_isolation.md) — Secret leaks, service container pollution.
  - [`05_composer_supply_chain.md`](./references/agents/05_composer_supply_chain.md) — `composer validate --strict`, export-ignore.
  - [`06_testing_compatibility.md`](./references/agents/06_testing_compatibility.md) — Unit & Feature test assertions.
  - [`07_consumer_release.md`](./references/agents/07_consumer_release.md) — README compliance, CHANGELOG, LICENSE.
- **Templates & Schemas**:
  - Manifest Template: [`resources/templates/audit-manifest.template.json`](./resources/templates/audit-manifest.template.json)
  - Release Gate Template: [`resources/templates/release-gate.template.md`](./resources/templates/release-gate.template.md)
  - JSON Schemas: [`resources/schema/`](./resources/schema/)
- **Package-Local Audit Runs**: Saved directly in `packages/<vendor>/<package>/.audit/YYYY-MM-DD_HH-MM-SS/` (gitignored).

---

## Operational Workflow

### Phase 1: Audit-Only & Human Decision Gate (Read-Only)
1. **Pre-Audit Discovery**:
   - Inspect package archetype (Library, Engine, Domain) and identify consumers in `packages/`.
   - Determine target version (default `0.1.0` or latest git tag).
2. **Execute 7 Specialized Audit Contracts**:
   - Run each contract defined in [`references/agents/`](./references/agents/).
   - Populate audit manifest using [`resources/templates/audit-manifest.template.json`](./resources/templates/audit-manifest.template.json).
3. **Compile Run Artifacts**:
   - Save findings and reports into `packages/<vendor>/<package>/.audit/<timestamp>/findings.json`.
   - Compile actionable choices into `packages/<vendor>/<package>/.audit/<timestamp>/decisions.md`.
   - Generate preliminary `RELEASE-GATE.md`.
4. **Mandatory Phase 1 Chat Output Protocol**:
   In the user response, the agent MUST format findings into 3 structured sections:
   - **Section 1: Test & Tooling Baseline**: Exact results of CLI verification (`composer pkg:check <vendor/package>` — Composer validate, Pint, PHPStan, PHPUnit/Pest).
   - **Section 2: Mechanical & Routine Fixes**: List of non-invasive fixes planned for Phase 2 (Pint formatting, PHPStan type annotations, missing docblocks, syntax cleanups).
   - **Section 3: Human Decisions & Logic Interventions**: Items requiring explicit human approval (public API changes, adding/removing/calling methods, service logic changes, schema/migration alterations).
     - **Mandatory Agent Recommendation & Rationale**: For every non-obvious issue or dilemma, the agent MUST provide its explicit technical recommendation (`(Recommended)`) accompanied by concrete architectural rationale and trade-off analysis.
5. **🛑 MANDATORY HUMAN GATE (HARD STOP)**:
   - **Zero Code Modification**: The agent is **strictly prohibited** from editing any source files, running mutating commands, creating git commits, or proceeding to Phase 2 in the same turn.
   - The agent **MUST stop calling tools and end the turn** immediately after outputting the Phase 1 report, awaiting the user's explicit confirmation or feedback on Section 3 items.

### Phase 2: Remediation & Certification (After User Confirmation)
1. **Remediation Graph**:
   Execute fixes sequentially based on user decisions: Root Architectural/Schema → API/DB refactor → Code quality & types → Tests.
2. **Atomic Commits & Delta Verification**:
   - Create atomic semantic commits for discrete defect groups.
   - Verify with `composer pkg:check <vendor>/<package> --json` and `composer pkg:readme <vendor>/<package> --json`.
3. **Freeze `RELEASE-GATE.md` & Badge**:
   - Copy final certified `RELEASE-GATE.md` into target package root with exact commit SHA and framework version.
   - Update README audit badge and monorepo DASHBOARD.md.
   *Rule: `RELEASE-GATE.md` is an immutable certified snapshot and must never be edited in-place.*
