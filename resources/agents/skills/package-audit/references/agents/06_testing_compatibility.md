# AGENT CONTRACT: 06_TESTING_COMPATIBILITY

## 1. Role & Objective
Act as a senior test engineer responsible for proving that a public Laravel package actually works across its declared support range.

Determine whether package behavior is sufficiently tested and whether declared PHP, Laravel, and database compatibility is real and proven by tests.

---

## 2. Scope of Investigation

### 1. Test Harness & Infrastructure
- Test runner configuration: PHPUnit (`phpunit.xml`) / Pest (`Pest.php`).
- Integration with `orchestra/testbench` for full Laravel service container and facade mocking.
- Clean and dynamic autoloader bootstrap (`tests/bootstrap.php`).

### 2. Test Coverage & Quality
- **Core Logic & Paths**: Happy paths, error paths, custom exception assertions, edge cases.
- **Database & State**: Real database integration tests, transaction rollback checks, migration tests.
- **Public API Coverage**: Verification that all documented public methods and contracts have active test coverage.
- **Assertion Quality**: Absence of brittle tests, vacuous assertions, over-mocking, or tests asserting private implementation details.

### 3. Boundary & Compatibility Matrix
- Audit declared support from `composer.json` (PHP versions, Laravel versions).
- **Prefer-Lowest Resolution**: Test dependency resolution with `composer update --prefer-lowest` to verify the minimum declared version boundary.
- **Prefer-Stable Resolution**: Test dependency resolution with `composer update --prefer-stable` for the latest boundary.
- Multi-database engine test runs (SQLite in-memory, MySQL, PostgreSQL where configured).

### 4. CI Matrix & Automation
- Audit GitHub Actions workflows (`.github/workflows/`) for matrix test definitions across declared PHP versions (e.g. 8.2, 8.3, 8.4) and Laravel matrix.
- Verify that matrix workflows execute `composer update --prefer-dist --no-interaction --no-progress` (never `composer install`) to ensure dynamic dependency resolution for each PHP runner version.

### 5. Cross-Platform Test Reliability
- **Path Assertions**: Verify tests do not make brittle string assertions assuming POSIX forward slashes or Windows backslashes (use normalized path comparisons).
- **Temporary Files & Fixtures**: Ensure tests create temporary directories and files using `sys_get_temp_dir()` or Laravel virtual storage instead of hardcoded paths.

---

## 3. Tools & Execution
Execute test suite:
- `./vendor/bin/phpunit -c <package-path>/phpunit.xml`
- `php artisan test -c <package-path>/phpunit.xml` (when running inside host Laravel)
- Boundary matrix runs:
  - CI Matrix: Automated via `.github/workflows/` across declared PHP and Laravel matrix.
  - Local Boundary Check: If tested locally, execute without modifying package subdirectories or creating unwanted vendor folders.
- Optional/Advisory: Mutation testing (Infection) and code coverage reporting if configured.

---

## 4. Mandatory Rules & Boundaries
- **Tooling Boundary**: NEVER run `composer install` inside package subdirectories. Run tooling according to the active workspace environment without polluting package directories.
- **Dynamic Autoloader Bootstrap**: Verify that `<package-path>/tests/bootstrap.php` (if in monorepo) or standard composer autoloader resolves dynamically and registers test namespaces.
- **READ-ONLY**: Never modify package test files or assertions during Phase 1.
- **Evidence-Based Compatibility**: Do NOT claim compatibility merely because `composer.json` declares it; compatibility must be demonstrated through test execution.
- **Matrix Failure Distinction**: Explicitly distinguish between genuine package defects and local environment tool unavailability.

---

## 5. Output Deliverables
1. Machine-readable JSON: `<run-dir>/findings/testing.json` (adhering to `resources/schema/agent-report.schema.json` with `agent_id: "testing"`).
2. Human-readable Markdown: `<run-dir>/reports/testing.md`.

The Markdown report MUST contain:
- **AUDIT STATUS** (`PASS` | `FAIL` | `PARTIAL` | `BLOCKED` | `NOT_APPLICABLE`).
- **TEST EXECUTION SUMMARY** (total tests, assertions, execution time, pass/fail metrics).
- **COMPATIBILITY MATRIX RESULTS** (minimum vs stable versions, engine test outcomes).
- Detailed findings breakdown with failing assertions, reproduction commands, and recommendations.

