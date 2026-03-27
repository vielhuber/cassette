'use strict';

const fs = require('fs');
const path = require('path');
const { defineConfig } = require('@playwright/test');

const configPath = path.join(__dirname, '../../../../.cassette/config.json');
const cassetteConfig = fs.existsSync(configPath) ? JSON.parse(fs.readFileSync(configPath, 'utf8')) : {};

module.exports = defineConfig({
    // Test files live alongside the PHP source files.
    testDir: __dirname,
    testMatch: 'cassette.spec.js',

    // Screenshots live in .data/{cassette-name}/screenshots/ alongside all other run data.
    snapshotDir: path.join(__dirname, '../../../../.cassette/runs', process.env.CASSETTE_NAME || '_unknown', 'screenshots'),
    snapshotPathTemplate: '{snapshotDir}/{arg}{ext}',

    // Sequential execution is required: requests must be replayed in the exact
    // order they were recorded so the mock pointer stays aligned.
    workers: 1,

    reporter: 'list',

    // Overall test timeout: steps * per-step timeout, with a generous minimum.
    // This prevents the whole test from timing out on long runs.
    timeout: cassetteConfig.screenshot?.timeout
        ? cassetteConfig.screenshot.timeout * 200 // headroom for up to 200 steps
        : 5 * 60 * 1000, // default: 5 min

    // Artifacts (traces etc.) land in /tmp — diffs are copied to .data/{name}/
    // directly by the afterEach hook in cassette.spec.js.
    outputDir: '/tmp/cassette-playwright-results',

    use: {
        // Self-signed certs on dev are fine — the recording already validated the content.
        ignoreHTTPSErrors: true,
        headless: cassetteConfig.screenshot?.headless ?? true
    }
});
