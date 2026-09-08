# Modular Laravel Workspace

[![PHP Version](https://img.shields.io/badge/php-%5E8.2%20%7C%20%5E8.3%20%7C%20%5E8.4-777bb4.svg?logo=php&logoColor=white)](https://php.net)
[![Laravel Framework](https://img.shields.io/badge/laravel-11.x%20%7C%2012.x%20%7C%2013.x-ff2d20.svg?logo=laravel&logoColor=white)](https://laravel.com)
[![Tooling](https://img.shields.io/badge/dev--kit-modular--workspace-4f46e5.svg)](https://github.com/alex-kassel/dev-kit)

A standardized, modular development environment and testing harness for Laravel packages.

---

## 1. Architecture & Core Principles

This repository is **not** a monolithic Laravel application. It is a **disposable host harness** engineered to develop, test, and maintain independent Composer packages:

- **Domain Isolation**: All business and library logic resides exclusively in `packages/<vendor>/<package>/`. The root `app/` directory remains a pristine testing shell.
- **Independent Git Repositories**: Each package in `packages/` is an independent Git repository with its own remote tracking branch, tags, and lifecycle.
- **Unified Root Tooling**: Never run `composer install` inside individual packages. All dependencies are linked and resolved via the root `vendor/` directory.
- **Autonomous Automation**: Quality gates (Pint, PHPStan Level 8+, Pest/PHPUnit) are executed uniformly via dev-kit CLI tooling.

---

## 2. Quick Start

### Initial Setup
```bash
composer install
php artisan pkg:install
```

### Clone an Existing Package
```bash
php artisan pkg:clone vendor/package-name
```

### Scaffold a New Package
```bash
php artisan pkg:make vendor/package-name --archetype=library --git --register --update
```

---

## 3. Daily CLI Tooling

| Command | Purpose |
|---|---|
| `php artisan pkg:list` | Show status of all local packages and symlinks |
| `php artisan pkg:check <pkg>` | Run full quality checks (Pint, PHPStan L8+, Tests) |
| `php artisan pkg:check --all` | Run quality suite across all local packages |
| `php artisan pkg:sync` | Synchronize package dependencies with root `composer.json` |
| `php artisan pkg:readme <pkg>` | Validate package documentation against badge standard |
| `php artisan pkg:release-check <pkg>` | Run pre-flight release-gate audit before tagging (`--fast` for speed) |

---

## 4. Local Packages

<!-- dev-kit:packages-start -->
Run `php artisan pkg:list` to inspect active packages.
<!-- dev-kit:packages-end -->

---

## 5. Development Guidelines & AI Agents

- Agent runbooks, workflows, and strict communication invariants are defined in [`AGENTS.md`](./AGENTS.md).
- Domain skills and release automation procedures are located in [`.agents/skills/`](./.agents/skills/).