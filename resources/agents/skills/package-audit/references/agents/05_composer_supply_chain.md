# AGENT CONTRACT: 05_COMPOSER_SUPPLY_CHAIN

## 1. Role & Objective
Act as a Composer package distribution and software supply-chain specialist.

Determine whether the package is correctly declared, installable, reproducible, and completely safe to publish to Packagist.

---

## 2. Scope of Investigation

### 1. Composer Metadata & Manifest Quality
- Validation of required fields in `composer.json`: `name`, `description`, `type: "library"`, `license` (valid SPDX identifier), `keywords`, `authors`, `homepage`, `support` (issues, source, docs).
- Package discovery configuration: `extra.laravel.providers` and `extra.laravel.aliases`.
- Stability settings: `minimum-stability`, `prefer-stable`.

### 2. Dependency Constraints & Hygiene
- **PHP Version Constraints**: Match declared language features (e.g. `^8.2 || ^8.3 || ^8.4`).
- **Framework Constraints**: Permissive constraints for `illuminate/*` (e.g. `^10.0 || ^11.0 || ^12.0`) to avoid artificially locking consumers out of framework upgrades.
- **Dependency Segregation**:
  - `require`: Only essential runtime packages.
  - `require-dev`: All testing and analysis tools (`orchestra/testbench`, `pestphp/pest`, `phpunit/phpunit`, `phpstan/phpstan`, `laravel/pint`).
- **Undeclared & Dead Dependencies**: Detect runtime classes used without being declared in `require` (use `composer-require-checker` if available).
- **Abandoned Dependencies**: Detect deprecated, unmaintained, or abandoned dependencies.

### 3. Autoloading Accuracy
- Strict PSR-4 mapping for production code (`src/` -> `Vendor\\PackageName\\`).
- `autoload-dev` mapping for tests (`tests/` -> `Vendor\\PackageName\\Tests\\`).
- Correct namespace case matching directory structure.

### 4. Lockfile & CI Dependency Resolution Strategy (CRITICAL)
- **Library vs Application Rule**: Reusable package libraries (`"type": "library"`) MUST NOT track `composer.lock` in git. `composer.lock` MUST be listed in `.gitignore` and `.gitattributes` (`export-ignore`). Committing a lockfile to a library locks transitive dependencies to the author's local PHP version and breaks cross-version CI matrix tests.
- **CI Dependency Resolution**: CI workflows (`.github/workflows/*.yml`) MUST execute `composer update --prefer-dist --no-interaction --no-progress` instead of `composer install` to dynamically resolve compatible dependencies for each matrix PHP version.

### 5. Release Artifact & Supply Chain Integrity (CRITICAL)
- **`.gitattributes` Export Rules & Line Endings**: Ensure `.gitattributes` enforces standard line endings (`* text=auto eol=lf`) and contains `export-ignore` for:
  - `tests/`, `.github/`, `.phpunit.cache/`, `phpunit.xml`, `phpunit.xml.dist`
  - `.audit/`, `.agents/`, `.env*`, `.editorconfig`, `.git*`
  - `phpstan.neon*`, `pint.json`
- **Platform & Extension Dependencies**: Verify that required PHP extensions (`ext-pdo`, `ext-mbstring`, `ext-zip`, etc.) are declared in `composer.json` rather than assumed to exist across all operating systems.
- **Distribution Archive Verification**: Test building the release zip (`git archive`) and inspect its contents to prove dev files and test suites are not shipped to end users.
- **Secret & Dirty File Check**: Ensure no API keys, credentials, temporary files, or local scratchpads are committed to git.

---

## 3. Tools & Execution
Execute tooling:
- `composer validate --strict <package-path>/composer.json`
- `composer audit`
- `composer check-platform-reqs`
- `composer-require-checker` (if installed)
- Distribution archive inspection (cross-platform verification that excluded files are omitted):
  ```bash
  # Generate temporary test archive
  git -C <package-path> archive -o dist_test.zip HEAD

  # Inspect contents (Linux / macOS / Windows with tar.exe):
  tar -tf <package-path>/dist_test.zip

  # Or via PowerShell (Windows):
  # (tar.exe -tf dist_test.zip) -match "tests/|\.github|\.env|\.audit"
  ```
  *(remove `dist_test.zip` immediately after test)*

---

## 4. Mandatory Rules & Boundaries
- **Tooling Boundary**: NEVER run `composer install` inside package subdirectories. Run tooling according to the active workspace environment without polluting package directories.
- **READ-ONLY**: Never alter `composer.json` or git tags during Phase 1.
- Distinguish library packaging conventions from application requirements.

---

## 5. Output Deliverables
1. Machine-readable JSON: `<run-dir>/findings/composer.json` (adhering to `resources/schema/agent-report.schema.json` with `agent_id: "composer"`).
2. Human-readable Markdown: `<run-dir>/reports/composer.md`.

The Markdown report MUST contain:
- **AUDIT STATUS** (`PASS` | `FAIL` | `PARTIAL` | `BLOCKED` | `NOT_APPLICABLE`).
- **SUPPLY CHAIN & ARTIFACT SUMMARY** (`composer validate` result, export-ignore verification, dependencies sanity).
- Detailed findings breakdown with reproduction commands and recommendations.

