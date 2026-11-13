/**
 * 傳統結帳 — 結帳強化 6 個開關全開時的既有欄位回歸（計畫 R4）
 *
 * 對應計畫：specs/open-issue/checkout-enhancement-implementation-plan.md 第 7 階段、風險 R4
 *   「欄位排序 / 隱藏影響既有結帳欄位」：結帳強化會動 woocommerce_checkout_fields 的順序與顯示，
 *   必須確認既有的發票欄位、ECPay AIO 信用卡分期選單、物流選店按鈕仍在且可操作。
 *
 * 6 個開關：last_name_first / tw_address / tw_phone_validation / virtual_hide_address /
 *           virtual_auto_complete / free_shipping_hint
 *
 * 只驗「仍在且可操作」，不碰卡號輸入、不實際導轉付款 / 選店：
 *  - 發票：#_pc_issue_invoice_params 欄位仍在；Vue 掛載的「設定發票資訊」按鈕可開啟對話框
 *  - ECPay AIO 分期：選用 ecpay_aio 後出現分期下拉（#pc_ecpay_aio_installment），可選期數
 *  - 物流選店：選用綠界物流（超商取貨）後出現「選擇門市」按鈕，點擊會送出選店請求
 *    （請求以 page.route 攔截並回傳錯誤，避免導轉到綠界；驗證按鈕回復可再操作並顯示錯誤提示）
 *  - 另以兩個斷言證明開關確實生效（姓名排序、台灣縣市下拉），避免回歸測試在「功能其實沒開」時恆真
 *
 * 測試資料（spec 自行建立 / 清理）：
 *  - 結帳強化 6 開關暫設為 yes（REST，結束時還原原值）
 *  - ecpay_aio 的 enableInstallment 暫設為 true（REST，結束時還原原值；installmentPeriods 沿用站台設定）
 *  - 傳統結帳頁（[woocommerce_checkout] shortcode，站台預設結帳頁是區塊型態）
 *  - 實體商品「[E2E] 回歸帆布袋」(300) + 運送區域「[E2E] 回歸-台灣」(TW) 內的 ecpay_logistics 運送方式
 *
 * 前置假設（需由開發站滿足，spec 不改動這些全站設定）：
 *  - 已啟用：ecpay_aio（allowedPayments 含 Credit、installmentPeriods 非空）、ecpay_logistics
 *    （enabled_methods 含 UNIMART / FAMI 其一）、至少一個發票 provider（如 amego）
 *  - 商店預設國家 TW、預設客戶位置為商店基本地址
 *  - 已執行 pnpm build（js/dist）
 *
 * 執行（wp-env 開發站）：
 *   cd tests/e2e
 *   E2E_BASE_URL=http://localhost:8891 E2E_ADMIN_USER=admin E2E_ADMIN_PASS=password npx playwright test checkout-enhancement-regression
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test'
import { wpGet, wpPost, wpDelete, createApiContext, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL } from '../fixtures/test-data.js'

const SETTINGS_ENDPOINT = 'power-checkout/v1/settings/checkout_enhancement'
const AIO_ENDPOINT = 'power-checkout/v1/settings/ecpay_aio'

/** 結帳強化 6 個開關 */
const TOGGLE_KEYS = [
  'last_name_first',
  'tw_address',
  'tw_phone_validation',
  'virtual_hide_address',
  'virtual_auto_complete',
  'free_shipping_hint',
] as const

const STORE_SELECTION_PATTERN = '**/wp-json/power-checkout/v1/logistics/store-selection'

/** 傳統結帳以 AJAX 重繪區塊（update_order_review），元素等待時間放寬 */
const UI_TIMEOUT = 20_000

