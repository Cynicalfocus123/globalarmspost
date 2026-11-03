import { defineConfig, devices } from '@playwright/test';

// Every test runs on phone, tablet and desktop in Chromium, Firefox and WebKit (hard rule 10),
// against both copies of the site: git/ ('#' links) and live/ (proper addresses, hard rule 9).
// The servers are the real PHP server with a real SQLite database (tests/serve.mjs).
const copies = { git: 8090, live: 8091 } as const;

// One id per run, shared with the workers and the servers. Each run writes to new folders,
// so Playwright's start-of-run cleanup never removes earlier files (owner rule: no deleting).
process.env.GAP_RUN ??= String(Date.now());

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
const projects = [];
for (const [copy, port] of Object.entries(copies)) {
  for (const engine of engines) {
    for (const [device, use] of Object.entries(devicesByEngine[engine as keyof typeof devicesByEngine])) {
      projects.push({
        name: `${copy}-${engine}-${device}`,
        use: { ...use, baseURL: `http://127.0.0.1:${port}` },
        metadata: { copy, engine, device },
      });
    }
  }
}

export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  fullyParallel: true,
  workers: process.env.CI ? 2 : 4,
  outputDir: `test-results/${process.env.GAP_RUN}`,
  reporter: [['list'], ['json', { outputFile: `test-results/${process.env.GAP_RUN}-report.json` }]],
  use: { trace: 'retain-on-failure' },
  projects,
  webServer: Object.entries(copies).map(([copy, port]) => ({
    command: `node tests/serve.mjs ${copy} ${port}`,
    url: `http://127.0.0.1:${port}/`,
    reuseExistingServer: false,
    timeout: 60_000,
    ignoreHTTPSErrors: true,
  })),
});
