/**
 * P1 — 後台 Vue「結帳」設定頁（checkout_enhancement）
 *
 * 依據：specs/features/settings/checkout-enhancement-settings.feature
 *       specs/open-issue/checkout-enhancement-implementation-plan.md 第 2 階段
 *
 * 測試情境（UI 操作，非純 API）：
 * - 進入 WC 設定 Power Checkout 分頁 → 點左側「結帳」選單 → 頁面出現 woomp 並存提示
 * - 切換開關、修改免運文案 → 儲存 → 重新整理後值仍在（並以 GET API 交叉確認已持久化）
 * - 「未達門檻文案」不含 {price} 時，前端阻擋送出（不發出 POST），並顯示錯誤訊息
 *
 * 選擇器：頁面元件以 data-testid="pc-checkout-<設定鍵>" 標記
 * （el-switch 落在根節點 .el-switch；el-input 落在原生 <input>）。
 */
import { test, expect, type Page } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import { wpGet, wpPost, createApiContext, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP } from '../fixtures/test-data.js'

type AnyRecord = Record<string, unknown>

const PROVIDER_ID = 'checkout_enhancement'
const SETTINGS_PAGE = `${BASE_URL}/wp-admin/admin.php?page=wc-settings&tab=power_checkout_wc_settings`
const WOOMP_NOTICE = '若同站 woomp 已開啟同名功能，請擇一啟用以免重複顯示或重複驗證'

/** 測試前重設的基準值（全部關閉 + 預設文案） */
const BASELINE: Record<string, string> = {
  last_name_first: 'no',
  tw_address: 'no',
  tw_phone_validation: 'no',
  virtual_hide_address: 'no',
  virtual_auto_complete: 'no',
  free_shipping_hint: 'no',
  free_shipping_text_left: '再買 {price} 元即可享免運',
  free_shipping_text_achieved: '已享免運',
  free_shipping_bg_color: '#d36f6f',
  free_shipping_text_color: '#ffffff',
}

const unwrap = (res: { data: unknown }) =>
  ((res.data as AnyRecord).data ?? res.data) as AnyRecord

const testId = (key: string) => `pc-checkout-${key}`

/** 進入 Power Checkout 設定分頁並點「結帳」選單 */
async function openCheckoutMenu(page: Page): Promise<void> {
  await page.goto(SETTINGS_PAGE)
  const menuItem = page.locator('#power-checkout-wc-setting-app .el-menu-item', { hasText: '結帳' })
  await expect(menuItem).toBeVisible({ timeout: 20_000 })
  // 等待設定 GET 回來再操作，避免讀取結果覆寫剛輸入的值
  const loaded = page.waitForResponse(
    (r) =>
      r.request().method() === 'GET' &&
      r.url().includes(`power-checkout/v1/settings/${PROVIDER_ID}`),
  )
  await menuItem.click()
  expect((await loaded).status()).toBe(200)
  await expect(page.getByTestId(testId('last_name_first'))).toBeVisible({ timeout: 20_000 })
}

// playwright.config 全域 extraHTTPHeaders 帶空的 X-WP-Nonce，會覆寫頁面內 axios 送出的 Nonce，
// 導致 SPA 的 REST 請求 403（rest_cookie_invalid_nonce）；UI 測試須清空此設定。
test.use({ extraHTTPHeaders: {} })

