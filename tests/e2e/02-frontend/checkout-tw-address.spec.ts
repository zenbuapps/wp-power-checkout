/**
 * 傳統結帳 — 台灣縣市 / 鄉鎮 / 郵遞區號下拉（結帳強化 tw_address）
 *
 * 對應 feature：specs/features/checkout/checkout-address-fields.feature
 *   規則「台灣地址開啟時，傳統結帳頁提供鄉鎮與郵遞區號對照資料」（互動行為只能用瀏覽器驗證）
 *
 * 只驗 PHPUnit 驗不到的 JS 互動：
 *  - 國家 TW → 縣市下拉 → 選縣市後 city 變成鄉鎮下拉 → 選鄉鎮後 postcode 自動帶入
 *  - 切到非 TW 國家 → city / state / postcode 還原為原生控制項
 *  - 帳單（billing_*）與運送（shipping_*）兩組都適用
 *
 * 前置假設（實作 inc/assets/js/classic-tw-address.js 時需維持）：
 *  - 欄位 id 沿用 WC 傳統結帳：#billing_country / #billing_state / #billing_city / #billing_postcode（shipping_* 同）
 *  - TW 時 city 被換成 <select id="billing_city">；非 TW 時還原為 <input id="billing_city">
 *
 * 執行（wp-env 開發站）：
 *   cd tests/e2e
 *   E2E_BASE_URL=http://localhost:8891 E2E_ADMIN_USER=admin E2E_ADMIN_PASS=password npx playwright test checkout-tw-address
 *
 * 預期：實作前為紅燈（tw_address 尚未實作，選縣市後 city 不會變成下拉）。
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test'
import { wpGet, wpPost, wpDelete, createApiContext, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL } from '../fixtures/test-data.js'

const SETTINGS_ENDPOINT = 'power-checkout/v1/settings/checkout_enhancement'

type Prefix = 'billing' | 'shipping'

test.describe('傳統結帳 — 台灣縣市鄉鎮下拉', () => {
  test.setTimeout(60_000)

  let ctx: APIRequestContext
  let opts: ApiOptions
  let originalSettings: Record<string, string> = {}
  let pageId = 0
  let productId = 0
  let zoneId = 0
  let classicCheckoutUrl = ''

  test.beforeAll(async () => {
    ctx = await createApiContext(BASE_URL)
    opts = { request: ctx, baseURL: BASE_URL, nonce: getNonce() }

    // 1. 記住原設定，開啟 tw_address
    const current = await wpGet(opts, SETTINGS_ENDPOINT)
    originalSettings = (((current.data as Record<string, unknown>).data ?? current.data) ?? {}) as Record<string, string>
    const updated = await wpPost(opts, SETTINGS_ENDPOINT, { tw_address: 'yes' })
    expect(updated.status).toBe(200)

    // 2. 建立傳統結帳頁（[woocommerce_checkout] shortcode；站台預設的結帳頁可能是區塊結帳）
    const page = await wpPost<{ id: number; link: string }>(opts, 'wp/v2/pages', {
      title: '[E2E] 傳統結帳',
      status: 'publish',
      content: '[woocommerce_checkout]',
    })
    expect(page.status).toBeLessThan(300)
    pageId = page.data.id
    classicCheckoutUrl = page.data.link

    // 3. 建立實體商品（需要運送，才會出現 shipping 欄位）
    const product = await wpPost<{ id: number }>(opts, 'wc/v3/products', {
      name: '[E2E] 帆布袋',
      type: 'simple',
      regular_price: '300',
      virtual: false,
      status: 'publish',
    })
    expect(product.status).toBeLessThan(300)
    productId = product.data.id

    // 4. 運送區域（需有啟用的運送方式，WC 才會判定購物車需要運送）
    const zone = await wpPost<{ id: number }>(opts, 'wc/v3/shipping/zones', { name: '[E2E] 台灣與美國' })
    if (zone.status < 300) {
      zoneId = zone.data.id
      await wpPost(opts, `wc/v3/shipping/zones/${zoneId}/locations`, [
        { code: 'TW', type: 'country' },
        { code: 'US', type: 'country' },
      ] as unknown as Record<string, unknown>)
      await wpPost(opts, `wc/v3/shipping/zones/${zoneId}/methods`, { method_id: 'flat_rate' })
    }
  })

  test.afterAll(async () => {
    await wpPost(opts, SETTINGS_ENDPOINT, {
      tw_address: originalSettings.tw_address ?? 'no',
    }).catch(() => { /* 忽略還原錯誤 */ })
    if (pageId) await wpDelete(opts, `wp/v2/pages/${pageId}?force=true`).catch(() => {})
    if (productId) await wpDelete(opts, `wc/v3/products/${productId}?force=true`).catch(() => {})
    if (zoneId) await wpDelete(opts, `wc/v3/shipping/zones/${zoneId}?force=true`).catch(() => {})
    await ctx.dispose()
  })

  /** 加入購物車並進入傳統結帳頁 */
  async function openClassicCheckout(page: Page): Promise<void> {
    await page.goto(`${BASE_URL}/?add-to-cart=${productId}`, { timeout: 30_000 })
    await page.goto(classicCheckoutUrl, { timeout: 30_000 })
    await expect(page.locator('#billing_country')).toBeAttached({ timeout: 15_000 })
  }

  /** 選國家並等 WC 的 update_checkout 跑完 */
  async function selectCountry(page: Page, prefix: Prefix, country: string): Promise<void> {
    await page.selectOption(`#${prefix}_country`, country)
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {})
  }

  /** TW → 臺北市 → 信義區 */
  async function pickTaipeiXinyi(page: Page, prefix: Prefix): Promise<void> {
    await selectCountry(page, prefix, 'TW')

    // 縣市：state 為下拉，key = label = 中文全名
    await expect(page.locator(`#${prefix}_state option[value="臺北市"]`)).toHaveCount(1, { timeout: 10_000 })
    await page.selectOption(`#${prefix}_state`, '臺北市')

    // 鄉鎮：city 被換成下拉
    const city = page.locator(`select#${prefix}_city`)
    await expect(city).toHaveCount(1, { timeout: 10_000 })
    await expect(city.locator('option[value="信義區"]')).toHaveCount(1)
    await page.selectOption(`select#${prefix}_city`, '信義區')
  }

  test('帳單：選臺北市後出現鄉鎮下拉，選信義區後郵遞區號帶入 110', async ({ page }) => {
    await openClassicCheckout(page)
    await pickTaipeiXinyi(page, 'billing')

    await expect(page.locator('#billing_postcode')).toHaveValue('110', { timeout: 10_000 })
  })

  test('帳單：縣市下拉含 22 個縣市（含連江縣），選連江縣南竿鄉帶入 209', async ({ page }) => {
    await openClassicCheckout(page)
    await selectCountry(page, 'billing', 'TW')

    // 22 個縣市 + WC 的「請選擇」佔位選項
    const options = page.locator('#billing_state option:not([value=""])')
    await expect(options).toHaveCount(22, { timeout: 10_000 })

    await page.selectOption('#billing_state', '連江縣')
    await expect(page.locator('select#billing_city')).toHaveCount(1, { timeout: 10_000 })
    await page.selectOption('select#billing_city', '南竿鄉')
    await expect(page.locator('#billing_postcode')).toHaveValue('209', { timeout: 10_000 })
  })

  test('帳單：切到 US 後還原為文字輸入，不再有鄉鎮下拉', async ({ page }) => {
    await openClassicCheckout(page)
    await pickTaipeiXinyi(page, 'billing')

    await selectCountry(page, 'billing', 'US')

    await expect(page.locator('select#billing_city')).toHaveCount(0, { timeout: 10_000 })
    await expect(page.locator('input#billing_city')).toBeVisible()
    await expect(page.locator('input#billing_city')).toBeEditable()
    // US 的州選單不應出現台灣縣市
    await expect(page.locator('#billing_state option[value="臺北市"]')).toHaveCount(0)
    // 郵遞區號可手動輸入
    await expect(page.locator('#billing_postcode')).toBeEditable()
  })

  test('運送地址：同樣可選縣市 / 鄉鎮並帶入郵遞區號 110', async ({ page }) => {
    await openClassicCheckout(page)

    // 勾選「運送到不同地址」
    const shipToDifferent = page.locator('#ship-to-different-address-checkbox')
    await expect(shipToDifferent).toBeAttached({ timeout: 15_000 })
    await shipToDifferent.check()
    await expect(page.locator('#shipping_country')).toBeAttached()

    await pickTaipeiXinyi(page, 'shipping')
    await expect(page.locator('#shipping_postcode')).toHaveValue('110', { timeout: 10_000 })

    // 帳單欄位不受運送欄位影響
    await expect(page.locator('select#billing_city')).toHaveCount(0)
  })

  test('運送地址：切到 US 後還原為文字輸入', async ({ page }) => {
    await openClassicCheckout(page)
    await page.locator('#ship-to-different-address-checkbox').check()
    await pickTaipeiXinyi(page, 'shipping')

    await selectCountry(page, 'shipping', 'US')

    await expect(page.locator('select#shipping_city')).toHaveCount(0, { timeout: 10_000 })
    await expect(page.locator('input#shipping_city')).toBeEditable()
  })
})
