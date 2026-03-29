'use strict';

const fs = require('fs');
const path = require('path');
const { defineConfig } = require('@playwright/test');

const projectRoot = process.env.CASSETTE_ROOT || path.join(__dirname, '../../../..');
const configPath = path.join(projectRoot, '.cassette/config.json');
const cassetteConfig = fs.existsSync(configPath) ? JSON.parse(fs.readFileSync(configPath, 'utf8')) : {};

module.exports = defineConfig({
    // Test files live alongside the PHP source files.
    testDir: __dirname,
    testMatch: 'cassette.spec.js',

    // Screenshots live in .cassette/runs/{cassette-name}/screenshots/ alongside all other run data.
    snapshotDir: path.join(projectRoot, '.cassette/runs', process.env.CASSETTE_NAME || '_unknown', 'screenshots'),
    snapshotPathTemplate: '{snapshotDir}/{arg}{ext}',

    // Sequential execution is required: requests must be replayed in the exact
    // order they were recorded so the mock pointer stays aligned.
    workers: 1,

    // Suppress Playwright's built-in verbose output — step lines and diff paths
    // are logged directly via console.log inside cassette.spec.js.
    reporter: 'null',

    // Overall test timeout: steps * per-step timeout, with a generous minimum.
    // This prevents the whole test from timing out on long runs.
    timeout: cassetteConfig.screenshot?.timeout
        ? cassetteConfig.screenshot.timeout * 200 // headroom for up to 200 steps
        : 5 * 60 * 1000, // default: 5 min

    // Artifacts (traces etc.) land in /tmp — diffs are copied to .cassette/runs/{name}/
    // directly by the afterEach hook in cassette.spec.js.
    outputDir: '/tmp/cassette-playwright-results',

    use: {
        // Self-signed certs on dev are fine — the recording already validated the content.
        ignoreHTTPSErrors: true,
        headless: cassetteConfig.screenshot?.headless ?? true
    }
});
