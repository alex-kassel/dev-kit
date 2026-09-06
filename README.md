<h1 align="center">🛠️ DevKit</h1>

<p align="center">
  <strong>Comprehensive development workspace automation and package engineering toolkit for modular Laravel monorepos</strong>
</p>

<p align="center">
  <a href="#key-features">Key Features</a> •
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a> •
  <a href="#usage">Usage</a> •
  <a href="#testing">Testing</a> •
  <a href="CHANGELOG.md">Changelog</a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/alex-kassel/dev-kit"><img src="https://img.shields.io/packagist/v/alex-kassel/dev-kit?color=f59e0b&logo=packagist&logoColor=white" alt="Latest Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?logo=laravel&logoColor=white" alt="Laravel Support"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.3+-777bb4?logo=php&logoColor=white" alt="PHP Support"></a>
  <a href="phpstan.neon"><img src="https://img.shields.io/badge/PHPStan-Level%208-8b5cf6?logo=php&logoColor=white" alt="PHPStan Level 8"></a>
</p>

---

## Key Features

* **Deterministic Workspace Setup (pkg:install):** Prepares host path repositories, gitignore rules, Artisan command shortcuts, and synchronizes agent instructions with optional --force overwrite.
* **Smart Package Generator (pkg:make):** Scaffolds enterprise packages with archetype presets (library, ngine, domain), strict typing, and full test suites.
* **Remote Git Ingestion (pkg:clone):** Clones standalone packages from remote Git repositories directly into the workspace and auto-wires them.
* **Comprehensive Quality Gate (pkg:check):** Executes 4-stage quality verification (Composer validation, Pint style fixing, PHPStan Level 8, PHPUnit tests).
* **Package Inventory (pkg:list):** Scans the workspace, reports versioning and path repository registration status.
* **Automated Monorepo Sync (pkg:sync):** Detects local packages and synchronizes them with root dependencies and path repositories.
* **Standard README Validator (pkg:readme):** Validates enterprise documentation against the standard 7-badge palette and canonical section schema.
* **Release Gate Pre-flight (pkg:release-check):** Verifies working tree cleanliness, independent Git history, audit certificates, and export-ignore directives before release.

---

## Requirements

* **PHP:** 8.3+ (tested on 8.3, 8.4)
* **Laravel Framework:** 11.x | 12.x | 13.x
* **Composer:** 2.2+ (with runtime API 2.1+)

---

## Installation

Require lex-kassel/dev-kit as a development dependency in your Laravel project:

`ash
composer require alex-kassel/dev-kit --dev
`

Initialize your workspace and install agent tooling:

`ash
php artisan pkg:install
`

---

## Usage

### Workspace Preparation & Package Discovery

`ash
# Initialize workspace repositories and agent skills
php artisan pkg:install

# List all local packages and their linking state
php artisan pkg:list

# Synchronize local packages into composer.json
php artisan pkg:sync
`

### Scaffolding & Quality Assurance

`ash
# Scaffold a new library or engine package interactively
php artisan pkg:make my-vendor/my-package --archetype=engine

# Run the complete quality verification suite
php artisan pkg:check alex-kassel/dev-kit

# Automatically fix code style violations
php artisan pkg:check alex-kassel/dev-kit --fix
`

### Documentation & Release Pre-flight

`ash
# Validate README structure against canonical rules
php artisan pkg:readme alex-kassel/dev-kit

# Run pre-flight checks before tagging a release
php artisan pkg:release-check alex-kassel/dev-kit
`

---

## Testing

Run unit and integration test suites:

`ash
vendor/bin/phpunit packages/alex-kassel/dev-kit/tests
`

Or verify using the universal check command:

`ash
php artisan pkg:check alex-kassel/dev-kit
`

---

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for details on changes in recent releases.

---

## License

Proprietary / MIT. Please see [LICENSE](LICENSE) for license details.