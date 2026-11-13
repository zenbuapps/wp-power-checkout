/**
 * 區塊（Blocks）購物車 / 結帳 — 「差多少免運」提示（結帳強化 free_shipping_hint）
 *
 * 對應 feature：specs/features/checkout/free-shipping-hint.feature
 *   - 位置 Outline 的「區塊 × 購物車頁 / 結帳頁」：顯示「再買 150 元即可享免運」
 *   - 差額 Outline 的邊界：數量調到門檻後顯示「已享免運」
 * 對應計畫：specs/open-issue/checkout-enhancement-implementation-plan.md 第 6、7 階段、ASM-3
 *
 * 只驗 PHPUnit 驗不到的部分：區塊前端（slot fill）實際渲染。計算邏輯由
 * tests/Integration/Checkout/FreeShippingHintTest.php 負責，這裡不展開邊緣案例。
 *
 * 測試資料（spec 自行建立 / 清理）：
 *  - 商店小數位數 woocommerce_price_num_decimals 暫設為 0（對齊 feature 背景），結束時還原原值
 *  - 實體商品「[E2E] 免運提示帆布袋」單價 850
 *  - 運送區域「[E2E] 免運提示-台灣」(TW)：flat_rate 100 + free_shipping（min_amount 1000、不忽略折扣）
 *  - 結帳強化設定：free_shipping_hint=yes、預設文案（再買 {price} 元即可享免運 / 已享免運）
 *    開關透過 REST POST /power-checkout/v1/settings/checkout_enhancement，結束時還原原值
 *
 * 前置假設（需由開發站滿足，spec 不改動這些全站設定）：
 *  - WooCommerce 的購物車頁 / 結帳頁為「區塊」型態（WC 8.3+ 全新安裝預設）；否則 beforeAll 會明確報錯
 *  - 商店預設國家 TW、幣別 TWD，預設客戶位置為商店基本地址（woocommerce_default_country=TW），
 *    讓訪客不填地址時 WC 也能以 TW 比對到上述運送區域
 *  - 站內沒有其他排序更前、且涵蓋 TW 的運送區域（否則會先比對到別的區域）
 *  - 已執行 pnpm build:blocks，inc/assets/dist/blocks/pc_free_shipping_hint.js 存在
 *
 * 執行（wp-env 開發站）：
 *   cd tests/e2e
 *   E2E_BASE_URL=http://localhost:8891 E2E_ADMIN_USER=admin E2E_ADMIN_PASS=password npx playwright test free-shipping-hint-blocks
 *
 * 預期：實作前為紅燈（區塊提示尚未實作，頁面上找不到提示文字）。
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test'
import { wpGet, wpPost, wpPut, wpDelete, createApiContext, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL } from '../fixtures/test-data.js'

const SETTINGS_ENDPOINT = 'power-checkout/v1/settings/checkout_enhancement'

/** WC 商店小數位數設定（feature 背景為 0，文案 {price} 為純數字千分位；開發站預設 2 會顯示「150.00」） */
const DECIMALS_ENDPOINT = 'wc/v3/settings/general/woocommerce_price_num_decimals'

/** 本 spec 會改動並於結束時還原的設定鍵 */
const TOUCHED_KEYS = [
  'free_shipping_hint',
  'free_shipping_text_left',
  'free_shipping_text_achieved',
] as const

const TEXT_SHORT = '再買 150 元即可享免運'
const TEXT_REACHED = '已享免運'

/** 區塊購物車 / 結帳在 hydration 與 Store API 重算期間較慢，提示文字等待時間放寬 */
const HINT_TIMEOUT = 30_000

