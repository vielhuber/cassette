[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/cassette)](https://github.com/vielhuber/cassette/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/cassette)](https://github.com/vielhuber/cassette/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/cassette)](https://github.com/vielhuber/cassette/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/cassette)](https://packagist.org/packages/vielhuber/cassette)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/cassette)](https://packagist.org/packages/vielhuber/cassette)

# 📼 cassette 📼

cassette hooks into your PHP application at the lowest level — intercepting database calls and outgoing HTTP requests via [uopz](https://www.php.net/manual/en/book.uopz.php) and serialises every return value to a JSON tape. On replay, the server runs normally but all external I/O is served from that tape, making each test completely self-contained: no database, no network, no side effects. Visual regression is layered on top via Playwright, which navigates through the recorded request sequence and compares screenshots against committed baselines.

## Installation

```bash
composer require --dev vielhuber/cassette
```

**Requirements:**

- PHP ≥ 8.3 with the [uopz](https://www.php.net/manual/en/book.uopz.php) extension
- Node.js (for visual screenshot regression — installed automatically on first use)

> **uopz should only be enabled temporarily** — disable it in production and re-enable it only when recording or replaying. All required settings are toggled via a single conf.d override file (`zzz-cassette.ini`):

```bash
PHP_VER=8.1  # adjust to your PHP version

# enable (the sed line removes any legacy pool config entries from older installations)
printf '[uopz]\nuopz.exit = 1\n[opcache]\nopcache.enable = 0\n[xdebug]\nxdebug.mode = off\n[blackfire]\nblackfire.apm_enabled = 0\n' | sudo tee /etc/php/$PHP_VER/fpm/conf.d/zzz-cassette.ini
sudo phpenmod -v $PHP_VER uopz && sudo systemctl restart php$PHP_VER-fpm

# disable
sudo rm -f /etc/php/$PHP_VER/fpm/conf.d/zzz-cassette.ini
sudo phpdismod -v $PHP_VER uopz && sudo systemctl restart php$PHP_VER-fpm
```

> - `uopz.exit = 1` — restores normal exit semantics (the default `0` silently suppresses all `exit()` / `die()` calls)
> - `opcache.enable = 0` — avoids a [known uopz + opcache incompatibility](https://github.com/krakjoe/uopz/pull/132) that can corrupt interned strings (symptom: `preg_match_all(): Null byte in regex`)
> - `xdebug.mode = off` — Xdebug and uopz both instrument bytecodes at the Zend engine level and conflict with each other; `xdebug.mode` is a `PHP_INI_SYSTEM` setting that cannot be overridden via `php_admin_value` in the pool config, so all settings live in `zzz-cassette.ini`; the `zzz-` prefix ensures the file sorts after any `custom.ini` in the same conf.d directory
> - `blackfire.apm_enabled = 0` — Blackfire APM also instruments the Zend engine and can trigger the same null-byte corruption in combination with uopz

**Bootstrap** — add one line to your entry point before any application code runs:

```php
require_once __DIR__ . '/vendor/vielhuber/cassette/src/bootstrap.php';
```

The bootstrap is a no-op when no cassette is active, so it is always safe to keep this line in place.

**Laravel** — add the line to `public/index.php`, right before the first `require` statement (i.e. before the autoloader):

```php
<?php

// ...

require_once __DIR__.'/../vendor/vielhuber/cassette/src/bootstrap.php';

require __DIR__.'/../bootstrap/autoload.php'; // or vendor/autoload.php in newer Laravel versions
```

The path is `__DIR__.'/../vendor/...'` because `public/index.php` sits one level below the project root. `public/index.php` is the earliest possible hook point in Laravel — uopz overrides are in place before the autoloader, the service container, and any service providers are initialised.

**Development tip** — when working on cassette itself alongside a project, point the `require_once` directly at the cassette source directory instead of the installed vendor copy. The bootstrap detects the project root via `SCRIPT_FILENAME`, so this works correctly even from a non-vendor path:

```php
// use live cassette source in dev, installed package everywhere else
require_once file_exists('/var/www/cassette/src/bootstrap.php')
    ? '/var/www/cassette/src/bootstrap.php'
    : __DIR__.'/../vendor/vielhuber/cassette/src/bootstrap.php';
```

No file copying or symlinks needed. Changes to cassette's source take effect immediately on the next request.

**WordPress** — add the line to `wp-config.php`, before `require_once ABSPATH . 'wp-settings.php';`:

```php
require_once __DIR__ . '/vendor/vielhuber/cassette/src/bootstrap.php';
```

`.cassette/config.json` is created automatically on the first `vendor/bin/cassette record` call.

## Usage

| Command                                                                                             | Description                                                                           |
| --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `vendor/bin/cassette record <name>`                                                                 | Switch to record mode and clear old data — then click through the flow in the browser |
| `vendor/bin/cassette stop <name>`                                                                   | Stop recording — further requests are no longer captured                              |
| `vendor/bin/cassette replay <name> [--refresh] [--base-url=<url>] [--http-only\|--screenshot-only]` | HTTP diff + visual screenshot comparison; `--refresh` recreates baselines             |
| `vendor/bin/cassette accept <name>`                                                                 | Interactively accept HTTP diffs as new baseline                                       |
| `vendor/bin/cassette delete <name>`                                                                 | Delete a run including all its data and screenshots                                   |
| `vendor/bin/cassette delete --all`                                                                  | Delete all runs                                                                       |
| `vendor/bin/cassette list`                                                                          | List all recorded runs with request count and screenshots                             |

Exit code `0` = all green, `1` = deviations found. CI-compatible.

All run data is stored in `.cassette/runs/<name>/`. Screenshot baselines are stored in `.cassette/runs/<name>/screenshots/` and should be committed to git.

If runs should **not** be tracked in git at all, add the entire directory to `.gitignore`:

```
/.cassette/
```

## Development workflow (working on cassette itself)

When developing cassette alongside a project, you can run the CLI directly from the cassette source directory and point it at the target project via `--root`:

```bash
cd /var/www/cassette
./cassette record run_001 --root=/var/www/my-project
./cassette stop   run_001 --root=/var/www/my-project
./cassette replay run_001 --root=/var/www/my-project
```

This way the target project's `composer.json` stays completely untouched — no path-repository, no `minimum-stability`, no symlinks. All cassette data is read from and written to `/var/www/my-project/.cassette/` as usual.

## Portability

Recordings are captured on one host (e.g. `https://custom-tld.dev`) but can be
replayed anywhere — CI, localhost, staging — without re-recording:

```bash
# Replay against a different host than the one used during recording
vendor/bin/cassette replay run_001 --base-url=http://localhost
```

The `--base-url` flag replaces the host for both the HTTP diff and the Playwright
screenshots. The recorded `base_url` in `http.json` is never modified.

For GitHub Actions, pass the URL as a secret:

```yaml
- name: Replay cassettes
  run: vendor/bin/cassette replay run_001 --base-url=${{ secrets.APP_URL }}
```

### No database required for replay

During replay, all database queries and outgoing HTTP calls are intercepted by
[uopz](https://www.php.net/manual/en/book.uopz.php) and served from the recorded
cassette data. The server must be running and reachable, but:

- **no real database connection is needed** — the DB state at replay time is completely irrelevant
- **no test fixtures or seed data** are required
- external APIs and curl calls are mocked the same way

This makes cassette replays safe to run on CI, on a fresh machine, or against a
server whose database is empty, stale, or even offline.

### curl interception

To intercept `__::curl()` calls, add [`vielhuber/stringhelper`](https://github.com/vielhuber/stringhelper) to your project:

```bash
composer require vielhuber/stringhelper
```

Without it, cassette still records and replays all database calls — curl interception is simply skipped.

## Configuration

Create `.cassette/config.json` to customise recording and screenshot behaviour per project:

```json
{
    "ignoreUrls": [],
    "screenshot": {
        "headless": true,
        "zoom": 0.7,
        "maxDiffPixelRatio": 0.01,
        "maskSelectors": [],
        "maskDates": true,
        "timeout": 60000,
        "waitAfterGoto": 2000
    }
}
```

| Key                            | Default | Description                                                                                                                                                                                         |
| ------------------------------ | ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ignoreUrls`                   | `[]`    | List of URI substrings — any HTTP request whose path contains one of these strings is silently skipped during recording (not written to `http.json`)                                                |
| `screenshot.headless`          | `true`  | Run Playwright in headless mode                                                                                                                                                                     |
| `screenshot.zoom`              | `0.7`   | CSS zoom applied to `<html>` before each screenshot                                                                                                                                                 |
| `screenshot.maxDiffPixelRatio` | `0.01`  | Maximum allowed pixel difference ratio (0–1)                                                                                                                                                        |
| `screenshot.maskSelectors`     | `[]`    | CSS selectors whose elements are hidden before each screenshot (uses direct DOM manipulation, so `position: fixed` elements are reliably hidden)                                                    |
| `screenshot.maskDates`         | `true`  | Automatically hide all date and time values in the page (ISO dates `2026-03-29`, German dates `29.03.2026`, times `12:34` / `12:34:56`) including `<input type="date">` values and plain text nodes |
| `screenshot.waitAfterGoto`     | `0`     | Extra milliseconds to wait after `networkidle` before taking the screenshot — useful when JS-rendered content (e.g. lazy-loaded tables) needs extra time to paint after the network goes idle       |
