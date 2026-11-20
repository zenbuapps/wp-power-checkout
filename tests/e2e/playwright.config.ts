import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: '.',
  testMatch: ['**/*.spec.ts'],
  // WordPress 共用 DB session，必須單 worker 循序執行
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
  ],
  use: {
    // 可用 E2E_BASE_URL 覆寫（例如 wp-env：http://localhost:8891）
    baseURL: process.env.E2E_BASE_URL || 'https://local-turbo.powerhouse.tw',
    ignoreHTTPSErrors: true,
    storageState: '.auth/admin.json',
    extraHTTPHeaders: {
      // 預設不帶 Nonce，各測試透過 getNonce() 動態取得
      'X-WP-Nonce': '',
    },
    trace: 'on-first-retry',
  },
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',
  projects: [
    {
      name: 'api-tests',
      testDir: '.',
      testMatch: ['**/*.spec.ts'],
      // 04-live 為真實 sandbox 錄影驗收，只在 live-acceptance project 執行
      testIgnore: ['04-live/**'],
    },
    {
      // 錄影驗收（藍圖 P8 §7）：對真實站點 + 金流 / 物流 sandbox 跑，產出影片
      // 執行：npx playwright test --project=live-acceptance 04-live/<spec>（一次一支）
      // 憑證只從環境變數讀（helpers/live-env.ts），影片整理到 acceptance-videos/（已 gitignore）
      name: 'live-acceptance',
      testDir: '.',
      testMatch: ['04-live/**/*.spec.ts'],
      timeout: 600_000,
      outputDir: 'test-results/live-acceptance',
      use: {
        headless: false, // headless 易被 WAF / 收銀台 anti-automation 擋
        // 預設用本機 Google Chrome（真瀏覽器較不易被 WAF 擋；亦免下載 Playwright chromium），可用 LIVE_BROWSER_CHANNEL 覆寫
        channel: process.env.LIVE_BROWSER_CHANNEL || 'chrome',
        video: 'on',
        trace: 'on',
        screenshot: 'on',
        viewport: { width: 1440, height: 900 },
        launchOptions: { slowMo: 150 },
        // 不沿用 api-tests 的 admin storageState：顧客結帳以訪客身分進行，admin 段落於 spec 內登入
        storageState: { cookies: [], origins: [] },
        // 頂層的 'X-WP-Nonce': '' 會被瀏覽器帶進每個請求，WP 對空 nonce 判定 rest_cookie_invalid_nonce（403），
        // 使訪客 order_key 授權的 REST（dispatch 等）失敗 → live 驗收不帶任何額外 header
        extraHTTPHeaders: {},
        actionTimeout: 30_000,
        navigationTimeout: 90_000,
      },
    },
  ],
})
