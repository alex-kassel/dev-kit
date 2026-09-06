# Package Audit Contracts Reference

The framework defines 7 specialized audit domain contracts located in `references/agents/`:

| Agent Contract | Focus Area | Mandatory Verification Command / Tool |
|---|---|---|
| **[`01_architecture_api`](./agents/01_architecture_api.md)** | Public API Surface, BC compatibility, ServiceProvider registration, archetype rules | Manual AST review + static reflection |
| **[`02_code_quality`](./agents/02_code_quality.md)** | PSR-12/Laravel formatting, PHPStan Level 8+ strictness, exception taxonomy | `composer pkg:check <pkg> --json` |
| **[`03_database`](./agents/03_database.md)** | Migrations isolation, SQLite/MySQL compatibility, prefix safety, composite indexes | Migration dry-run & context inspection |
| **[`04_security_isolation`](./agents/04_security_isolation.md)** | Secrets leakage, container singleton pollution, ambient state safety | Security scan & binding isolation check |
| **[`05_composer_supply_chain`](./agents/05_composer_supply_chain.md)** | `composer validate --strict`, `.gitattributes export-ignore`, dependency boundaries | `composer validate --strict` + archive inspection |
| **[`06_testing_compatibility`](./agents/06_testing_compatibility.md)** | Unit/Feature test suite, Orchestra Testbench integration, edge-case coverage | `php artisan test -c ...` |
| **[`07_consumer_release`](./agents/07_consumer_release.md)** | README standard, GitHub topics (exact 3), CHANGELOG, LICENSE, Release Gate report | `composer pkg:readme <pkg> --json` |

## RELEASE-GATE Verification Snapshot Rule
- `RELEASE-GATE.md` is a frozen verification snapshot tied to a specific commit hash.
- Routine patch updates do not require re-auditing.
- Never edit `RELEASE-GATE.md` manually to bump version numbers.
