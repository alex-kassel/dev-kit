# Proposal 001: Modular Laravel Development Environment

- **Status**: Accepted (Roadmapped)
- **Author**: Alex Kassel
- **Date**: 2026-09-07
- **Target Component**: `alex-kassel/dev-kit`

---

## 1. Executive Summary

This proposal captures the foundational architectural brief for the modular Laravel development environment, establishes the philosophy of a disposable host application, and defines the prioritization of upcoming features for `alex-kassel/dev-kit`.

---

## 2. Original Architectural Concept Brief

> Below is the verbatim foundational brief formulated for the package architecture.

### Modular Laravel Development Environment
#### Concept & Architectural Foundation

Этот документ описывает концепцию среды разработки, которую необходимо сначала понять, критически проанализировать и при необходимости улучшить. Это НЕ готовая техническая спецификация и НЕ инструкция немедленно начинать реализацию.

Цель — совместно выработать хорошую архитектуру, а затем реализовывать её небольшими, понятными и проверяемыми этапами.

---

### 1. Основная идея

Я хочу построить собственную модульную среду разработки для Laravel-проектов.

Ключевой принцип:

Laravel application — это host/shell, а не место хранения бизнес-логики.

Практически вся значимая прикладная логика должна находиться в Composer packages.

Host предоставляет Laravel runtime и инфраструктуру:

- routing
- database
- queues
- cache
- configuration
- events
- console
- HTTP infrastructure
- testing infrastructure
- frontend infrastructure
- и другие стандартные возможности Laravel

Но сам host не должен становиться монолитным приложением с бизнес-логикой в `app/`.

Идея заключается в том, чтобы package был реальной единицей разработки, а Laravel host — воспроизводимой средой, в которой эти packages собираются и запускаются.

---

### 2. Типы packages

Среда должна одинаково хорошо работать с разными типами packages:

- мои внутренние packages;
- мои reusable packages;
- packages, которые потенциально будут опубликованы в Packagist;
- экспериментальные packages;
- packages, которые используются только одним конкретным проектом.

Разница между ними не должна ломать общий workflow.

Package должен быть полноценной самостоятельной Composer/Laravel единицей.

---

### 3. Структура workspace

Packages хранятся внутри host:

`packages/{vendor}/{package}`

Например:

```
packages/
    alex-kassel/
        users/
        billing/
        notifications/
    my-company/
        payments/
```

Каждый package является отдельным Git repository.

Host repository не владеет содержимым этих packages.

Host должен игнорировать package directories через Git.

Например, концептуально:

`packages/*/*`

---

### 4. Composer integration

Host должен автоматически иметь Composer path repository:

```json
{
    "type": "path",
    "url": "packages/*/*",
    "options": {
        "symlink": true
    }
}
```

Этот механизм уже проверен и работает.

Пользователь НЕ должен вручную редактировать host composer.json после создания среды.

Dev-kit должен подготовить необходимую инфраструктуру автоматически.

Packages во время workspace development используют единый центральный `vendor/` host.

В нормальном workspace не должно быть множества локальных:

`packages/foo/bar/vendor/`

---

### 5. Laravel Package Discovery

Packages должны интегрироваться с Laravel стандартным способом.

В частности, package может объявлять свой ServiceProvider через:

`extra.laravel.providers`

Package самостоятельно отвечает за регистрацию своих Laravel-компонентов.

Например, migrations должны загружаться самим package через ServiceProvider:

`loadMigrationsFrom(...)`

Таким образом host не должен знать расположение migrations каждого package.

После подключения packages пользователь должен иметь возможность выполнять обычные Laravel-команды, например:

`php artisan migrate`

---

### 6. Package как самостоятельная единица

Хотя workspace использует общий host и общий vendor, package концептуально должен оставаться независимым.

Package должен содержать собственный:

- composer.json
- source code
- tests
- Laravel ServiceProvider, если необходим
- migrations, если необходимы
- configuration, если необходима
- resources, если необходимы
- README/documentation
- собственные package dependencies

Package должен быть потенциально пригоден для дальнейшей публикации и использования отдельно от этого workspace.

Это означает, что workspace mode и standalone package mode — разные режимы работы, а не противоречащие друг другу требования.

В workspace тестирование обычно централизованное.

Standalone package должен оставаться теоретически способным установить собственные dev dependencies и пройти свои тесты независимо.

