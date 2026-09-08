---
name: package-verification
description: >-
  Use this skill when the user asks to test, lint, verify, analyze, or check the quality of a package
  (e.g., "проверь пакет", "запусти тесты", "проверь кодстайл", "run tests", "check package", "phpstan").
---

# Package Verification Skill

This skill guides the automated, multi-suite verification of any package in the repository.

## Operational Workflow

1. **Run Targeted or Universal Verification**:
   - For rapid iteration during active development, run targeted unit tests:
     ```bash
     vendor/bin/phpunit -c packages/<vendor>/<package-name>/phpunit.xml --filter=TestName
     ```
   - For complete package quality verification:
     ```bash
     php artisan pkg:check <vendor>/<package-name>
     ```
   - *Note:* Do NOT run `pkg:check` right before `pkg:release-check`, since `pkg:release-check` already runs the full quality suite.
   - *For machine-readable processing:*
     ```bash
     php artisan pkg:check <vendor>/<package-name> --json
     ```

2. **Interpret Verification Results**:
   The verification suite checks 4 capabilities:
   - **Composer Validation**: `composer validate --strict`
   - **Code Style (Pint)**: Code formatting and PSR standards
   - **Static Analysis (PHPStan)**: Level 8+ type safety
   - **Automated Tests**: Unit & Feature test assertions via Testbench

3. **Remediate Identified Issues**:
   - If **Pint fails**: Auto-fix formatting using `composer pkg:check <vendor>/<package-name> --fix`.
   - If **PHPStan fails**: Inspect error output, fix missing return types, generics, or type mismatches in `src/`. See [Troubleshooting Guide](./references/troubleshooting.md).
   - If **Tests fail**: Read failing test assertions, fix regression, and re-run.
   - If **Composer fails**: Fix license, author, or autoload definitions in package `composer.json`.

4. **Confirm Full Green Corridor**:
   Re-run `composer pkg:check <vendor>/<package-name> --json` until overall status is `passed`.