test.describe('傳統結帳 — 結帳強化 6 開關全開時，既有欄位回歸', () => {
  test.setTimeout(90_000)

  // 訪客購物車（Cookie session），避免 admin 持久購物車造成測試間污染
  // extraHTTPHeaders 清空：playwright 全域設定的 X-WP-Nonce: '' 會讓頁面內 REST / ajax 請求 403
  test.use({ storageState: { cookies: [], origins: [] }, extraHTTPHeaders: {} })

  let ctx: APIRequestContext
  let opts: ApiOptions
  const originalToggles: Record<string, string | undefined> = {}
  /** ecpay_aio 原本的 enableInstallment（undefined = 尚未成功讀取，不還原） */
  let originalEnableInstallment: unknown
  let aioSettingsRead = false
  let pageId = 0
  let productId = 0
  let zoneId = 0
  let classicCheckoutUrl = ''

  test.beforeAll(async () => {
    ctx = await createApiContext(BASE_URL)
    opts = { request: ctx, baseURL: BASE_URL, nonce: getNonce() }

    // 1. 記住原設定，6 開關全開
    const current = await wpGet(opts, SETTINGS_ENDPOINT)
    const currentData = (((current.data as Record<string, unknown>).data ?? current.data) ?? {}) as Record<string, string>
    for (const key of TOGGLE_KEYS) originalToggles[key] = currentData[key]
    const allOn = Object.fromEntries(TOGGLE_KEYS.map((key) => [key, 'yes']))
    const updated = await wpPost(opts, SETTINGS_ENDPOINT, allOn)
    expect(updated.status, '6 開關全開失敗').toBe(200)

    // 2. ecpay_aio 開啟分期選單（前置：provider 已啟用且信用卡有可選期數）
    const aio = await wpGet(opts, AIO_ENDPOINT)
    expect(aio.status, '讀取 ecpay_aio 設定失敗（provider 未註冊？）').toBe(200)
    const aioData = (((aio.data as Record<string, unknown>).data ?? aio.data) ?? {}) as Record<string, unknown>
    originalEnableInstallment = aioData.enableInstallment
    aioSettingsRead = true
    const aioUpdated = await wpPost(opts, AIO_ENDPOINT, { enableInstallment: true })
    expect(aioUpdated.status, '開啟 ecpay_aio 分期失敗').toBe(200)

    // 3. 傳統結帳頁（站台預設結帳頁可能是區塊結帳）
    const page = await wpPost<{ id: number; link: string }>(opts, 'wp/v2/pages', {
      title: '[E2E] 回歸-傳統結帳',
      status: 'publish',
      content: '[woocommerce_checkout]',
    })
    expect(page.status).toBeLessThan(300)
    pageId = page.data.id
    classicCheckoutUrl = page.data.link

    // 4. 實體商品（物流選店只在需要運送時出現）
    const product = await wpPost<{ id: number }>(opts, 'wc/v3/products', {
      name: '[E2E] 回歸帆布袋',
      type: 'simple',
      regular_price: '300',
      virtual: false,
      status: 'publish',
    })
    expect(product.status).toBeLessThan(300)
    productId = product.data.id

    // 5. 運送區域 TW + 綠界物流運送方式
    const zone = await wpPost<{ id: number }>(opts, 'wc/v3/shipping/zones', { name: '[E2E] 回歸-台灣' })
    expect(zone.status).toBeLessThan(300)
    zoneId = zone.data.id
    await wpPost(opts, `wc/v3/shipping/zones/${zoneId}/locations`, [
      { code: 'TW', type: 'country' },
    ] as unknown as Record<string, unknown>)
    const method = await wpPost(opts, `wc/v3/shipping/zones/${zoneId}/methods`, { method_id: 'ecpay_logistics' })
    expect(method.status, '運送區域新增 ecpay_logistics 運送方式失敗（物流 provider 未啟用？）').toBeLessThan(300)
  })

  test.afterAll(async () => {
    // 原本沒有儲存過的鍵（undefined）以預設值 no 還原
    const restore = Object.fromEntries(TOGGLE_KEYS.map((key) => [key, originalToggles[key] ?? 'no']))
    await wpPost(opts, SETTINGS_ENDPOINT, restore).catch(() => { /* 忽略還原錯誤 */ })
    if (aioSettingsRead) {
      await wpPost(opts, AIO_ENDPOINT, { enableInstallment: originalEnableInstallment ?? false }).catch(() => { /* 忽略還原錯誤 */ })
    }
    if (pageId) await wpDelete(opts, `wp/v2/pages/${pageId}?force=true`).catch(() => {})
    if (productId) await wpDelete(opts, `wc/v3/products/${productId}?force=true`).catch(() => {})
    if (zoneId) await wpDelete(opts, `wc/v3/shipping/zones/${zoneId}?force=true`).catch(() => {})
    await ctx.dispose()
  })

  /** 加入購物車並進入傳統結帳頁，等到帳單欄位與運送方式渲染完成 */
  async function openClassicCheckout(page: Page): Promise<void> {
    await page.goto(`${BASE_URL}/?add-to-cart=${productId}`, { timeout: 30_000 })
    await page.goto(classicCheckoutUrl, { timeout: 30_000 })
    await expect(page.locator('#billing_country')).toBeAttached({ timeout: UI_TIMEOUT })
    await expect(page.locator('input[name^="shipping_method"]').first()).toBeAttached({ timeout: UI_TIMEOUT })
  }

  test('開關確實生效：姓氏排在名字前、台灣縣市下拉出現（避免回歸測試恆真）', async ({ page }) => {
    await openClassicCheckout(page)

    // last_name_first：姓氏欄位在 DOM 中先於名字欄位
    const order = await page.evaluate(() => {
      const last = document.querySelector('#billing_last_name_field')
      const first = document.querySelector('#billing_first_name_field')
      if (!last || !first) return 'missing'
      return last.compareDocumentPosition(first) & Node.DOCUMENT_POSITION_FOLLOWING ? 'last-first' : 'first-last'
    })
    expect(order, '姓氏欄位應排在名字欄位之前').toBe('last-first')

    // tw_address：TW 國家的 state 為縣市下拉（22 個縣市 + 佔位）
    await page.selectOption('#billing_country', 'TW')
    await expect(page.locator('select#billing_state option:not([value=""])')).toHaveCount(22, { timeout: UI_TIMEOUT })
  })

  test('發票欄位仍在，「設定發票資訊」按鈕可開啟對話框', async ({ page }) => {
    await openClassicCheckout(page)

    // 欄位本身（CheckoutFields 註冊的 _pc_issue_invoice_params，billing 區）
    await expect(page.locator('#_pc_issue_invoice_params_field')).toBeAttached({ timeout: UI_TIMEOUT })
    await expect(page.locator('#_pc_issue_invoice_params')).toBeAttached()

    // Vue 於欄位旁掛載（延遲 1 秒）的按鈕
    const button = page.locator('#_pc_issue_invoice_params_container').getByRole('button', { name: '設定發票資訊' })
    await expect(button).toBeVisible({ timeout: UI_TIMEOUT })
    await expect(button).toBeEnabled()

    await button.click()
    const dialog = page.getByRole('dialog', { name: '設定發票資訊' })
    await expect(dialog).toBeVisible({ timeout: UI_TIMEOUT })

    await page.screenshot({ path: 'test-results/checkout-enhancement-regression-invoice.png', fullPage: true })
  })

  test('ECPay AIO 分期選單：選用 ecpay_aio 後出現，可選擇期數', async ({ page }) => {
    await openClassicCheckout(page)

    await page.locator('label[for="payment_method_ecpay_aio"]').click()
    const select = page.locator('#pc_ecpay_aio_installment')
    await expect(select).toBeVisible({ timeout: UI_TIMEOUT })
    await expect(select).toBeEnabled()

    // 第一項固定為「不分期」（value ''），其餘為後台白名單期數
    await expect(select.locator('option').first()).toHaveAttribute('value', '')
    await expect(select.locator('option[value="6"]')).toHaveCount(1)

    await select.selectOption('6')
    await expect(select).toHaveValue('6')

    await page.screenshot({ path: 'test-results/checkout-enhancement-regression-installment.png', fullPage: true })
  })

  test('物流選店按鈕：選用超商取貨後出現且可點擊（選店請求被攔截，不導轉綠界）', async ({ page }) => {
    // 攔截選店請求：回傳錯誤，讓 classic-logistics.js 走「顯示錯誤並還原按鈕」分支而不導轉
    let postHits = 0
    await page.route(STORE_SELECTION_PATTERN, async (route) => {
      const headers = {
        'access-control-allow-origin': '*',
        'access-control-allow-headers': '*',
        'access-control-allow-methods': 'POST, OPTIONS',
      }
      if (route.request().method() === 'OPTIONS') {
        await route.fulfill({ status: 204, headers })
        return
      }
      postHits += 1
      await route.fulfill({
        status: 500,
        headers,
        contentType: 'application/json',
        body: JSON.stringify({ code: 'error', message: 'e2e mock', data: null }),
      })
    })

    await openClassicCheckout(page)

    // 選用超商取貨（UNIMART / FAMI 任一，依站台 enabled_methods）
    const cvsRate = page.locator('input[name^="shipping_method"][value^="ecpay_logistics"]').first()
    await expect(cvsRate).toBeAttached({ timeout: UI_TIMEOUT })
    if (!(await cvsRate.isChecked())) {
      await cvsRate.check()
    }

    const storeButton = page.locator('#pc-classic-logistics-select-store')
    await expect(storeButton).toBeVisible({ timeout: UI_TIMEOUT })
    await expect(storeButton).toBeEnabled()
    await expect(storeButton).toHaveText('選擇門市')

    await page.screenshot({ path: 'test-results/checkout-enhancement-regression-logistics.png', fullPage: true })

    await storeButton.click()

    // 選店請求有送出；因被攔截為錯誤，按鈕回復可再操作並顯示錯誤提示（未導轉）
    await expect(page.locator('#pc-classic-logistics-error')).toBeVisible({ timeout: UI_TIMEOUT })
    expect(postHits).toBeGreaterThan(0)
    await expect(storeButton).toBeEnabled()
    await expect(storeButton).toHaveText('選擇門市')
    expect(page.url()).toContain(new URL(classicCheckoutUrl).pathname)
  })
})