test.describe('後台「結帳」設定頁（checkout_enhancement）', () => {
  let opts: ApiOptions
  let ctx: APIRequestContext
  let original: AnyRecord = {}

  test.beforeAll(async () => {
    ctx = await createApiContext(BASE_URL)
    opts = { request: ctx, baseURL: BASE_URL, nonce: getNonce() }

    // 備份原設定，並重設為基準值
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDER_ID))
    if (res.status === 200) {
      original = unwrap(res)
    }
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDER_ID), BASELINE)
  })

  test.afterAll(async () => {
    // 還原原設定（失敗時退回基準值）
    const restore = Object.keys(original).length ? original : BASELINE
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDER_ID), restore).catch(() => {/* 忽略還原失敗 */})
    await ctx.dispose()
  })

  test('@smoke 點「結帳」選單後顯示 woomp 並存提示', async ({ page }) => {
    await openCheckoutMenu(page)
    await expect(page.getByText(WOOMP_NOTICE)).toBeVisible()
  })

  test('@happy 切換開關與修改文案後儲存，重新整理仍保留', async ({ page }) => {
    await openCheckoutMenu(page)

    const lastNameFirst = page.getByTestId(testId('last_name_first'))
    const phoneValidation = page.getByTestId(testId('tw_phone_validation'))
    const freeShippingHint = page.getByTestId(testId('free_shipping_hint'))

    // 基準值：全部關閉
    await expect(lastNameFirst).not.toHaveClass(/is-checked/)
    await expect(phoneValidation).not.toHaveClass(/is-checked/)
    await expect(freeShippingHint).not.toHaveClass(/is-checked/)

    await lastNameFirst.click()
    await phoneValidation.click()
    await freeShippingHint.click()

    const newTextLeft = '[E2E] 還差 {price} 元免運'
    const newTextAchieved = '[E2E] 恭喜免運'
    await page.getByTestId(testId('free_shipping_text_left')).fill(newTextLeft)
    await page.getByTestId(testId('free_shipping_text_achieved')).fill(newTextAchieved)

    // 預覽區即時反映（{price} 以範例金額替換）
    await expect(page.getByTestId('pc-checkout-preview')).toContainText('[E2E] 還差')

    const saveResponse = page.waitForResponse(
      (r) =>
        r.request().method() === 'POST' &&
        r.url().includes(`power-checkout/v1/settings/${PROVIDER_ID}`),
    )
    await page.getByTestId('pc-checkout-save').click()
    const resp = await saveResponse
    expect(resp.status(), await resp.text()).toBe(200)

    // 重新整理後再次進入「結帳」，值仍在
    await page.reload()
    await openCheckoutMenu(page)
    await expect(page.getByTestId(testId('last_name_first'))).toHaveClass(/is-checked/)
    await expect(page.getByTestId(testId('tw_phone_validation'))).toHaveClass(/is-checked/)
    await expect(page.getByTestId(testId('free_shipping_hint'))).toHaveClass(/is-checked/)
    await expect(page.getByTestId(testId('tw_address'))).not.toHaveClass(/is-checked/)
    await expect(page.getByTestId(testId('free_shipping_text_left'))).toHaveValue(newTextLeft)
    await expect(page.getByTestId(testId('free_shipping_text_achieved'))).toHaveValue(newTextAchieved)

    // API 交叉確認已持久化
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDER_ID))
    expect(res.status).toBe(200)
    const data = unwrap(res)
    expect(data.last_name_first).toBe('yes')
    expect(data.tw_phone_validation).toBe('yes')
    expect(data.free_shipping_hint).toBe('yes')
    expect(data.tw_address).toBe('no')
    expect(data.free_shipping_text_left).toBe(newTextLeft)
    expect(data.free_shipping_text_achieved).toBe(newTextAchieved)
  })

  test('@error 未達門檻文案不含 {price} 時前端阻擋送出', async ({ page }) => {
    await openCheckoutMenu(page)

    let postCount = 0
    page.on('request', (r) => {
      if (r.method() === 'POST' && r.url().includes(`power-checkout/v1/settings/${PROVIDER_ID}`)) {
        postCount++
      }
    })

    await page.getByTestId(testId('free_shipping_text_left')).fill('再買一點就免運')
    await page.getByTestId('pc-checkout-save').click()

    await expect(page.getByText('須包含 {price}')).toBeVisible()
    // 給予足夠時間確認沒有請求送出
    await page.waitForTimeout(1_500)
    expect(postCount).toBe(0)

    // 後端值未被改寫
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDER_ID))
    expect(unwrap(res).free_shipping_text_left).not.toBe('再買一點就免運')
  })
})
