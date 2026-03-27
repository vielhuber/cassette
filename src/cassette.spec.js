'use strict';

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

const cassetteName = process.env.CASSETTE_NAME;
const baseUrlOverride = process.env.CASSETTE_BASE_URL ? process.env.CASSETTE_BASE_URL.replace(/\/$/, '') : null;

if (!cassetteName) {
    throw new Error('CASSETTE_NAME environment variable is required.');
}

const projectRoot = process.env.CASSETTE_ROOT || path.join(__dirname, '../../../..');
const dataDir = path.join(projectRoot, '.cassette/runs');
const runDir = path.join(dataDir, cassetteName);
const httpLogPath = path.join(runDir, 'http.json');
const pointerPath = path.join(runDir, 'data.pointer');

// Load optional project config from the .cassette/ directory.
const configPath = path.join(projectRoot, '.cassette/config.json');
const cassetteConfig = fs.existsSync(configPath) ? JSON.parse(fs.readFileSync(configPath, 'utf8')) : {};
const screenshotConfig = cassetteConfig.screenshot ?? {};
const zoom = screenshotConfig.zoom ?? 0.7;
const maxDiffPixelRatio = screenshotConfig.maxDiffPixelRatio ?? 0.01;
const maskSelectors = screenshotConfig.maskSelectors ?? [];
const timeout = screenshotConfig.timeout ?? 30000;

if (!fs.existsSync(httpLogPath)) {
    throw new Error(`HTTP log not found: ${httpLogPath}. Run record mode first.`);
}

/** @type {Array<{request: object, response: object}>} */
const log = JSON.parse(fs.readFileSync(httpLogPath, 'utf8'));

// Reset the mock pointer before any browser request so the server starts
// serving from bucket 0 — the same order as during the original recording.
test.beforeAll(() => {
    fs.writeFileSync(pointerPath, JSON.stringify({ _request_index: 0 }));
});

// After each test, copy actual/diff PNGs directly into .data/{name}/screenshots/
// so they are visible next to the baselines.
test.afterEach(async ({}, testInfo) => {
    if (testInfo.status === 'passed') {
        return;
    }

    const screenshotsDir = path.join(runDir, 'screenshots');
    fs.mkdirSync(screenshotsDir, { recursive: true });

    for (const attachment of testInfo.attachments) {
        if (!attachment.path) {
            continue;
        }
        if (!attachment.path.endsWith('-actual.png') && !attachment.path.endsWith('-diff.png')) {
            continue;
        }
        const dest = path.join(screenshotsDir, path.basename(attachment.path));
        fs.copyFileSync(attachment.path, dest);
    }
});

// ---------------------------------------------------------------------------
// Visual regression test
//
// All recorded requests are replayed in order as a single test so the server's
// mock pointer stays perfectly aligned with the cassette buckets:
//   GET  → page.goto()          — navigates the browser, takes a screenshot
//   POST → page.context().request.post() — advances the pointer, no screenshot
// ---------------------------------------------------------------------------

test(cassetteName, async ({ page }) => {
    const firstReq = log[0]?.request ?? {};
    const defaultBaseUrl = firstReq.base_url ?? 'http://localhost';

    // Pre-seed auth cookies so the very first request is already authenticated.
    const initialCookies = buildCookies(firstReq.cookies ?? {}, defaultBaseUrl);

    if (initialCookies.length > 0) {
        await page.context().addCookies(initialCookies);
    }

    for (const [index, entry] of log.entries()) {
        const req = entry.request;
        // --base-url overrides the recorded host so the same cassette can be
        // replayed on CI, localhost, staging, etc. without re-recording.
        const baseUrl = baseUrlOverride ?? req.base_url ?? defaultBaseUrl;
        const url = baseUrl + req.uri;

        if (req.method === 'GET') {
            await page.goto(url, { waitUntil: 'networkidle' });

            // Apply zoom, scrollbar fix and masks after the page has fully settled.
            const cssRules = [
                // Hide scrollbars completely so the viewport width stays constant
                // regardless of content height — avoids the ±22px width oscillation
                // that causes toHaveScreenshot() to see unstable layouts.
                `html { zoom: ${zoom}; }`,
                `html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; }`,
                `html, body { scrollbar-width: none; }`,
                ...maskSelectors.map(sel => `${sel} { visibility: hidden !important; }`)
            ];
            await page.addStyleTag({ content: cssRules.join('\n') });

            // Give the browser a moment to re-layout after the style injection.
            await page.waitForTimeout(500);

            // First run (no baseline): Playwright creates the snapshot file.
            // Subsequent runs: Playwright compares and fails on visual diff.
            await expect(page).toHaveScreenshot(`step-${index + 1}.png`, {
                fullPage: true,
                maxDiffPixelRatio,
                timeout
            });
        } else {
            // POST: replay via the page context so the session cookie jar is shared.
            // No screenshot — the only purpose is to advance the server's mock pointer.
            const isRawBody = (req.body ?? '') !== '';
            const body = isRawBody ? req.body : new URLSearchParams(req.post ?? {}).toString();
            const contentType = isRawBody
                ? (req.headers?.['CONTENT-TYPE'] ?? 'application/octet-stream')
                : 'application/x-www-form-urlencoded';

            await page.context().request.post(url, {
                data: body,
                headers: { 'Content-Type': contentType }
            });
        }
    }
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Convert a flat name→value cookies map into Playwright BrowserContext cookie objects.
 *
 * @param {Record<string, string>} cookies
 * @param {string} baseUrl
 * @returns {import('@playwright/test').Cookie[]}
 */
function buildCookies(cookies, baseUrl) {
    const { hostname: domain } = new URL(baseUrl);

    return Object.entries(cookies).map(([name, value]) => ({
        name,
        value: String(value),
        domain,
        path: '/',
        httpOnly: false,
        secure: false,
        sameSite: 'Lax'
    }));
}
