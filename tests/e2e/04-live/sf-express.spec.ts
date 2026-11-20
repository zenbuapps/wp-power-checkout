/**
 * P8 錄影驗收：順豐速運（sf_express）— 公開沙箱 SIT
 *
 * 憑證：順豐官方公開「測試環境授權信息」（skill sf-express onboarding-and-faq.md §8），執行時解析，不寫入 repo。
 * SIT 非正式系統（orchestrator 已核准建單）。
 *
 * 1. 設定頁 → 測試連線（取 token → 加密 → 簽章 → 查不存在運單，唯讀）成功
 * 2. 嘗試 SIT 建單（REST create-shipment）→ 成功則列印面單（PDF 於瀏覽器顯示）→ 查詢軌跡
 * 3. 以正確 sign（Base64(MD5(key+body+key))）送一筆軌跡推送 → 訂單貨態更新（order note）
 *    建單被拒時降級：以 REST 在測試訂單寫入一個測試運單號（僅供推送比對）再送推送
 */
import { createHash } from 'crypto'
import { expect, test } from '@playwright/test'
import { LIVE_BASE_URL, sfPublicSandboxCredentials } from '../helpers/live-env.js'
import {
  AdminSession,
  adminLogin,
  caption,
  clickPing,
  openProviderSettingsPage,
  saveAcceptanceVideo,
} from '../helpers/live-setup.js'
import { callLogistics, renderPanel, showOrderNotes, showPdf } from '../helpers/live-logistics.js'

const PROVIDER_ID = 'sf_express'

