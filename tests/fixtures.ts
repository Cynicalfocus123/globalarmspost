// Shared `test` for every spec: Playwright's own, plus one worker fixture that keeps a run from freezing.
//
// 1. Warm-up: right after the browser starts, each worker opens and closes one page, with its own 120 s
//    budget. On this Windows PC a cold browser took up to 20 s for its first page, and that time used to
//    count against the first test's 45 s.
// 2. Watchdog: when the worker shuts down, its browser is closed. Sometimes on Windows the browser quits
//    but its "closed" answer never arrives, and the worker waited forever: the whole run froze (twice on
//    2026-11-10, for 8-10 minutes, with no browser left). Now a worker still shutting down after 60 s
//    ends itself; all its tests have finished by then.
import { test as base } from '@playwright/test';

export * from '@playwright/test';

export const test = base.extend<{}, { workerGuard: void }>({
  workerGuard: [async ({ browser }, use) => {
    const context = await browser.newContext();
    await (await context.newPage()).close();
    await context.close();
    await use();
    setTimeout(() => process.exit(0), 60_000).unref();
  }, { scope: 'worker', auto: true, timeout: 120_000 }],
});
