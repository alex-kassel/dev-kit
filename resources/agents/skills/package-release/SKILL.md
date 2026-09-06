---
name: package-release
description: >-
  Use this skill when the user asks to release, publish, tag, or prepare a package for Packagist
  (e.g., "подготовь к публикации на Packagist", "выпусти релиз", "опубликуй пакет", "release package", "prepare release").
---

# Package Release Skill

This skill guides the pre-flight verification, version tagging, and Packagist release workflow.

## Operational Workflow

1. **Run Automated Pre-Flight Release Gate**:
   Execute the automated release check tool:
   ```bash
   composer pkg:release-check <vendor>/<package-name>
   ```
   *Or with JSON output:*
   ```bash
   composer pkg:release-check <vendor>/<package-name> --json
   ```

2. **Handle Release Verdict**:
   - **`READY`**: All quality checks pass, git tree is clean, README is compliant, and audit certificate is fresh. Proceed to step 3.
   - **`ACTION_REQUIRED`**: Source code changes detected since last audit certificate (`RELEASE-GATE.md`). Prompt the user:
     - *Option 1:* Proceed with release (minor non-breaking changes).
     - *Option 2:* Run full package audit first (`composer pkg:audit`).
   - **`BLOCKED`**: Release blockers detected (failing tests, PHPStan errors, dirty git tree, or README violations). Fix blockers before releasing.

3. **Release Execution**:
   - Determine target SemVer tag (e.g. `v0.1.0`, `v1.0.0`, `v1.0.1`).
   - **Pull-Before-Release**: Always pull latest changes from remote before tagging:
     ```bash
     git pull --rebase origin main
     ```
   - **Tag Immutability**: Published git tags are strictly immutable (`git push --force` is prohibited).
   - Create annotated tag and push:
     ```bash
     git tag -a vX.Y.Z -m "Release vX.Y.Z"
     git push origin main --tags
     ```

4. **Detailed Reference**:
   For Packagist webhook setup and GitHub metadata requirements, see [Release Pipeline Reference](./references/release-pipeline.md).
