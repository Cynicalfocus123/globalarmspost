import { defineConfig, devices } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Every test runs on phone, tablet and desktop in Chromium, Firefox and WebKit (hard rule 10),
// against both copies of the site: git/ ('#' links) and live/ (proper addresses, hard rule 9).
// The servers are the real PHP server with a real SQLite database (tests/serve.mjs).
const copies = { git: 8190, live: 8191 } as const; // 8090/8091 stay free for the preview (.claude/launch.json)

// One id per run, shared with the workers and the servers. Each run writes to new folders,
// so Playwright's start-of-run cleanup never removes earlier files (owner rule: no deleting).
process.env.GAP_RUN ??= String(Date.now());
// Temporary files of the run (browser profiles and the like) on D:, never on C: (owner's rule: C: has little
// space). The workers and the servers inherit this; Playwright removes its own browser profiles when a browser closes.
const RUN_TMP = join(dirname(fileURLToPath(import.meta.url)), 'test-data', process.env.GAP_RUN, 'tmp');
mkdirSync(RUN_TMP, { recursive: true });
process.env.TMP = process.env.TEMP = process.env.TMPDIR = RUN_TMP;

const devicesByEngine = {
  chromium: {
    phone: { ...devices['Pixel 7'] },
    tablet: { viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true },
    desktop: { viewport: { width: 1440, height: 900 } },
  },
  firefox: {
    // Firefox in Playwright has no "mobile" mode: same sizes with touch on.
    phone: { browserName: 'firefox' as const, viewport: { width: 390, height: 844 }, hasTouch: true },
    tablet: { browserName: 'firefox' as const, viewport: { width: 768, height: 1024 }, hasTouch: true },
    desktop: { browserName: 'firefox' as const, viewport: { width: 1440, height: 900 } },
  },
  webkit: {
    phone: { ...devices['iPhone 14'] },
    tablet: { ...devices['iPad (gen 7)'] },
    desktop: { browserName: 'webkit' as const, viewport: { width: 1440, height: 900 } },
  },
};

const engines = (process.env.ENGINES ?? 'chromium,firefox,webkit').split(',');
// Three phases per copy:
//   setup-<copy>            makes the first admin through the real setup step (desktop Chromium, once)
//   <copy>-<engine>-<device>             site, story and admin tests (18 projects)
//   <copy>-<engine>-<device>-publish     admin actions visitors can see (publishing, new categories, homepage
//                                        builder), after the phase above, so the site tests never see a half-changed homepage
const projects: any[] = [];
for (const [copy, port] of Object.entries(copies)) {
  const baseURL = `http://127.0.0.1:${port}`;
  projects.push({ name: `setup-${copy}`, testMatch: /admin-setup\.spec\.ts/, use: { ...devicesByEngine.chromium.desktop, baseURL }, metadata: { copy, engine: 'chromium', device: 'desktop' } });
  const main: string[] = [];
  for (const engine of engines) {
    for (const [device, use] of Object.entries(devicesByEngine[engine as keyof typeof devicesByEngine])) {
      const name = `${copy}-${engine}-${device}`;
      main.push(name);
      projects.push({
        name,
        testIgnore: [/admin-setup\.spec\.ts/, /admin-publish\.spec\.ts/],
        dependencies: [`setup-${copy}`],
        use: { ...use, baseURL },
        metadata: { copy, engine, device },
      });
    }
  }
  for (const name of [...main]) {
    const base = projects.find((p) => p.name === name);
    projects.push({ ...base, name: `${name}-publish`, testIgnore: undefined, testMatch: /admin-publish\.spec\.ts/, dependencies: main });
  }
}

export default defineConfig({
  testDir: './tests',
  timeout: 45_000,
  expect: { timeout: 10_000 }, // 8 workers on three engines at once can make one page answer take over 5 s
  fullyParallel: true,
  workers: process.env.WORKERS ? Number(process.env.WORKERS) : process.env.CI ? 2 : 4, // WORKERS=8 npm test on a big machine
  outputDir: `test-results/${process.env.GAP_RUN}`,
  reporter: [['list'], ['json', { outputFile: `test-results/${process.env.GAP_RUN}-report.json` }]],
  use: { trace: 'retain-on-failure' },
  projects,
  webServer: Object.entries(copies).map(([copy, port]) => ({
    command: `node tests/serve.mjs ${copy} ${port}`,
    url: `http://127.0.0.1:${port}/`,
    reuseExistingServer: false,
    timeout: 180_000, // GAP_DB=mysql: mysqld --initialize takes a while
    ignoreHTTPSErrors: true,
  })),
});
