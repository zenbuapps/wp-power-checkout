/**
 * P8 錄影驗收：黑貓PAY（tcat_pay）— 降級驗收（憑證為佔位值）
 *
 * 黑貓PAY .env.local 的 cust_id / password / hash_base 皆為 `<...>` 佔位 → 無法打真交易。
 * 黑貓PAY 設定頁沒有「測試連線」按鈕，因此以「實際結帳一次」作為連線測試：
 * 1. 設定頁填寫佔位值 → 儲存成功
 * 2. 訪客結帳選黑貓PAY 信用卡 → 後端向黑貓PAY 取 token 被拒 → 結帳頁顯示通用失敗訊息（不洩漏內部錯誤）
 *
 * 佔位值為明顯假值（非真實憑證）。
 */
import { expect, test } from '@playwright/test'
import {
  AdminSession,
  adminLogin,
  caption,
  classicGuestCheckout,
  classicPlaceOrder,
  clickSave,
  fillFormItem,
  openProviderSettingsPage,
  saveAcceptanceVideo,
} from '../helpers/live-setup.js'

const GATEWAY_ID = 'tcat_pay'

test('黑貓PAY：設定頁填寫儲存 + 結帳連線失敗訊息', async ({ page, browser }) => {
  const admin = await AdminSession.open(browser)
  try {
    await admin.ensureTaiwanStore()
    await admin.saveProviderSettings(GATEWAY_ID, { enabled: 'yes', mode: 'test', cust_id: '', api_password: '', hash_base: '' })
    const productId = await admin.ensureProduct({ sku: 'pc-live-tcatpay-200', name: '[PC Live] 黑貓PAY 200', price: 200, virtual: true })

    await adminLogin(page)
    await openProviderSettingsPage(page, '金流', '黑貓PAY')
    await caption(page, '黑貓PAY 設定頁：填寫佔位憑證（尚未取得正式 / 測試帳號）並儲存')
    await fillFormItem(page, '金流代號（cust_id）', 'placeholder-cust')
    await fillFormItem(page, 'API 密碼', 'placeholder-pass')
    await fillFormItem(page, 'hash_base', 'placeholder-hash')
    await clickSave(page)
    await page.waitForTimeout(2_000)

    const saved = await admin.getProviderSettings(GATEWAY_ID)
    expect(saved.cust_id).toBe('placeholder-cust')

    // 以結帳作為連線測試（設定頁無測試連線）
    await page.context().clearCookies()
    await classicGuestCheckout(page, productId, GATEWAY_ID)
    await caption(page, '訪客結帳選黑貓PAY（信用卡）：佔位憑證應被黑貓PAY 拒絕並顯示失敗訊息')
    const credit = page.locator('input[name="pc_tcat_pay_method"]').first()
    if (await credit.count() > 0) {
      await credit.check({ force: true })
    }
    await classicPlaceOrder(page)
    const error = page.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout, .wc-block-components-notice-banner.is-error').first()
    await expect(error).toBeVisible({ timeout: 120_000 })
    const message = (await error.innerText()).replace(/\s+/g, ' ')
    console.log(`[tcat_pay] 結帳錯誤訊息：${message}`)
    expect(page.url()).not.toMatch(/order-received/)
    await error.scrollIntoViewIfNeeded()
    await caption(page, `結帳失敗訊息：${message.slice(0, 80)}`)
    await page.waitForTimeout(4_000)

    const orders = await admin.api<any[]>('GET', `wc/v3/orders?payment_method=${GATEWAY_ID}&per_page=1&orderby=id&order=desc`)
    const order = Array.isArray(orders.json) ? orders.json[0] : null
    if (order) {
      console.log(`[tcat_pay] 訂單 #${order.id} status=${order.status} notes=${JSON.stringify((await admin.getOrderNotes(Number(order.id))).slice(0, 3))}`)
    }
  } finally {
    await admin.close()
    await saveAcceptanceVideo(page, 'tcat_pay-degraded')
  }
})