---

### 7. Dependency localization

Это одна из центральных возможностей среды.

Если я выполняю:

`php artisan package:clone vendor/package`

система должна не просто клонировать один repository.

Она должна:

1. найти package;
2. клонировать его локально;
3. прочитать его composer.json;
4. определить его dependencies;
5. определить, какие dependencies принадлежат моим организациям;
6. рекурсивно локализовать такие dependencies;
7. после завершения привести Composer workspace в рабочее состояние.

Пример:

```
A
└── my-company/B
    └── my-company/C
        └── my-company/D
```

При:

`php artisan package:clone A`

ожидается локализация:

`A`, `B`, `C`, `D`

Если:

```
A
├── my-company/B
│   └── my-company/C
│       └── spatie/X
└── spatie/Y
```

то локализуются:

`A`, `B`, `C`

а:

`spatie/X`, `spatie/Y`

не клонируются автоматически.

---

### 8. Определение "моих" packages

В первой версии используется простое правило:

Package считается моим, если его Composer vendor/organization входит в список моих организаций.

Например:

```yaml
organizations:
    - alex-kassel
    - my-company
    - another-company
```

Тогда:

```
alex-kassel/foo       → local
my-company/bar        → local
another-company/baz   → local
spatie/laravel-x      → remote
laravel/framework     → remote
```

Все мои packages в первой версии локализуются всегда.

Неважно, dependency constraint выглядит как:

- `^1.0`
- `^2.4`
- `dev-main`
- `dev-feature`
- и т.д.

Если package принадлежит моей организации, первая версия среды должна стремиться локализовать его.

В дальнейшем dependency localization policy может стать более гибкой, но в первой версии её намеренно не следует переусложнять.

Список организаций должен находиться в конфигурации среды, а не быть hardcoded в PHP-коде.

---

### 9. Source repositories

На данном этапе GitHub является основным/default source.

Но архитектура не должна быть построена вокруг предположения:

`vendor == GitHub organization`

или вокруг жёстко зашитого:

`git@github.com:{vendor}/{package}.git`

Источник package должен быть абстрагирован.

GitHub — текущая реализация/default, а не фундаментальная архитектурная зависимость.

В будущем должны оставаться возможными другие Git/VCS sources.

---

### 10. Ошибки при локализации

Система не должна молча менять стратегию.

Например, если package:

`my-company/payment-api`

должен быть локализован, но repository не найден, система должна явно сообщить об ошибке.

Она НЕ должна молча:

- использовать Packagist;
- брать другую версию;
- пропускать dependency;
- переключать source;
- делать какой-либо другой fallback.

Предпочтительное поведение первой версии:

операция останавливается с понятной диагностикой.

---

### 11. Уже существующий local package

Если dependency должна быть локализована, но package уже существует локально:

`packages/my-company/B`

повторно клонировать её нельзя.

Если существующий local package совместим с требуемой dependency constraint — используется существующий package.

Если он НЕ удовлетворяет constraint, операция должна завершиться ошибкой.

Например:

`my-company/B requires ^2.0`
`local B is 1.x`

Система должна сообщить об этом явно.

Она не должна молча:

- переключать branch;
- менять версию;
- удалять package;
- брать remote package вместо local;
- выполнять другое неожиданное действие.

---

### 12. Dependency graph

Recursive localization должна корректно работать с:

- глубокими dependency chains;
- уже локализованными packages;
- повторяющимися dependencies;
- dependency cycles.

Например:

`A → B → C → A`

не должно приводить к бесконечной рекурсии или повторному клонированию.

Resolver должен иметь понятное понятие уже посещённых packages.

---

### 13. Composer dependency graph и workspace graph

Необходимо различать:

`Composer dependency graph`

и

`workspace graph`.

Composer отвечает за dependency resolution.

Workspace отвечает за вопрос:

"Какие packages сейчас должны физически находиться локально?"

`workspace.yaml`, если он будет использоваться, не должен становиться второй системой dependency resolution и не должен дублировать Composer.

Он может хранить информацию, которую Composer/Git не знают, например:

- какие repositories пользователь хочет восстановить;
- workspace-specific state;
- developer-specific settings;
- активные branches;
- другие данные, необходимые для восстановления workspace.

---

### 14. Git model

Каждый package — самостоятельный Git repository.

