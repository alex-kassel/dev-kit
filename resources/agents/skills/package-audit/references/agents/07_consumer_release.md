# AGENT CONTRACT: 07_CONSUMER_RELEASE

## 1. Role & Objective
Act as an external developer who has never seen the package source code and is integrating it into a production Laravel application for the first time.

Determine whether the package can actually be installed, configured, understood, and consumed without friction, and whether it satisfies all release prerequisites for production distribution.

---

## 2. Scope of Investigation

### 1. Fresh Consumer Installation & Isolation (CRITICAL)
- **Isolated Environment**: Test installing the package into a clean, standalone Laravel application using a local Composer path repository (`"type": "path"`).
- **No Parent Vendor Leakage**: The consumer smoke test MUST NOT accidentally resolve package runtime dependencies from the parent monorepo project's `vendor/`.
  - All runtime classes consumed by the package MUST be explicitly declared in the package's own `composer.json` (`require`).
  - An installation into a clean app must resolve dependencies strictly from what the package declares, avoiding false positives where parent dependencies masked missing declarations.
- **Auto-Discovery**: Verify that Laravel's package auto-discovery (`extra.laravel`) registers the ServiceProvider and Facades without manual intervention.
- **Resource Publishing**:
  - `php artisan vendor:publish --tag="<package>-config"` (verify config file lands in `config/`).
  - `php artisan vendor:publish --tag="<package>-migrations"` (verify migration files land cleanly).
  - `php artisan vendor:publish --tag="<package>-views"` / translations / assets (if applicable).
- **End-to-End Consumer Smoke Test**: Execute a minimal consumer script or route calling the public API to verify end-to-end functionality.

### 2. Documentation & Quickstart Validation
- **`README.md` Integrity**:
  - Clear, working installation instructions via Composer.
  - Complete configuration reference explaining all environment variables and options.
  - Minimal quickstart copy-paste example.
  - Verifiable code samples (all snippets in README must be syntactically valid and match current API).
  - Official Audit Verification Badge linked to `RELEASE-GATE.md` (**MUST be the VERY FIRST badge** at the top of the README, using concise flat format `<a href="RELEASE-GATE.md"><img src="https://img.shields.io/badge/Audit-Verified-10b981?logo=shield" alt="Audit Verified"></a>` or `[![Audit Verified](https://img.shields.io/badge/Audit-Verified-10b981?logo=shield)](RELEASE-GATE.md)`). Note: bulky `for-the-badge` format is obsolete.
  - Troubleshooting and upgrade path guides.
- **Standard Legal & Release Documents**:
  - `RELEASE-GATE.md`: Certified audit pass summary referencing the framework using the **Standard 5-Section Gold Layout**:
    1. **Header Callout**: `> 🛡️ **Audited with [Laravel Package Audit Framework](https://github.com/alex-kassel/laravel-package-audit)**`
    2. **Executive Summary Grid**: Table with attributes (`Package Name`, `Target Release Version`, `Target Branch / Commit`, `Release Verdict: 🟢 READY FOR RELEASE`, `Audit Framework Version`, `Certification Date`, `Known Release Blockers`, `Critical Defects`, `Static Analysis Errors`, `Automated Test Assertions`).
    3. **360-Degree Domain Assessment Grid**: Full table of all 7 domains (`#`, `Verification Domain`, `Result`, `Deterministic Verification Command & Evidence`).
    4. **Quality & Verification Scorecard**: Static Analysis output block, PHPUnit CLI test run output block, Supply Chain & Distribution Integrity checklist.
    5. **Audit Trail & Digital Signature**: JSON block with `audit_run`, `package`, `version`, `commit`, `framework`, `framework_version`, `environment`, and `signature` hash.
  - `LICENSE`: Valid OSI-approved license (e.g. MIT, Apache-2.0, BSD-3-Clause).
  - `CHANGELOG.md`: Structured changelog (Keep-a-Changelog standard) documenting changes under the target version.
- **Packagist & GitHub Release Execution**:
  - Pushing Git tags alone (`git push origin <tag>`) is NOT sufficient.
  - The formal GitHub Release MUST be published via `gh release create <tag> --title "<tag>" --notes-file CHANGELOG.md` to ensure GitHub shows the version in the "Latest Release" badge and Packagist hooks trigger properly.
- **Packagist & GitHub Repository Metadata Verification**:
  - Inspect GitHub repository metadata via `gh repo view <vendor>/<package> --json description,homepageUrl,repositoryTopics`:
    - **Description**: MUST match `composer.json` description verbatim.
    - **Homepage/Website**: MUST link to Packagist package page (`https://packagist.org/packages/<vendor>/<package>`).
    - **Topics**: MUST be set to **STRICTLY EXACTLY 3** key domain tags (e.g. `laravel,diagnostics,ai-agent` or `laravel,history,navigation`). Never exceed or fall below 3 topics.

### 3. Developer Experience (DX) & Ergonomics
- Intuitive, idiomatic API design following Laravel conventions.
- Discoverability in modern IDEs (proper PHPDoc, autocomplete, fluent builders).
- Clear, actionable error messages with troubleshooting guidance when misconfigured.

### 4. Release Gate Blockers
Categorize as **BLOCKER** if any of the following occur:
- Package fails to install via Composer in a clean Laravel application.
- Package auto-discovery is broken.
- Documented quickstart / installation instructions fail to execute.
- Required `LICENSE` file is missing or invalid.
- Published configuration or migrations contain syntax errors.
- Release distribution artifact is corrupted or leaks dev files.

---

## 3. Tools & Execution
Execute from repository root:
- `php artisan vendor:publish --provider="<Vendor>\<PackageName>\<ServiceProvider>" --dry-run`
- Consumer smoke test inside host app or Testbench environment (without modifying package source)
- Markdown linting and documentation validation

---

## 4. Mandatory Rules & Boundaries
- **Tooling Boundary**: NEVER run `composer install` inside package subdirectories. Run tooling according to the active workspace environment without polluting package directories.
- **READ-ONLY on Package**: Never modify the target package source code during the consumer test.
- Verify the actual release artifact and published files rather than relying solely on the in-tree source.

---

## 5. Output Deliverables
1. Machine-readable JSON: `<run-dir>/findings/consumer_release.json` (adhering to `resources/schema/agent-report.schema.json` with `agent_id: "consumer_release"`).
2. Human-readable Markdown: `<run-dir>/reports/consumer_release.md`.

The Markdown report MUST contain:
- **AUDIT STATUS** (`PASS` | `FAIL` | `PARTIAL` | `BLOCKED` | `NOT_APPLICABLE`).
- **CONSUMER SMOKE TEST REPORT** (installation result, discovery check, publish check, API execution result).
- **DOCUMENTATION & DX SCORECARD** (README, CHANGELOG, LICENSE verification).
- Detailed findings breakdown with reproduction steps and recommendations.

