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
const maskDates = screenshotConfig.maskDates ?? true;
const timeout = screenshotConfig.timeout ?? 30000;
// Extra wait after networkidle to give JS-rendered content (lazy-loaded tables etc.) time to paint.
const waitAfterGoto = screenshotConfig.waitAfterGoto ?? 0;

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

// After each test, copy actual/diff PNGs directly into .cassette/runs/{name}/screenshots/
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
//
// Problem: when page.goto() navigates to a page, the browser may automatically
// fire JS-initiated AJAX requests (e.g. lazy-load calls) that also appear as
// explicit future steps in the log. Those browser-initiated requests would
// advance the PHP mock pointer prematurely, causing the subsequent explicit
// step to read the wrong cassette bucket (returning null instead of real data).
//
// Fix: before each page.goto(), register page.route() handlers for all
// future log entries' URLs. The browser-initiated sub-requests are served
// directly from the recorded response — bypassing PHP entirely — so the
// pointer is NOT advanced. The explicit loop step later navigates to that URL
// normally, hitting PHP with the correct pointer value.
// ---------------------------------------------------------------------------

test(cassetteName, async ({ page }) => {
    const firstReq = log[0]?.request ?? {};
    const defaultBaseUrl = firstReq.base_url ?? 'http://localhost';

    // Debug logging — uncomment to trace pointer drift and network activity.
    // page.on('request', (request) => {
    //     const resourceType = request.resourceType();
    //     const isNav = request.isNavigationRequest();
    //     console.log(`[browser-request] ${request.method()} ${request.url()} | type=${resourceType} nav=${isNav}`);
    // });
    // page.on('response', async (response) => {
    //     const status = response.status();
    //     const url = response.url();
    //     // Log only same-origin responses to avoid noise from CDN/static assets.
    //     if (url.includes(defaultBaseUrl.replace(/^https?:\/\//, ''))) {
    //         let body = '';
    //         try { body = (await response.text()).substring(0, 120).replace(/\s+/g, ' '); } catch (_) {}
    //         console.log(`[browser-response] ${status} ${url} | body_preview: ${body}`);
    //     }
    // });

    // Pre-seed auth cookies so the very first request is already authenticated.
    const initialCookies = buildCookies(firstReq.cookies ?? {}, defaultBaseUrl);

    if (initialCookies.length > 0) {
        await page.context().addCookies(initialCookies);
    }

    // Handlers registered in the previous step — kept active so that delayed
    // JS-initiated sub-requests (e.g. lazy-load fetches that fire after networkidle)
    // are still intercepted. Unrouted at the START of the next iteration.
    let previousHandlers = [];

    for (const [index, entry] of log.entries()) {
        const req = entry.request;
        // --base-url overrides the recorded host so the same cassette can be
        // replayed on CI, localhost, staging, etc. without re-recording.
        const baseUrl = baseUrlOverride ?? req.base_url ?? defaultBaseUrl;
        const url = baseUrl + req.uri;

        // Unroute the previous step's handlers now that this new step is
        // about to register its own routes. Any delayed sub-requests from the
        // previous step that fired between the last goto and here were still
        // intercepted because the previous handlers remained active.
        for (const { handlerUrl, handler } of previousHandlers) {
            await page.unroute(handlerUrl, handler);
        }
        previousHandlers = [];

        if (req.method === 'GET') {
            // Tracks which URLs already have a handler so we never register
            // two handlers for the same URL (Playwright would only call the
            // last one, which could be the wrong cassette entry).
            const registeredUrls = new Set();
            const currentHandlers = [];

            // --- self-route for the current URL ----------------------------
            // Handles navigation for this step (→ continue to PHP) AND any
            // post-load sub-requests to the same URL that fire after goto (→
            // fulfill from the recorded response so the PHP pointer is not
            // advanced a second time). This also covers the last step in the
            // log which has no "future" entry for its own URL.
            const selfResponse = entry.response;
            const selfHandler = async (route, request) => {
                if (request.isNavigationRequest()) {
                    // console.log(`[route] SELF NAV → continue  step=${index} url=${request.url()}`);
                    await route.continue();
                } else {
                    // console.log(`[route] SELF SUB → fulfill   step=${index} url=${request.url()} status=${selfResponse.status ?? 200}`);
                    await route.fulfill({
                        status: selfResponse.status ?? 200,
                        headers: parseResponseHeaders(selfResponse.headers ?? []),
                        body: selfResponse.body ?? ''
                    });
                }
            };
            // console.log(`[route] register SELF step=${index} url=${url}`);
            await page.route(url, selfHandler);
            currentHandlers.push({ handlerUrl: url, handler: selfHandler });
            registeredUrls.add(url);

            // --- future routes (first occurrence per URL) ------------------
            // Intercept browser-initiated sub-requests to URLs that will be
            // explicitly navigated in a future step so they don't hit PHP and
            // advance the mock pointer prematurely.
            for (let futureIndex = index + 1; futureIndex < log.length; futureIndex++) {
                const futureEntry = log[futureIndex];
                if (futureEntry.request.method !== 'GET') {
                    continue;
                }
                const futureBase = baseUrlOverride ?? futureEntry.request.base_url ?? defaultBaseUrl;
                const futureUrl = futureBase + futureEntry.request.uri;

                // Skip duplicate URLs — only the first cassette entry wins.
                if (registeredUrls.has(futureUrl)) {
                    continue;
                }
                registeredUrls.add(futureUrl);

                const recordedResponse = futureEntry.response;

                const handler = async (route, request) => {
                    const isNav = request.isNavigationRequest();
                    if (isNav) {
                        // console.log(`[route] NAV → continue  step=${index} future=${futureIndex} url=${request.url()}`);
                        await route.continue();
                        return;
                    }
                    // console.log(`[route] SUB → fulfill   step=${index} future=${futureIndex} url=${request.url()} status=${recordedResponse.status ?? 200}`);
                    await route.fulfill({
                        status: recordedResponse.status ?? 200,
                        headers: parseResponseHeaders(recordedResponse.headers ?? []),
                        body: recordedResponse.body ?? ''
                    });
                };

                // console.log(`[route] register step=${index} future=${futureIndex} url=${futureUrl}`);
                await page.route(futureUrl, handler);
                currentHandlers.push({ handlerUrl: futureUrl, handler });
            }

            // Keep handlers active after goto so delayed fetches are caught.
            // They will be unrouted at the start of the next loop iteration.
            previousHandlers = currentHandlers;

            // Pointer tracking — uncomment to verify pointer alignment.
            // const pointerBefore = fs.existsSync(pointerPath)
            //     ? JSON.parse(fs.readFileSync(pointerPath, 'utf8'))._request_index
            //     : '(missing)';
            // console.log(`\n[step ${index}] GET ${url} | pointer_before=${pointerBefore}`);

            console.log(`  #${index + 1}  GET   ${url}`);
            await page.goto(url, { waitUntil: 'networkidle' });
            if (waitAfterGoto > 0) {
                await page.waitForTimeout(waitAfterGoto);
            }

            // const pointerAfter = fs.existsSync(pointerPath)
            //     ? JSON.parse(fs.readFileSync(pointerPath, 'utf8'))._request_index
            //     : '(missing)';
            // console.log(`[step ${index}] goto done | pointer_after=${pointerAfter} | expected_pointer_after=${index + 1}`);
            // if (pointerAfter !== index + 1) {
            //     console.warn(`[step ${index}] WARNING: pointer mismatch! got ${pointerAfter}, expected ${index + 1}`);
            // }

            // Apply zoom and scrollbar fix via stylesheet.
            await page.addStyleTag({ content: [
                `html { zoom: ${zoom}; }`,
                // Hide scrollbars completely so the viewport width stays constant
                // regardless of content height — avoids the ±22px width oscillation
                // that causes toHaveScreenshot() to see unstable layouts.
                `html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; }`,
                `html, body { scrollbar-width: none; }`
            ].join('\n') });

            // Apply maskSelectors via direct DOM manipulation so that position:fixed
            // elements are reliably hidden even in full-page screenshots.
            if (maskSelectors.length > 0) {
                await page.evaluate((selectors) => {
                    for (const sel of selectors) {
                        document.querySelectorAll(sel).forEach(el => {
                            el.style.setProperty('visibility', 'hidden', 'important');
                        });
                    }
                }, maskSelectors);
            }

            // Mask date/time text throughout the page to prevent screenshot diffs
            // caused by dynamically generated dates (e.g. today's date as default value).
            // Disable via maskDates: false in .cassette/config.json.
            if (maskDates) {
                await page.evaluate(() => {
                    // Matches: ISO date (2026-03-29), German date (29.03.2026), time (12:34 or 12:34:56),
                    // runtime seconds (0.52s or 0,52s as used in footer render-time display)
                    const PATTERN = /\b(\d{4}-\d{2}-\d{2}|\d{2}\.\d{2}\.\d{4}|\d{1,2}:\d{2}(?::\d{2})?|\d+[.,]\d+s)\b/g;

                    // Hide input[type="date"] value displays
                    document.querySelectorAll('input[type="date"]').forEach(el => {
                        el.style.setProperty('visibility', 'hidden', 'important');
                    });

                    // Walk all text nodes and wrap matched date/time spans with visibility:hidden
                    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
                    const textNodes = [];
                    let node;
                    while ((node = walker.nextNode())) {
                        if (PATTERN.test(node.nodeValue)) textNodes.push(node);
                    }
                    for (const textNode of textNodes) {
                        const parent = textNode.parentNode;
                        if (!parent || parent.tagName === 'SCRIPT' || parent.tagName === 'STYLE') continue;
                        PATTERN.lastIndex = 0;
                        const fragment = document.createDocumentFragment();
                        let lastIndex = 0;
                        let match;
                        while ((match = PATTERN.exec(textNode.nodeValue)) !== null) {
                            if (match.index > lastIndex) {
                                fragment.appendChild(document.createTextNode(textNode.nodeValue.slice(lastIndex, match.index)));
                            }
                            const mask = document.createElement('span');
                            // Use a space-preserving invisible placeholder of similar width
                            mask.style.cssText = 'visibility:hidden!important;display:inline-block!important;white-space:pre!important;';
                            mask.textContent = match[0].replace(/./g, '\u2007'); // figure spaces
                            fragment.appendChild(mask);
                            lastIndex = match.index + match[0].length;
                        }
                        if (lastIndex < textNode.nodeValue.length) {
                            fragment.appendChild(document.createTextNode(textNode.nodeValue.slice(lastIndex)));
                        }
                        parent.replaceChild(fragment, textNode);
                    }
                });
            }

            // Give the browser a moment to re-layout after the DOM modifications.
            await page.waitForTimeout(500);

            // First run (no baseline): Playwright creates the snapshot file.
            // Subsequent runs: Playwright compares and fails on visual diff.
            try {
                await expect(page).toHaveScreenshot(`step-${index + 1}.png`, {
                    fullPage: true,
                    maxDiffPixelRatio,
                    timeout
                });
            } catch (_e) {
                const diffPath = path.join(runDir, 'screenshots', `step-${index + 1}-diff.png`);
                console.log(`  ✘  Diff: ${diffPath}`);
                throw new Error('screenshot diff');
            }
        } else {
            // const pointerBefore = fs.existsSync(pointerPath)
            //     ? JSON.parse(fs.readFileSync(pointerPath, 'utf8'))._request_index
            //     : '(missing)';
            // console.log(`\n[step ${index}] POST ${url} | pointer_before=${pointerBefore}`);

            // POST: replay via the page context so the session cookie jar is shared.
            // No screenshot — the only purpose is to advance the server's mock pointer.
            console.log(`  #${index + 1}  POST  ${url}`);
            const isRawBody = (req.body ?? '') !== '';
            const body = isRawBody ? req.body : new URLSearchParams(req.post ?? {}).toString();
            const contentType = isRawBody
                ? (req.headers?.['CONTENT-TYPE'] ?? 'application/octet-stream')
                : 'application/x-www-form-urlencoded';

            await page.context().request.post(url, {
                data: body,
                headers: { 'Content-Type': contentType }
            });

            // const pointerAfter = fs.existsSync(pointerPath)
            //     ? JSON.parse(fs.readFileSync(pointerPath, 'utf8'))._request_index
            //     : '(missing)';
            // console.log(`[step ${index}] post done | pointer_after=${pointerAfter} | expected_pointer_after=${index + 1}`);
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

/**
 * Convert headers_list() output ("Name: value" strings) into a plain object
 * suitable for Playwright's route.fulfill({ headers }).
 *
 * @param {string[]} headersList
 * @returns {Record<string, string>}
 */
function parseResponseHeaders(headersList) {
    const headers = {};
    for (const raw of headersList) {
        const colon = raw.indexOf(':');
        if (colon > 0) {
            const name = raw.substring(0, colon).trim().toLowerCase();
            const value = raw.substring(colon + 1).trim();
            headers[name] = value;
        }
    }
    return headers;
}