Host — отдельный Git repository.

Host не должен коммитить содержимое packages.

Это позволяет:

- независимо развивать packages;
- переиспользовать их в разных hosts;
- публиковать packages отдельно;
- уничтожать и пересоздавать host;
- переносить workspace между машинами.

---

### 15. Disposable host

Host должен рассматриваться как воспроизводимая оболочка.

Идеальный сценарий:

1. создать чистый Laravel host;
2. установить dev-kit;
3. восстановить/подключить необходимые packages;
4. Composer собирает workspace;
5. Laravel запускается.

Если host уничтожить и создать заново, packages и их repositories не должны от этого потеряться.

Долгоживущими активами являются packages и их Git repositories.

---

### 16. Dev-kit

На новой машине пользователь должен иметь возможность создать чистый Laravel host и затем установить специальный development package.

Концептуально:

`composer require vendor/dev-kit --dev`

затем:

`php artisan dev-kit:install`

Dev-kit должен подготовить:

- package directory;
- Composer path repository;
- необходимые development configuration;
- testing infrastructure;
- CLI infrastructure;
- другие необходимые элементы workspace.

Dev-kit НЕ должен автоматически клонировать все packages пользователя.

Подключение packages должно происходить явно.

---

### 17. CLI layer

Среда должна иметь собственный CLI API.

Примеры:

- `package:create`
- `package:clone`
- `package:remove`
- `package:link`
- `package:list`
- `package:test`
- `package:audit`

Точный набор команд ещё предстоит определить.

Важно, что CLI должен быть стабильным интерфейсом среды.

Детерминированные операции должны быть реализованы здесь, а не каждый раз вручную агентом.

Например:

- clone repository
- создать directory
- проверить package
- обновить Composer
- запустить package tests

должны быть воспроизводимыми командами.

---

### 18. JSON output

CLI должен по возможности поддерживать machine-readable output:

`--json`

Это позволит AI agent и другим инструментам безопасно взаимодействовать с environment.

Например:

`php artisan package:list --json`

может возвращать структурированные данные вместо необходимости парсить human-readable текст.

JSON API не обязательно реализовывать во всех командах в самом первом прототипе, но это должно быть частью архитектурного направления.

---

### 19. Testing

В workspace предпочтителен централизованный testing model.

Host содержит общую testing infrastructure.

Package tests могут запускаться из host через общий vendor.

Например:

`php artisan package:test vendor/package`

или через центральный PHPUnit configuration.

Не нужно создавать отдельный vendor directory для каждого package во время обычной workspace-разработки.

При этом package должен оставаться самостоятельно тестируемым вне workspace.

---

### 20. Package boundary

Нужен механизм проверки package boundaries.

Package не должен случайно зависеть от внутренних деталей host.

Возможные инструменты/механизмы:

- composer-require-checker;
- dependency analysis;
- clean-install validation;
- собственные audit rules.

Один инструмент не обязан решать всю задачу.

Цель:

package должен явно декларировать свои реальные зависимости и не использовать случайно доступные зависимости host.

---

### 21. Frontend

Некоторые packages могут содержать frontend resources.

Vite/frontend infrastructure должна поддерживать package resources и aliases.

Но frontend architecture не должна преждевременно становиться слишком жёсткой.

Не каждый package обязан иметь frontend.

---

### 22. AI Agent

AI agent является не частью фундаментальной инфраструктуры, а заменяемым orchestration/intelligence layer.

AI должен использовать существующую environment infrastructure.

Если есть команда:

`package:clone`

агенту предпочтительно вызвать её, а не самостоятельно реализовывать:

- filesystem operations;
- git clone;
- directory creation;
- Composer setup;
- dependency localization.

При этом AI должен свободно заниматься интеллектуальной разработкой:

- архитектура;
- implementation;
- classes;
- services;
- controllers;
- DTOs;
- events;
- tests;
- debugging;
- refactoring;
- documentation.

То есть правило не такое:

"AI никогда не может изменять файлы."

Правило такое:

"Для детерминированной операции, для которой существует официальный CLI механизм среды, AI должен использовать этот механизм."

Если AI заменить другим агентом или вообще убрать, среда должна продолжать работать.

---

### 23. Главный принцип AI integration

CLI и документация должны быть понятнее и стабильнее, чем конкретный AI agent.

AI — потребитель environment API.

