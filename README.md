# 📼 cassette 📼

cassette hooks into your PHP application at the lowest level — intercepting database calls and outgoing HTTP requests via [uopz](https://www.php.net/manual/en/book.uopz.php) and serialises every return value to a JSON tape. On replay, the server runs normally but all external I/O is served from that tape, making each test completely self-contained: no database, no network, no side effects. Visual regression is layered on top via Playwright, which navigates through the recorded request sequence and compares screenshots against committed baselines.

## Installation

```bash
composer require --dev vielhuber/cassette
```

**Requirements:**

- PHP ≥ 8.3 with the [uopz](https://www.php.net/manual/en/book.uopz.php) extension
- Node.js (for visual screenshot regression — installed automatically on first use)

**Bootstrap** — add one line to your entry point (e.g. `wp-config.php`) before any application code runs:

```php
require_once __DIR__ . '/vendor/vielhuber/cassette/src/bootstrap.php';
```

The bootstrap is a no-op when no cassette is active, so it is always safe to keep this line in place.

`.cassette/config.json` is created automatically on the first `vendor/bin/cassette record` call.

## Usage

| Command                                                                                                   | Description                                                                           |
| --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `vendor/bin/cassette record <name>`                                                                       | Switch to record mode and clear old data — then click through the flow in the browser |
| `vendor/bin/cassette stop <name>`                                                                         | Stop recording — further requests are no longer captured                              |
| `vendor/bin/cassette replay <name> [--refresh] [--base-url=<url>] [--http-only\|--screenshot-only]`      | HTTP diff + visual screenshot comparison; `--refresh` recreates baselines             |
| `vendor/bin/cassette accept <name>`                                                                       | Interactively accept HTTP diffs as new baseline                                       |
| `vendor/bin/cassette delete <name>`                                                                       | Delete a run including all its data and screenshots                                   |
| `vendor/bin/cassette delete --all`                                                                        | Delete all runs                                                                       |
| `vendor/bin/cassette list`                                                                                | List all recorded runs with request count and screenshots                             |

Exit code `0` = all green, `1` = deviations found. CI-compatible.

All run data is stored in `.cassette/runs/<name>/`. Screenshot baselines are stored in `.cassette/runs/<name>/screenshots/` and should be committed to git.

Add `.cassette/state.json` to `.gitignore` to avoid committing the active mode flag.

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

Create `.cassette/config.json` to customise screenshot behaviour per project:

```json
{
    "screenshot": {
        "headless": true,
        "zoom": 0.7,
        "maxDiffPixelRatio": 0.01,
        "maskSelectors": [".footer__meta"]
    }
}
```

| Key                            | Default | Description                                                    |
| ------------------------------ | ------- | -------------------------------------------------------------- |
| `screenshot.headless`          | `true`  | Run Playwright in headless mode                                |
| `screenshot.zoom`              | `0.7`   | CSS zoom applied to `<html>` before each screenshot            |
| `screenshot.maxDiffPixelRatio` | `0.01`  | Maximum allowed pixel difference ratio (0–1)                   |
| `screenshot.maskSelectors`     | `[]`    | CSS selectors whose elements are hidden before each screenshot |
