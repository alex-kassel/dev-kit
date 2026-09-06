---
name: package-readme
description: >-
  Use this skill when the user asks to create, update, or validate the README.md for a package
  (e.g., "создай README", "обнови README", "составим README для пакета", "check readme", "readme standard").
---

# Package README Skill

This skill guides the composition and automated validation of enterprise-grade package READMEs.

## Operational Workflow

1. **Inspect Actual Package Sources**:
   Before writing or updating `README.md`, inspect actual codebase:
   - `composer.json` (exact package name, PHP & Laravel constraints)
   - `src/` (public API, service providers, facades, contracts)
   - `config/` (actual configuration keys)
   - `tests/` (real-world usage examples)
   - `RELEASE-GATE.md` (audit certificate existence)

2. **Compose README Following Canonical Standard**:
   Ensure the README includes:
   - Cross-platform centered Hero header (`<h1 align="center">`, `<p align="center">`)
   - Quick navigation links
   - 4–5 compact flat badges following canonical palette (see [Badge & Section Reference](./references/badge-palette-and-sections.md))
   - Canonical section order: `Key Features` → `Requirements` → `Installation` → `Usage` → `Testing` → `Changelog` → `Security` → `License`.

3. **Validate README Structure**:
   Execute the automated README linter:
   ```bash
   composer pkg:readme <vendor>/<package-name>
   ```
   *Or with JSON output:*
   ```bash
   composer pkg:readme <vendor>/<package-name> --json
   ```

4. **Remediate Any Linter Warnings**:
   Fix any missing sections, double pipe violations (`||`), or unresolved placeholders until `composer pkg:readme` returns `PASS`.
