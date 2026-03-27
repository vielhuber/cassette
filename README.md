# \_cassette

Record real HTTP flows once, replay them deterministically as regression tests.

## Installation

Add to `wp-config.php` before the "stop editing" comment:

```php
require_once __DIR__ . '/_cassette/src/bootstrap.php';
```

Install Playwright once (only required for visual regression tests):

```bash
cd _cassette && npm install && npx playwright install chromium
```

Copy the example config and adjust as needed:

```bash
cp _cassette/config.example.json _cassette/config.json
```

## Usage

| Command                                                                                           | Description                                                                           |
| ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `php _cassette/cli record <name>`                                                                 | Switch to record mode and clear old data — then click through the flow in the browser |
| `php _cassette/cli stop <name>`                                                                   | Stop recording — further requests are no longer captured                              |
| `php _cassette/cli replay <name> [--refresh] [--base-url=<url>] [--http-only\|--screenshot-only]` | HTTP diff + visual screenshot comparison; `--refresh` recreates baselines             |
| `php _cassette/cli accept <name>`                                                                 | Interactively accept HTTP diffs as new baseline                                       |
| `php _cassette/cli delete <name>`                                                                 | Delete a run including all its data and screenshots                                   |
| `php _cassette/cli delete --all`                                                                  | Delete all runs                                                                       |
| `php _cassette/cli list`                                                                          | List all recorded runs with request count and screenshots                             |

Exit code `0` = all green, `1` = deviations found. CI-compatible.

Screenshot baselines are stored in `.data/<name>/screenshots/` and should be committed to git.

## Portability

Recordings are captured on one host (e.g. `https://custom-tld.dev`) but can be
replayed anywhere — CI, localhost, staging — without re-recording:

```bash
# Replay against a different host than the one used during recording
php _cassette/cli replay run_001 --base-url=http://localhost
```

The `--base-url` flag replaces the host for both the HTTP diff and the Playwright
screenshots. The recorded `base_url` in `http.json` is never modified.

For GitHub Actions, pass the URL as a secret:

```yaml
- name: Replay cassettes
  run: php _cassette/cli replay run_001 --base-url=${{ secrets.APP_URL }}
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

## Configuration

Create `_cassette/config.json` to customise screenshot behaviour per project:

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