test('順豐：測試連線 + SIT 建單 / 列印 / 查詢 + 簽章推送更新貨態', async ({ page, browser }) => {
  const creds = sfPublicSandboxCredentials()
  const pushKey = `pc-live-push-${Date.now()}`
  const admin = await AdminSession.open(browser)
  let level = 'full'
  try {
    await admin.ensureTaiwanStore()
    await admin.saveProviderSettings(PROVIDER_ID, {
      enabled: 'yes',
      mode: 'test',
      ...creds,
      push_sign_key: pushKey,
      sender_name: 'PC Live 測試',
      sender_mobile: '0912345678',
      sender_tel: '',
      sender_company: 'PC Live',
      sender_postcode: '110',
      sender_address: '台北市信義區信義路五段7號',
    })
    const productId = await admin.ensureProduct({ sku: 'pc-live-logistics-1kg', name: '[PC Live] 物流測試商品 1kg', price: 500, virtual: false, weight: '1' })
    const created = await admin.api('POST', 'wc/v3/orders', {
      status: 'processing',
      payment_method: 'bacs',
      payment_method_title: '銀行轉帳',
      billing: { first_name: '測試', last_name: '王', phone: '0912345678', email: 'pc-live-acceptance@example.com', country: 'TW', postcode: '407', state: '', city: '台中市西屯區', address_1: '臺灣大道三段99號' },
      shipping: { first_name: '測試', last_name: '王', phone: '0912345678', country: 'TW', postcode: '407', state: '', city: '台中市西屯區', address_1: '臺灣大道三段99號' },
      line_items: [{ product_id: productId, quantity: 1 }],
    })
    expect(created.status, created.text.slice(0, 300)).toBe(201)
    const orderId = Number(created.json.id)
    console.log(`[sf] 測試訂單 #${orderId}`)

    // 1. 設定頁 + 測試連線
    await adminLogin(page)
    await openProviderSettingsPage(page, '物流', '順豐速運')
    await caption(page, '順豐設定頁（沙箱，官方公開測試憑證）→ 測試連線')
    const ping = await clickPing(page)
    console.log(`[sf] 測試連線：${ping}`)
    expect(ping).toMatch(/^HTTP 200/)
    await expect(page.locator('.el-alert--success', { hasText: '連線成功（沙箱）' })).toBeVisible({ timeout: 30_000 })
    await page.waitForTimeout(3_000)

    // 2. SIT 建單
    const create = await callLogistics(page, orderId, PROVIDER_ID, 'create-shipment', `順豐 SIT 建單（訂單 #${orderId}）`)
    console.log(`[sf] 建單：HTTP ${create.status} ${JSON.stringify(create.json).slice(0, 400)}`)
    let waybill = String(create.json?.data?.logistics_id ?? '')

    if (create.status === 200 && waybill) {
      const print = await callLogistics(page, orderId, PROVIDER_ID, 'print', `順豐列印面單（運單 ${waybill}）`)
      console.log(`[sf] 列印：HTTP ${print.status} ${print.contentType} ${print.body.length} bytes`)
      if (print.contentType.includes('pdf')) {
        await showPdf(page, print.body, test.info().outputPath(`sf-label-${waybill}.pdf`))
      } else {
        console.log(`[sf] 列印回應非 PDF：${print.body.toString('utf-8').slice(0, 300)}`)
      }
      const query = await callLogistics(page, orderId, PROVIDER_ID, '', `順豐查詢軌跡（運單 ${waybill}）`)
      console.log(`[sf] 查詢：HTTP ${query.status} ${JSON.stringify(query.json).slice(0, 400)}`)
    } else {
      // 降級：建單被 SIT 拒絕 → 寫入測試運單號供推送比對
      level = 'degraded'
      waybill = `SFLIVE${Date.now()}`
      const meta = await admin.api('PUT', `wc/v3/orders/${orderId}`, { meta_data: [{ key: '_pc_sf_express_ref', value: waybill }, { key: '_pc_sf_express_provider_id', value: PROVIDER_ID }] })
      expect(meta.status).toBe(200)
      console.log(`[sf] 建單被拒 → 降級，寫入測試運單號 ${waybill}`)
    }

    // 3. 簽章推送
    const now = new Date(Date.now() + 8 * 3600_000).toISOString().replace('T', ' ').slice(0, 19)
    const body = JSON.stringify([{
      gmt: 'GMT+08:00',
      localTm: now,
      opCode: '50',
      opCodeTranslation: '已收件',
      reasonCode: '',
      reasonCodeTranslation: '',
      sfWaybillNo: waybill,
      trackAddr: '',
      trackCity: 'Taipei City',
      trackCountry: 'TW',
      trackPostCode: '',
      trackProvince: '',
      trackRemark: 'P8 live 驗收：順豐已收件',
      trackUniqueId: `pc-live-${Date.now()}`,
    }])
    const sign = createHash('md5').update(pushKey + body + pushKey, 'utf8').digest('base64')
    const pushUrl = `${LIVE_BASE_URL}/wp-json/power-checkout/sf-express/track-push`
    const bad = await page.request.post(pushUrl, { headers: { 'Content-Type': 'application/json', sign: 'invalid-sign' }, data: body })
    const badText = JSON.stringify(await bad.json())
    const push = await page.request.post(pushUrl, { headers: { 'Content-Type': 'application/json', sign }, data: body })
    const pushJson = await push.json()
    const pushText = JSON.stringify(pushJson)
    console.log(`[sf] 錯誤 sign 推送：HTTP ${bad.status()} ${badText}`)
    console.log(`[sf] 正確 sign 推送：HTTP ${push.status()} ${pushText}`)
    await renderPanel(page, '順豐軌跡推送（opCode 50 已收件）', `POST ${pushUrl}`, `錯誤 sign → HTTP ${bad.status()} ${badText}\n\n正確 sign → HTTP ${push.status()} ${pushText}`)
    expect(push.status()).toBe(200)
    expect(pushJson.code).toBe('0')
    expect(pushJson.data?.[0]?.msg).toBe('成功')
    expect(badText).toContain('簽章驗證失敗')

    const state = await admin.getOrderMeta(orderId, '_pc_sf_express_unified_state')
    console.log(`[sf] 推送後 unified_state=${String(state)} status_name=${String(await admin.getOrderMeta(orderId, '_pc_sf_express_status_name'))}`)
    expect(String(state)).toBe('accepted')
    await showOrderNotes(page, orderId, `訂單 #${orderId}：順豐運單 ${waybill}，推送後貨態更新為「已收件」（見 order notes）`)
    test.info().annotations.push({ type: 'level', description: level })
  } finally {
    await admin.close()
    await saveAcceptanceVideo(page, `sf_express-${level}`)
  }
})
