# Package Release & Packagist Publication Reference

## 1. Release Modes

| Criteria | Mode A: Initial Public Release | Mode B: Fast-Track "Green Corridor" |
|---|---|---|
| **Trigger** | First time package is published to Packagist | Routine version bumps (`v1.0.1`, `v1.1.0`) |
| **Verification** | `composer pkg:release-check <pkg>` + Clean App test | `composer pkg:release-check <pkg>` (~10s) |
| **Packagist** | Manual submit on Packagist.org + Webhook setup | Automatic sync via GitHub Webhook upon `git push --tags` |

## 2. GitHub Repository Metadata Standard
- **Description**: Exact description matching package `composer.json`.
- **Website**: Link to the package page on Packagist (`https://packagist.org/packages/<vendor>/<package>`).
- **Topics**: Strictly **3 most relevant keywords** (e.g. `laravel`, `domain-driven`, `multi-database`).

## 3. Strict SemVer, Tag Immutability & Git Hygiene
- **Pull-Before-Tag**: Always run `git pull --rebase origin main` before creating and pushing tags to ensure local workspace is aligned across all machines.
- **Tag Immutability**: All published tags are strictly immutable. Never delete, re-point, or force-push an existing tag.
- Any subsequent hotfix or adjustment must be released under an incremented version tag (`vX.Y.(Z+1)`).

## 4. Reporting Protocol
When commits or tags are pushed to remote, the agent must always provide the user with direct clickable Markdown web links:
- **Repository:** `https://github.com/<vendor>/<package>`
- **Release Tag:** `https://github.com/<vendor>/<package>/releases/tag/vX.Y.Z`
- **Commit:** `https://github.com/<vendor>/<package>/commit/<hash>`
- **Packagist:** `https://packagist.org/packages/<vendor>/<package>`

## 5. Audit Certificate Invariant
- `RELEASE-GATE.md` is strictly an audit artifact produced exclusively by `composer pkg:audit`.
- In routine releases (Mode B "Green Corridor"), never manually update `RELEASE-GATE.md` to silence `ACTION_REQUIRED`. The `ACTION_REQUIRED` notice is standard and expected between full audits when code changes have occurred.
- Only a complete audit run (`composer pkg:audit`) is authorized to certify and freeze a new `RELEASE-GATE.md`.