Не environment должна зависеть от конкретного AI.

Это позволяет:

- заменить AI;
- использовать несколько AI;
- выполнять операции вручную;
- автоматизировать операции другими инструментами.

---

### 24. Первая версия намеренно НЕ должна решать всё

Не нужно сразу реализовывать:

- сложную dependency policy;
- dependency localization exceptions;
- несколько типов remote sources;
- сложный workspace state;
- сложную branch management automation;
- интеллектуальный AI orchestration;
- все возможные frontend scenarios;
- универсальную distributed development system.

Первая версия должна быть простой и предсказуемой.

Главное правило localization:

"Если package принадлежит одной из моих организаций — локализовать его."

Если не принадлежит — не локализовать автоматически.

---

### 25. Предлагаемая философия разработки

Разработка этой среды должна идти маленькими этапами.

Не следует сразу создавать огромную систему.

Перед реализацией каждого крупного этапа необходимо:

1. понять существующую архитектуру;
2. проверить предположения;
3. предложить техническое решение;
4. показать, какие файлы будут изменены;
5. объяснить, как это будет проверено;
6. реализовать небольшой законченный шаг;
7. запустить проверки;
8. показать результат;
9. только после этого переходить дальше.

Если архитектурное решение вызывает сомнения — сначала обсуждать его, а не прятать решение внутри большого implementation.

---

### 26. Что должен сделать агент после изучения этого документа

Не начинать немедленно писать весь проект.

Сначала:

1. проанализировать концепцию;
2. указать противоречия, слабые места и технические риски;
3. предложить улучшения;
4. выделить решения, которые ещё требуют обсуждения;
5. предложить поэтапный roadmap реализации;
6. определить минимальный первый технический эксперимент/prototype.

После этого реализация должна идти маленькими контролируемыми шагами.

Этот документ является исходной архитектурной базой, а не окончательной спецификацией.

---

## 3. Critical Architecture Analysis & Engineering Resolutions

### 3.1 Resolving Dependencies Without Reinventing Composer (Avoid Solver Duplication)
- **Problem**: In Git, there is no direct mapping from a SemVer range (e.g. `^1.0`) to a Git reference (branches/commits). Attempting to build custom SAT solvers or version negotiation inside `dev-kit` violates single responsibility and creates maintenance fragility.
- **Resolution**:
  1. For Git cloning: when an owned dependency specifies a release constraint (e.g. `^1.0`), `dev-kit` clones the default repository branch (e.g. `main` / `master`) unless an explicit branch override is supplied.
  2. For Composer resolution: root `composer.json` registers local checkouts under path repository with `@dev` version constraint. Composer's internal solver links the local worktree via symlink cleanly.
  3. For compatibility verification: use official `composer/semver` (`Semver::satisfies()`) to inspect the local package's declared version / tag against the caller's constraint rather than home-grown string parsing.

### 3.2 Package Boundary Isolation (Phantom Dependency Elimination)
- **Problem**: Monorepos with a shared host `vendor/` conceal missing dependencies in package `composer.json` manifests. If a package references classes from packages required only by the host, tests pass locally but break in production for external consumers.
- **Resolution**:
  - Integrate `maglnet/composer-require-checker` into `php artisan pkg:check <pkg>`.
  - Parse AST of package source files and verify all imported symbols are declared directly in the package's own `require` or `require-dev`.

### 3.3 Default Branch Autodetection
- **Problem**: Forcing an explicit `--branch` parameter fails if the user or agent does not know whether a remote repository uses `main`, `master`, or another trunk.
- **Resolution**:
  - Remove mandatory requirement for `--branch`. Git natively resolves remote `HEAD` when `--branch` is omitted.

### 3.4 Implemented Reliability Decisions

The namespace-regex approximation has been removed. P2.1 now implements a separate Composer installation and executable test run using a current-checkout export that retains tests. This supersedes the earlier proposed require-checker integration in section 3.2; direct-symbol declaration analysis remains outside this test-based guarantee.

Version preflight uses only the current checkout's explicit version, clean HEAD tags and current branch aliases. It does not select historical releases or negotiate a dependency solution. The final decision remains with Composer.

Removal validates canonical package paths and requires positive Git evidence before deleting without force. Individual manifest writes use Symfony Filesystem atomic replacement. Host dependency installation is still managed explicitly by Composer.
