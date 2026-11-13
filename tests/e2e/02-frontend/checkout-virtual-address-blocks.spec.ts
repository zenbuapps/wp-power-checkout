/**
 * 區塊（Blocks）結帳 — 全虛擬購物車隱藏地址欄位（結帳強化 virtual_hide_address）
 *
 * 對應 feature：specs/features/checkout/ 虛擬商品隱藏地址相關規則
 * 對應計畫：specs/open-issue/checkout-enhancement-implementation-plan.md 第 7 階段
 * 對應決策：specs/clarify/2026-11-13-1232.md D15
 *   - 區塊結帳 country 不能用 locale 隱藏（會清空並擋單），改用 CSS
 *     `.wc-block-components-address-form .wc-block-components-country-input{display:none !important}` 隱藏
 *   - 其餘 5 個地址欄位（state / city / postcode / address_1 / address_2）在 TW locale 設 hidden=true、required=false
 *
 * 只驗 PHPUnit 驗不到的部分：區塊結帳實際渲染（欄位可見性）與「只填 email / 姓名 / 電話」能否送單成功。
 * 邊緣案例（混合購物車、開關關閉、非 TW 國家等）由 tests/Integration/Checkout/ 負責，這裡不展開。
 *
 * 測試資料（spec 自行建立 / 清理）：
 *  - 結帳強化設定 virtual_hide_address=yes（REST 設定，結束時還原原值）
 *  - 付款方式 cod（貨到付款，不需外部 API）暫時啟用（結束時還原原本的啟用狀態）
 *  - 虛擬商品「[E2E] 虛擬下載檔」(100) / 實體商品「[E2E] 實體帆布袋」(300)
 *  - 運送區域「[E2E] 虛擬地址-台灣」(TW) flat_rate 50（實體商品才需要運送方式）
 *  - 測試送出的訂單（結束時刪除）
 *
 * 前置假設（需由開發站滿足，spec 不改動這些全站設定）：
 *  - WooCommerce 結帳頁為「區塊」型態；否則 beforeAll 會明確報錯
 *  - 商店預設國家 TW，預設客戶位置為商店基本地址（woocommerce_default_country=TW）
 *  - 開啟訪客結帳
 *
 * 執行（wp-env 開發站）：
 *   cd tests/e2e
 *   E2E_BASE_URL=http://localhost:8891 E2E_ADMIN_USER=admin E2E_ADMIN_PASS=password npx playwright test checkout-virtual-address-blocks
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test'
import { wpGet, wpPost, wpPut, wpDelete, createApiContext, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL } from '../fixtures/test-data.js'

const SETTINGS_ENDPOINT = 'power-checkout/v1/settings/checkout_enhancement'
const COD_ENDPOINT = 'wc/v3/payment_gateways/cod'

/** 區塊結帳在 hydration 與 Store API 重算期間較慢 */
const BLOCK_TIMEOUT = 30_000

/** 區塊結帳地址欄位（billing 與 shipping 各一組，這裡以名稱列出） */
const ADDRESS_FIELDS = ['state', 'city', 'postcode', 'address_1'] as const

