[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/cassette)](https://github.com/vielhuber/cassette/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/cassette)](https://github.com/vielhuber/cassette/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/cassette)](https://github.com/vielhuber/cassette/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/cassette)](https://packagist.org/packages/vielhuber/cassette)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/cassette)](https://packagist.org/packages/vielhuber/cassette)

# 📼 cassette 📼

cassette hooks into your PHP application at the lowest level — intercepting database calls and outgoing HTTP requests via [uopz](https://www.php.net/manual/en/book.uopz.php) and serialises every return value to a JSON tape. On replay, the server runs normally but all external I/O is served from that tape, making each test completely self-contained: no database, no network, no side effects. Visual regression is layered on top via Playwright, which navigates through the recorded request sequence and compares screenshots against committed baselines.

## Installation

### Production

```sh
cd /var/www/my-project
composer require --dev vielhuber/cassette`
./vendor/bin/...
```

### Development

```sh
cd /var/www/cassette
export CASSETTE_ROOT=/var/www/my-project
```

### Usage

```sh
./cassette up --php=8.5
./cassette record run_001
./cassette replay run_001 --http-only --screenshot-only
./cassette delete run_001
./cassette list
./cassette down --php=8.5
```

<details>

<summary>Notes</summary>

**Requirements:**

- PHP ≥ 8.3 with the [uopz](https://www.php.net/manual/en/book.uopz.php) extension
- Node.js (for visual screenshot regression — installed automatically on first use)

**Project root resolution.** Every command resolves the project root in this order:

1. `--root=<path>` flag
2. `CASSETTE_ROOT` environment variable
3. Current working directory (default)

So `cd /var/www/my-project && vendor/bin/cassette record run_001` just works without any flags.

**Toggle uopz and inject the bootstrap line** — `up`/`down` enable/disable uopz (incl. the PHP 8.5 patch) and write/remove the `require_once` hook in `wp-config.php` (WordPress) or `public/index.php` (Laravel):

```bash
./cassette up   --php=8.5 --root=/var/www/my-project
./cassette down --php=8.5 --root=/var/www/my-project
```

`.cassette/config.json` is created automatically on the first `vendor/bin/cassette record` call.

## Usage

| Command                                                                                             | Description                                                                           |
| --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `vendor/bin/cassette up [--php=<ver>]`                                                              | Enable uopz, install LD_PRELOAD shim (PHP 8.5), inject bootstrap line                 |
| `vendor/bin/cassette down [--php=<ver>]`                                                            | Reverse `up` — disable uopz and remove bootstrap line                                 |
| `vendor/bin/cassette record <name>`                                                                 | Switch to record mode and clear old data — then click through the flow in the browser |
| `vendor/bin/cassette stop <name>`                                                                   | Stop recording — further requests are no longer captured                              |
| `vendor/bin/cassette replay <name> [--refresh] [--base-url=<url>] [--http-only\|--screenshot-only]` | HTTP diff + visual screenshot comparison; `--refresh` recreates baselines             |
| `vendor/bin/cassette accept <name>`                                                                 | Interactively accept HTTP diffs as new baseline                                       |
| `vendor/bin/cassette delete <name>`                                                                 | Delete a run including all its data and screenshots                                   |
| `vendor/bin/cassette delete --all`                                                                  | Delete all runs                                                                       |
| `vendor/bin/cassette list`                                                                          | List all recorded runs with request count and screenshots                             |

All commands accept `--root=<path>`. If omitted, `CASSETTE_ROOT` (env) is used, falling back to the current working directory.

Exit code `0` = all green, `1` = deviations found. CI-compatible.

All run data is stored in `.cassette/runs/<name>/`. The directory is created on the first `vendor/bin/cassette` invocation with a self-contained `.cassette/.gitignore` that excludes everything (`*`), so recordings stay out of git without touching the project's own `.gitignore`. To track screenshot baselines, replace that file's contents with negation patterns:

```
*
!runs/*/screenshots/
!runs/*/screenshots/*
!.gitignore
```

## Development workflow (working on cassette itself)

When developing cassette alongside a project, you can run the CLI directly from the cassette source directory and point it at the target project — either via `CASSETTE_ROOT` or via `--root`:

```bash
cd /var/www/cassette
export CASSETTE_ROOT=/var/www/my-project
./cassette record run_001
./cassette stop   run_001
./cassette replay run_001
```

Or with an explicit flag:

```bash
./cassette record run_001 --root=/var/www/my-project
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

</details>
