/**
 * P8 錄影驗收：LINE Pay 直連（linepay）— 降級驗收
 *
 * 預設降級（藍圖 §7.3）：訪客結帳 → 後端 POST /v4/payments/request 成功 → 導向 LINE Pay sandbox 付款頁並載入完成。
 * `LIVE_LINEPAY_MANUAL=1` 時於 LINE Pay 頁 page.pause() 交由真人登入付款，續跑驗 processing。
 *
 * 憑證：LIVE_ENV_FILES 指向 LINE Pay .env.local（LINEPAY_SANDBOX_CHANNEL_ID / LINEPAY_SANDBOX_CHANNEL_SECRET）。
 */
import { expect, test } from '@playwright/test'
import { requireLiveEnv, LIVE_BASE_URL } from '../helpers/live-env.js'
import {
  AdminSession,
  caption,
  classicGuestCheckout,
  classicPlaceOrder,
  saveAcceptanceVideo,
} from '../helpers/live-setup.js'

const GATEWAY_ID = 'linepay'

test('LINE Pay：request 成功並載入 sandbox 付款頁', async ({ page, browser }) => {
  const creds = requireLiveEnv({
    channel_id: ['LINEPAY_SANDBOX_CHANNEL_ID'],
    channel_secret: ['LINEPAY_SANDBOX_CHANNEL_SECRET'],
  })
  const admin = await AdminSession.open(browser)
  try {
    await admin.ensureTaiwanStore()
    await admin.saveProviderSettings(GATEWAY_ID, { enabled: 'yes', mode: 'test', ...creds })
    const productId = await admin.ensureProduct({ sku: 'pc-live-linepay-150', name: '[PC Live] LINE Pay 150', price: 150, virtual: true })

    await classicGuestCheckout(page, productId, GATEWAY_ID)
    await caption(page, '訪客結帳 NT$150，選擇 LINE Pay')
    await page.waitForTimeout(1_500)
    await classicPlaceOrder(page)

    await page.waitForURL((url) => url.hostname.endsWith('line.me'), { timeout: 120_000 })
    await page.waitForLoadState('load')
    await page.waitForTimeout(4_000)
    const url = new URL(page.url())
    console.log(`[linepay] 付款頁：${url.origin}${url.pathname}`)
    expect(url.hostname).toMatch(/sandbox.*line\.me/)
    const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 300)
    console.log(`[linepay] 付款頁文字：${text}`)
    expect(text.length).toBeGreaterThan(0)
    await caption(page, 'LINE Pay sandbox 付款頁已載入（後端 request 成功 → paymentUrl.web）')
    await page.screenshot({ path: test.info().outputPath('linepay-payment-page.png'), fullPage: true })
    await page.waitForTimeout(4_000)

    // 後端 request 紀錄
    const orders = await admin.api<any[]>('GET', `wc/v3/orders?payment_method=${GATEWAY_ID}&per_page=1&orderby=id&order=desc`)
    const order = Array.isArray(orders.json) ? orders.json[0] : null
    expect(order).not.toBeNull()
    const notes = await admin.getOrderNotes(Number(order.id))
    console.log(`[linepay] 訂單 #${order.id} status=${order.status} notes=${JSON.stringify(notes.slice(0, 3))}`)
    expect(notes.some((n) => /LINE Pay 已建立付款請求/.test(n))).toBe(true)

    if (process.env.LIVE_LINEPAY_MANUAL === '1') {
      await caption(page, '人工段：請登入 LINE Pay sandbox 完成付款後按 Resume')
      await page.pause()
      await page.waitForURL((u) => u.toString().startsWith(LIVE_BASE_URL), { timeout: 300_000 })
      await expect.poll(async () => String((await admin.getOrder(Number(order.id))).status), { timeout: 120_000 }).toBe('processing')
    }
  } finally {
    await admin.close()
    await saveAcceptanceVideo(page, process.env.LIVE_LINEPAY_MANUAL === '1' ? 'linepay-full' : 'linepay-degraded')
  }
})
