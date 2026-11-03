import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const php = process.env.JEAB_PHP || 'php';
const port = process.env.JEAB_E2E_PORT || '8765';
const channel = process.env.CI ? undefined : 'chrome';

export default defineConfig({
  testDir: here,
  testMatch: 'flows.spec.mjs',
  timeout: 30000,
  expect: {
    toHaveScreenshot: { maxDiffPixelRatio: 0.02 },
  },
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    channel,
    viewport: { width: 1280, height: 800 },
    deviceScaleFactor: 1,
  },
  webServer: {
    command: `${php} -S 127.0.0.1:${port} -t ${root} ${path.join(here, 'router.php')}`,
    cwd: root,
    url: `http://127.0.0.1:${port}/health`,
    reuseExistingServer: !process.env.CI,
    timeout: 20000,
  },
});