test.describe('區塊結帳 — 全虛擬購物車隱藏地址欄位', () => {
  test.setTimeout(120_000)

  // 訪客購物車（Cookie session）；預設 admin storageState 會共用使用者持久購物車，造成測試間互相污染
  // extraHTTPHeaders 清空：playwright 全域設定的 X-WP-Nonce: '' 會讓頁面內 Store API 請求 403
  test.use({ storageState: { cookies: [], origins: [] }, extraHTTPHeaders: {} })

  let ctx: APIRequestContext
  let opts: ApiOptions
  let originalHideAddress: string | undefined
  /** cod 原本是否啟用（undefined = 尚未成功讀取，不還原） */
  let originalCodEnabled: boolean | undefined
  let virtualProductId = 0
  let physicalProductId = 0
  let zoneId = 0
  let blockCheckoutUrl = ''
  const createdOrderIds: number[] = []

  test.beforeAll(async () => {
    ctx = await createApiContext(BASE_URL)
    opts = { request: ctx, baseURL: BASE_URL, nonce: getNonce() }

    // 0. 結帳頁需為區塊型態
    const setting = await wpGet<{ value?: string }>(opts, 'wc/v3/settings/advanced/woocommerce_checkout_page_id')
    expect(setting.status, '讀取 woocommerce_checkout_page_id 失敗').toBe(200)
    const pageId = Number(setting.data.value)
    expect(pageId, 'WC 未指定結帳頁').toBeGreaterThan(0)
    const page = await wpGet<{ link: string; content: { raw?: string } }>(opts, `wp/v2/pages/${pageId}`, { context: 'edit' })
    expect(page.status, `讀取結帳頁 #${pageId} 失敗`).toBe(200)
    expect(
      (page.data.content?.raw ?? '').includes('wp:woocommerce/checkout'),
      `開發站的結帳頁（#${pageId}）不是區塊型態，無法執行本 spec`,
    ).toBeTruthy()
    blockCheckoutUrl = page.data.link

    // 1. 記住原設定，開啟 virtual_hide_address
    const current = await wpGet(opts, SETTINGS_ENDPOINT)
    const currentData = (((current.data as Record<string, unknown>).data ?? current.data) ?? {}) as Record<string, string>
    originalHideAddress = currentData.virtual_hide_address
    const updated = await wpPost(opts, SETTINGS_ENDPOINT, { virtual_hide_address: 'yes' })
    expect(updated.status).toBe(200)

    // 2. 暫時啟用 cod（貨到付款不需外部 API），先記下原啟用狀態
    const cod = await wpGet<{ enabled?: boolean }>(opts, COD_ENDPOINT)
    expect(cod.status, '讀取 cod 付款方式失敗').toBe(200)
    originalCodEnabled = Boolean(cod.data.enabled)
    if (!originalCodEnabled) {
      const enableCod = await wpPut(opts, COD_ENDPOINT, { enabled: true })
      expect(enableCod.status, '啟用 cod 失敗').toBe(200)
    }

    // 3. 虛擬 / 實體商品
    const virtual = await wpPost<{ id: number }>(opts, 'wc/v3/products', {
      name: '[E2E] 虛擬下載檔',
      type: 'simple',
      regular_price: '100',
      virtual: true,
      status: 'publish',
    })
    expect(virtual.status).toBeLessThan(300)
    virtualProductId = virtual.data.id

    const physical = await wpPost<{ id: number }>(opts, 'wc/v3/products', {
      name: '[E2E] 實體帆布袋',
      type: 'simple',
      regular_price: '300',
      virtual: false,
      status: 'publish',
    })
    expect(physical.status).toBeLessThan(300)
    physicalProductId = physical.data.id

    // 4. 運送區域 TW + flat_rate（含實體商品才需要運送方式）
    const zone = await wpPost<{ id: number }>(opts, 'wc/v3/shipping/zones', { name: '[E2E] 虛擬地址-台灣' })
    expect(zone.status).toBeLessThan(300)
    zoneId = zone.data.id
    await wpPost(opts, `wc/v3/shipping/zones/${zoneId}/locations`, [
      { code: 'TW', type: 'country' },
    ] as unknown as Record<string, unknown>)
    const flat = await wpPost(opts, `wc/v3/shipping/zones/${zoneId}/methods`, {
      method_id: 'flat_rate',
      settings: { cost: '50' },
    })
    expect(flat.status).toBeLessThan(300)
  })

  test.afterAll(async () => {
    // 原本沒有儲存過的鍵（undefined）以預設值 no 還原，避免殘留開啟狀態
    await wpPost(opts, SETTINGS_ENDPOINT, { virtual_hide_address: originalHideAddress ?? 'no' }).catch(() => { /* 忽略還原錯誤 */ })
    if (originalCodEnabled === false) {
      await wpPut(opts, COD_ENDPOINT, { enabled: false }).catch(() => { /* 忽略還原錯誤 */ })
    }
    for (const id of createdOrderIds) {
      await wpDelete(opts, `wc/v3/orders/${id}?force=true`).catch(() => {})
    }
    if (virtualProductId) await wpDelete(opts, `wc/v3/products/${virtualProductId}?force=true`).catch(() => {})
    if (physicalProductId) await wpDelete(opts, `wc/v3/products/${physicalProductId}?force=true`).catch(() => {})
    if (zoneId) await wpDelete(opts, `wc/v3/shipping/zones/${zoneId}?force=true`).catch(() => {})
    await ctx.dispose()
  })

  /** 以訪客加入購物車（傳統 add-to-cart URL 仍可用，session cookie 與區塊共用）並進入區塊結帳頁 */
  async function openBlockCheckout(page: Page, productId: number): Promise<void> {
    await page.goto(`${BASE_URL}/?add-to-cart=${productId}`, { timeout: 30_000 })
    await page.goto(blockCheckoutUrl, { timeout: 30_000 })
    // 結帳區塊 hydration 完成的指標：聯絡資訊 email 欄位出現
    await expect(page.locator('#email')).toBeVisible({ timeout: BLOCK_TIMEOUT })
  }

  /** 區塊結帳以 CSS 隱藏 country 時，國家欄位的外層容器 */
  function countryInput(page: Page) {
    return page.locator('.wc-block-components-address-form .wc-block-components-country-input')
  }

  test('全虛擬購物車：國家 / 縣市 / 鄉鎮 / 郵遞區號 / 地址欄位不可見，只填 email、姓名、電話即可送單成功', async ({ page }) => {
    await openBlockCheckout(page, virtualProductId)

    // 正向確認：保留的欄位（姓名、電話）確實渲染，後面「不可見」才有意義（避免頁面未載入時恆真）
    await expect(page.locator('#billing-first_name')).toBeVisible({ timeout: BLOCK_TIMEOUT })
    await expect(page.locator('#billing-last_name')).toBeVisible()
    await expect(page.locator('#billing-phone')).toBeVisible()

    // 5 個地址欄位 + country 皆不可見
    await expect(countryInput(page).first()).toBeHidden()
    await expect(page.locator('#billing-country')).toBeHidden()
    for (const field of ADDRESS_FIELDS) {
      await expect(page.locator(`#billing-${field}`), `billing-${field} 應不可見`).toBeHidden()
    }

    // 只填 email、姓名、電話
    await page.locator('#email').fill('e2e-virtual@example.com')
    await page.locator('#billing-first_name').fill('明')
    await page.locator('#billing-last_name').fill('王')
    await page.locator('#billing-phone').fill('0912345678')
    // 離開欄位並等區塊的客戶資料同步（debounced Store API 更新）跑完，
    // 否則 Playwright 填值過快，區塊的受控欄位可能被較舊的伺服器資料覆寫而送出空值（WC Blocks 的既知競態，與本功能無關）
    await page.locator('#billing-phone').blur()
    await page.waitForLoadState('networkidle', { timeout: BLOCK_TIMEOUT }).catch(() => {})

    // 付款方式：貨到付款
    await page.locator('input[id*="radio-control-wc-payment-method-options-cod"]').check({ timeout: BLOCK_TIMEOUT })
    await page.waitForLoadState('networkidle', { timeout: BLOCK_TIMEOUT }).catch(() => {})

    // 送單前確認各欄位值仍在（區塊重繪沒有吃掉輸入）
    await expect(page.locator('#email')).toHaveValue('e2e-virtual@example.com')
    await expect(page.locator('#billing-first_name')).toHaveValue('明')
    await expect(page.locator('#billing-last_name')).toHaveValue('王')
    await expect(page.locator('#billing-phone')).toHaveValue('0912345678')

    await page.screenshot({ path: 'test-results/checkout-virtual-address-blocks-before-submit.png', fullPage: true })

    await page.locator('.wc-block-components-checkout-place-order-button').click()

    // 送單成功：導向 order-received，網址含訂單編號
    await page.waitForURL(/order-received\/(\d+)\//, { timeout: 60_000 })
    const matched = page.url().match(/order-received\/(\d+)\//)
    expect(matched, `送單後網址不含訂單編號：${page.url()}`).not.toBeNull()
    const orderId = Number(matched![1])
    createdOrderIds.push(orderId)
    expect(orderId).toBeGreaterThan(0)

    await page.screenshot({ path: 'test-results/checkout-virtual-address-blocks-order-received.png', fullPage: true })

    // 後端確認：訂單存在、電話正確、沒有帳單地址
    const order = await wpGet<{
      id: number
      billing: { phone: string; first_name: string; last_name: string; address_1: string; city: string; postcode: string }
    }>(opts, `wc/v3/orders/${orderId}`)
    expect(order.status).toBe(200)
    expect(order.data.billing.phone).toBe('0912345678')
    expect(order.data.billing.last_name).toBe('王')
    expect(order.data.billing.address_1).toBe('')
    expect(order.data.billing.city).toBe('')
    expect(order.data.billing.postcode).toBe('')
  })

  test('對照組：開關關閉時，全虛擬購物車的帳單地址欄位仍顯示（證明上面的隱藏來自本功能）', async ({ page }) => {
    const off = await wpPost(opts, SETTINGS_ENDPOINT, { virtual_hide_address: 'no' })
    expect(off.status).toBe(200)

    try {
      await openBlockCheckout(page, virtualProductId)
      await expect(page.locator('#billing-first_name')).toBeVisible({ timeout: BLOCK_TIMEOUT })
      await expect(countryInput(page).first()).toBeVisible()
      await expect(page.locator('#billing-address_1')).toBeVisible()
      await expect(page.locator('#billing-city')).toBeVisible()
      await expect(page.locator('#billing-postcode')).toBeVisible()
    } finally {
      await wpPost(opts, SETTINGS_ENDPOINT, { virtual_hide_address: 'yes' })
    }
  })

  test('含實體商品：地址欄位正常顯示（國家 / 地址 / 鄉鎮 / 郵遞區號）', async ({ page }) => {
    await openBlockCheckout(page, physicalProductId)

    // 實體商品需運送 → 顯示運送地址表單（預設「帳單同運送地址」，只會出現 shipping-* 欄位）
    await expect(page.locator('#shipping-first_name')).toBeVisible({ timeout: BLOCK_TIMEOUT })
    await expect(countryInput(page).first()).toBeVisible()
    await expect(page.locator('#shipping-address_1')).toBeVisible()
    await expect(page.locator('#shipping-city')).toBeVisible()
    await expect(page.locator('#shipping-postcode')).toBeVisible()

    await page.screenshot({ path: 'test-results/checkout-virtual-address-blocks-physical.png', fullPage: true })
  })
})
