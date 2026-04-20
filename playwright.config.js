'use strict';

// Thin forwarder so `npx playwright test` run from the repository root still
// works for local development. All production invocations go through the
// cassette CLI which passes `--config=src/playwright.config.js` explicitly —
// this file only exists so the Playwright CLI does not complain about a
// missing config when invoked without the flag.
module.exports = require('./src/playwright.config.js');
