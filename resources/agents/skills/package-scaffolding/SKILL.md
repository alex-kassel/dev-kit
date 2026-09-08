---
name: package-scaffolding
description: >-
  Use this skill when the user asks to create, initialize, or scaffold a new package in the monorepo
  (e.g., "создай новый пакет", "создай библиотеку", "инициализируй пакет", "create package").
---

# Package Scaffolding Skill

This skill guides the deterministic, non-destructive creation of new packages in `packages/<vendor>/<package-name>`.

## Operational Workflow

1. **Clarify Package Identification**:
   - Determine `<vendor>` (`alex-kassel` for public open-source, `alex-privat` for pre-Packagist, `alex-local` for local domain modules).
   - Determine `<package-name>` (kebab-case).
   - Determine package archetype (`library` by default, `engine` for capability cores, `domain` for business domains). See [Archetypes Reference](./references/archetypes.md).

2. **Execute Scaffolding Generator**:
   Run the deterministic CLI scaffolding tool with `--git`, `--register`, and `--update` (auto-commits initial files and links locally):
   ```bash
   php artisan pkg:make <vendor>/<package-name> --archetype=<type> --git --register --update
   ```
   *Tip: You can preview generated files first using `--dry-run --json`.*

3. **Verify Newly Scaffolded Package**:
   Immediately run the quality verification suite to ensure 100% green corridor:
   ```bash
   composer pkg:check <vendor>/<package-name> --json
   ```

4. **Implement Initial Package Logic**:
   - Add contracts, DTOs, and services in `src/`.
   - Add unit/feature tests in `tests/`.
   - Remember: **Strict No-Stubs Policy** (never leave empty methods, `// TODO`, or fake stubs).

5. **Commit Logical Unit**:
   Commit newly created files in the package's independent git repository and report commit hash to the user.