test.describe('區塊購物車 / 結帳 — 差多少免運提示', () => {
  test.setTimeout(90_000)

  // 訪客購物車（Cookie session）；預設的 admin storageState 會共用使用者持久購物車，造成測試間互相污染
  test.use({ storageState: { cookies: [], origins: [] } })

  let ctx: APIRequestContext
  let opts: ApiOptions
  let originalSettings: Record<string, string> = {}
  /** 原本的商店小數位數（afterAll 還原）；undefined 表示尚未成功讀取，不還原 */
  let originalDecimals: string | undefined
  let productId = 0
  let zoneId = 0
  let blockCartUrl = ''
  let blockCheckoutUrl = ''

  /** 取得 WC 設定的頁面 ID 並確認該頁為指定區塊型態，回傳前台網址 */
  async function resolveBlockPage(settingId: string, blockName: 'cart' | 'checkout'): Promise<string> {
    const setting = await wpGet<{ value?: string }>(opts, `wc/v3/settings/advanced/${settingId}`)
    expect(setting.status, `讀取 WC 設定 ${settingId} 失敗`).toBe(200)
    const pageId = Number(setting.data.value)
    expect(pageId, `WC 設定 ${settingId} 未指定頁面`).toBeGreaterThan(0)

    const page = await wpGet<{ link: string; content: { raw?: string } }>(
      opts,
      `wp/v2/pages/${pageId}`,
      { context: 'edit' },
    )
    expect(page.status, `讀取頁面 #${pageId} 失敗`).toBe(200)
    const raw = page.data.content?.raw ?? ''
    expect(
      raw.includes(`wp:woocommerce/${blockName}`),
      `開發站的${blockName === 'cart' ? '購物車' : '結帳'}頁（#${pageId}）不是區塊型態，無法執行本 spec（請改為區塊頁面）`,
    ).toBeTruthy()
    return page.data.link
  }

  test.beforeAll(async () => {
    ctx = await createApiContext(BASE_URL)
    opts = { request: ctx, baseURL: BASE_URL, nonce: getNonce() }

    // 0. 商店小數位數固定為 0（對齊 feature 背景），先記下原值
    const decimals = await wpGet<{ value?: string }>(opts, DECIMALS_ENDPOINT)
    expect(decimals.status, '讀取 woocommerce_price_num_decimals 失敗').toBe(200)
    originalDecimals = String(decimals.data.value ?? '2')
    if (originalDecimals !== '0') {
      const setDecimals = await wpPut(opts, DECIMALS_ENDPOINT, { value: '0' })
      expect(setDecimals.status, '設定 woocommerce_price_num_decimals=0 失敗').toBe(200)
    }

    // 1. 記住原設定，開啟免運提示並固定文案
    const current = await wpGet(opts, SETTINGS_ENDPOINT)
    const currentData = (((current.data as Record<string, unknown>).data ?? current.data) ?? {}) as Record<string, string>
    for (const key of TOUCHED_KEYS) originalSettings[key] = currentData[key]
    const updated = await wpPost(opts, SETTINGS_ENDPOINT, {
      free_shipping_hint: 'yes',
      free_shipping_text_left: '再買 {price} 元即可享免運',
      free_shipping_text_achieved: TEXT_REACHED,
    })
    expect(updated.status).toBe(200)

    // 2. 區塊購物車 / 結帳頁（用站台既有頁面，確認為區塊型態）
    blockCartUrl = await resolveBlockPage('woocommerce_cart_page_id', 'cart')
    blockCheckoutUrl = await resolveBlockPage('woocommerce_checkout_page_id', 'checkout')

    // 3. 實體商品 850
    const product = await wpPost<{ id: number }>(opts, 'wc/v3/products', {
      name: '[E2E] 免運提示帆布袋',
      type: 'simple',
      regular_price: '850',
      virtual: false,
      status: 'publish',
    })
    expect(product.status).toBeLessThan(300)
    productId = product.data.id

    // 4. 運送區域 TW：flat_rate 100 + free_shipping（min_amount 1000、不忽略折扣）
    const zone = await wpPost<{ id: number }>(opts, 'wc/v3/shipping/zones', { name: '[E2E] 免運提示-台灣' })
    expect(zone.status).toBeLessThan(300)
    zoneId = zone.data.id
    await wpPost(opts, `wc/v3/shipping/zones/${zoneId}/locations`, [
      { code: 'TW', type: 'country' },
    ] as unknown as Record<string, unknown>)
    const flat = await wpPost(opts, `wc/v3/shipping/zones/${zoneId}/methods`, {
      method_id: 'flat_rate',
      settings: { cost: '100' },
    })
    expect(flat.status).toBeLessThan(300)
    const free = await wpPost(opts, `wc/v3/shipping/zones/${zoneId}/methods`, {
      method_id: 'free_shipping',
      settings: { requires: 'min_amount', min_amount: '1000', ignore_discounts: 'no' },
    })
    expect(free.status).toBeLessThan(300)
  })

  test.afterAll(async () => {
    if (originalDecimals !== undefined && originalDecimals !== '0') {
      await wpPut(opts, DECIMALS_ENDPOINT, { value: originalDecimals }).catch(() => { /* 忽略還原錯誤 */ })
    }
    const restore: Record<string, string> = {}
    for (const key of TOUCHED_KEYS) {
      if (originalSettings[key] !== undefined) restore[key] = originalSettings[key]
    }
    // 原本沒有儲存過的鍵（undefined）以預設值還原，避免殘留開啟狀態
    if (restore.free_shipping_hint === undefined) restore.free_shipping_hint = 'no'
    await wpPost(opts, SETTINGS_ENDPOINT, restore).catch(() => { /* 忽略還原錯誤 */ })
    if (productId) await wpDelete(opts, `wc/v3/products/${productId}?force=true`).catch(() => {})
    if (zoneId) await wpDelete(opts, `wc/v3/shipping/zones/${zoneId}?force=true`).catch(() => {})
    await ctx.dispose()
  })

  /** 以訪客加入購物車（傳統 add-to-cart URL 仍可用，session cookie 與區塊共用） */
  async function addToCart(page: Page, quantity: number): Promise<void> {
    await page.goto(`${BASE_URL}/?add-to-cart=${productId}&quantity=${quantity}`, { timeout: 30_000 })
  }

  test('區塊購物車頁：小計 850 顯示「再買 150 元即可享免運」', async ({ page }) => {
    await addToCart(page, 1)
    await page.goto(blockCartUrl, { timeout: 30_000 })

    await expect(page.getByText(TEXT_SHORT)).toBeVisible({ timeout: HINT_TIMEOUT })
    await expect(page.getByText(TEXT_REACHED)).toHaveCount(0)
  })

  test('區塊結帳頁：小計 850 顯示「再買 150 元即可享免運」', async ({ page }) => {
    await addToCart(page, 1)
    await page.goto(blockCheckoutUrl, { timeout: 30_000 })

    await expect(page.getByText(TEXT_SHORT)).toBeVisible({ timeout: HINT_TIMEOUT })
    await expect(page.getByText(TEXT_REACHED)).toHaveCount(0)
  })

  test('區塊購物車頁：數量調到 2（小計 1700 >= 1000）後改顯示「已享免運」', async ({ page }) => {
    await addToCart(page, 1)
    await page.goto(blockCartUrl, { timeout: 30_000 })
    await expect(page.getByText(TEXT_SHORT)).toBeVisible({ timeout: HINT_TIMEOUT })

    // 數量 +1 -> Store API 重算購物車 -> extension 資料更新 -> slot fill 重新渲染
    await page.locator('.wc-block-components-quantity-selector__button--plus').first().click()

    await expect(page.getByText(TEXT_REACHED)).toBeVisible({ timeout: HINT_TIMEOUT })
    await expect(page.getByText(TEXT_SHORT)).toHaveCount(0)
  })

  test('區塊結帳頁：數量 2 的購物車（小計 1700 >= 1000）顯示「已享免運」', async ({ page }) => {
    await addToCart(page, 2)
    await page.goto(blockCheckoutUrl, { timeout: 30_000 })

    await expect(page.getByText(TEXT_REACHED)).toBeVisible({ timeout: HINT_TIMEOUT })
    await expect(page.getByText(TEXT_SHORT)).toHaveCount(0)
  })

  test('開關關閉時區塊購物車與結帳都不顯示提示', async ({ page }) => {
    const off = await wpPost(opts, SETTINGS_ENDPOINT, { free_shipping_hint: 'no' })
    expect(off.status).toBe(200)

    try {
      await addToCart(page, 1)

      await page.goto(blockCartUrl, { timeout: 30_000 })
      await expect(page.locator('.wc-block-cart')).toBeVisible({ timeout: HINT_TIMEOUT })
      await expect(page.getByText(TEXT_SHORT)).toHaveCount(0)

      await page.goto(blockCheckoutUrl, { timeout: 30_000 })
      await expect(page.locator('[data-block-name="woocommerce/checkout"]').first()).toBeVisible({ timeout: HINT_TIMEOUT })
      await expect(page.getByText(TEXT_SHORT)).toHaveCount(0)
    } finally {
      await wpPost(opts, SETTINGS_ENDPOINT, { free_shipping_hint: 'yes' })
    }
  })
})
