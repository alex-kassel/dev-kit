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
