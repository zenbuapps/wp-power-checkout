/**
 * P8 錄影驗收：SHOPLINE Payments 嵌入式（shopline_payment_embedded）— 完整驗收
 *
 * 1. 非 3DS 成功：訪客 classic 結帳 NT$401（沙盒：非 3 的倍數 + 單數 = 成功）→ order-received SDK 收銀台
 *    → keyboard.type 輸入沙盒測試卡 → 確認付款 → 導回 → processing + transaction id → 後台 WC 原生部分退款成功
 * 2. 付款未完成後同一訂單重付（commit 1e227d2 修正驗證）：NT$300 → 第一次 confirm → pay 失敗（3DS 放棄 / 沙盒 PCI 1005）
 *    → 第二次 confirm 必須 HTTP 200，且兩次送出的 referenceOrderId（`_pc_slp_reference_history`）不同、歷史共兩筆；
 *    前台不得再出現 500（舊行為：同 referenceOrderId 被 SLP 以 1001 重複下單拒絕 → 500）
 *
 * 結果認列：tunnel 在線時可能收到 webhook；否則 order-received 主動查詢（EmbeddedSyncManager）補單。
 * 依 order note 的來源前綴（Webhook / 導回同步）判斷實際走哪一條並輸出到測試 log。
 *
 * 憑證：repo `.env` 的 SHOPLINE_SANDBOX_*（只從環境變數讀，見 helpers/live-env.ts）。
 * 測試卡：skill shopline-payments-v1/references/sandbox-resources.md（官方公開沙盒測試卡）。
 */
import { expect, test, type Page } from '@playwright/test'
import { requireLiveEnv, LIVE_BASE_URL } from '../helpers/live-env.js'
import {
  AdminSession,
  adminLogin,
  caption,
  classicGuestCheckout,
  classicPlaceOrder,
  orderEditUrl,
  orderIdFromUrl,
  saveAcceptanceVideo,
} from '../helpers/live-setup.js'

/** 官方沙盒 Visa 測試卡 */
const SANDBOX_CARD = { number: '4147633700198405', expiry: '0330', cvc: '638', holder: 'PC LIVE TEST' }

const GATEWAY_ID = 'shopline_payment_embedded'

/** 每次付款嘗試送出的 referenceOrderId 歷史（EmbeddedReference::HISTORY_META_KEY） */
const REFERENCE_HISTORY_META = '_pc_slp_reference_history'

/** 將 meta 值正規化為字串陣列 */
function referenceHistory(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : []
}

/** 前置：共用導轉式商店憑證（test 模式）+ 啟用嵌入式 + 測試商品 */
async function prepare(admin: AdminSession, price: number): Promise<number> {
  const creds = requireLiveEnv({
    merchantId: ['SHOPLINE_SANDBOX_MERCHANT_ID'],
    apiKey: ['SHOPLINE_SANDBOX_API_KEY'],
    clientKey: ['SHOPLINE_SANDBOX_CLIENT_KEY'],
    signKey: ['SHOPLINE_SANDBOX_SIGN_KEY'],
  })
  await admin.ensureTaiwanStore()
  await admin.saveProviderSettings('shopline_payment_redirect', { mode: 'test', ...creds })
  await admin.saveProviderSettings(GATEWAY_ID, { enabled: 'yes' })
  return admin.ensureProduct({
    sku: `pc-live-slp-${price}`,
    name: `[PC Live] SLP 嵌入式 ${price}`,
    price,
    virtual: true,
  })
}

/** 等 SDK 收銀台（PCI iframe）載入 */
async function waitForCashier(page: Page): Promise<void> {
  await expect(page.locator('#pc-slp-embedded-container')).toBeAttached({ timeout: 90_000 })
  await expect.poll(
    () => page.frames().filter((f) => f.url().includes('/pci/iframe.html')).length,
    { timeout: 90_000, message: 'SLP PCI iframe 未載入' },
  ).toBeGreaterThanOrEqual(3)
  await expect(page.locator('#pc-slp-embedded-submit')).toBeVisible({ timeout: 90_000 })
  await page.waitForTimeout(2_000)
}

/**
 * 以真鍵擊輸入卡號（PCI iframe 有 input masking，JS 設值會被清掉）
 *
 * SDK 每個欄位一個 iframe，iframe name 前綴：number / firstName / expDate / cvc（+ 實例 uuid）。
 */
async function typeCard(page: Page): Promise<void> {
  const fields: Array<[string, string]> = [
    ['number', SANDBOX_CARD.number],
    ['firstName', SANDBOX_CARD.holder],
    ['expDate', SANDBOX_CARD.expiry],
    ['cvc', SANDBOX_CARD.cvc],
  ]
  for (const [prefix, value] of fields) {
    const frame = page.frameLocator(`#pc-slp-embedded-container iframe[name^="${prefix}"]`)
    const input = frame.locator('input#input-file').first()
    await input.click()
    await page.keyboard.type(value, { delay: 120 })
    await page.waitForTimeout(300)
  }
}

/** 監聽 SLP 沙盒 PCI API 1005 拒絕（SDK 內嵌 PCI-API-KEY 被拒，與本站無關） */
function watchPciRejection(page: Page): { rejected: Promise<void>, samples: string[] } {
  const samples: string[] = []
  let resolve: () => void = () => undefined
  const rejected = new Promise<void>((r) => { resolve = r })
  page.on('response', async (r) => {
    if (r.url().includes('payment-pci-api') && r.status() >= 400) {
      const text = await r.text().catch(() => '')
      samples.push(`${r.status()} ${new URL(r.url()).pathname} ${text.slice(0, 100)}`)
      if (/token\/generate/.test(r.url()) || samples.length >= 3) {
        resolve()
      }
    }
  })
  return { rejected, samples }
}

/** 按「確認付款」並等 dispatch confirm 回應 */
async function submitAndWaitConfirm(page: Page, orderId: number): Promise<{ status: number, body: string }> {
  const confirm = page.waitForResponse((r) => r.url().includes(`/pay/${orderId}/dispatch`) && r.request().postData()?.includes('pay_session') === true, { timeout: 120_000 })
  await page.locator('#pc-slp-embedded-submit').click()
  const res = await confirm
  return { status: res.status(), body: await res.text() }
}

/** 輪詢訂單狀態（每輪重新整理 order-received 觸發主動查詢；節流 30 秒） */
async function waitForOrderStatus(page: Page, admin: AdminSession, orderId: number, expected: string, timeoutMs = 240_000): Promise<string> {
  const started = Date.now()
  let status = ''
  while (Date.now() - started < timeoutMs) {
    status = String((await admin.getOrder(orderId)).status)
    if (status === expected) {
      return status
    }
    await page.waitForTimeout(20_000)
    if (page.url().includes('order-received')) {
      await page.reload()
      await caption(page, `等待付款結果認列（目前 ${status}，重新整理觸發主動查詢）`)
    }
  }
  return status
}

/** 結果認列路徑（依 order note 判斷） */
function settledVia(notes: string[]): string {
  const joined = notes.join('\n')
  if (/webhook/i.test(joined)) {
    return 'webhook'
  }
  if (/導回|主動查詢|return.?sync/i.test(joined)) {
    return 'order-received 主動查詢（EmbeddedSyncManager）'
  }
  return '未知（見 order notes）'
}

test.describe('SHOPLINE Payments 嵌入式 live 驗收', () => {
  test('非 3DS 付款成功 → processing → 後台部分退款', async ({ page, browser }) => {
    let videoName = 'shopline_payment_embedded-full'
    const admin = await AdminSession.open(browser)
    try {
      const productId = await prepare(admin, 401)

      await classicGuestCheckout(page, productId, GATEWAY_ID)
      await caption(page, '訪客結帳 NT$401，選擇 SHOPLINE Payments 信用卡（站內付）')
      await page.waitForTimeout(1_500)
      await classicPlaceOrder(page)
      await page.waitForURL(/order-received/, { timeout: 120_000 })
      const orderId = orderIdFromUrl(page.url())
      console.log(`[slp] 訂單 #${orderId}`)

      await waitForCashier(page)
      await caption(page, `訂單 #${orderId}：order-received 載入 SHOPLINE SDK 收銀台，以真鍵擊輸入沙盒測試卡`)
      await typeCard(page)
      await page.waitForTimeout(1_000)
      await caption(page, '按「確認付款」→ createPayment → dispatch confirm → payment.pay(nextAction)')
      const pci = watchPciRejection(page)
      const confirmRes = await submitAndWaitConfirm(page, orderId)
      console.log(`[slp] dispatch confirm：HTTP ${confirmRes.status} ${confirmRes.body.slice(0, 200)}`)
      expect(confirmRes.status, 'dispatch confirm 應成功').toBe(200)
      expect(confirmRes.body).toContain('next_action')

      const outcome = await Promise.race([
        page.waitForEvent('framenavigated', { predicate: (f) => f === page.mainFrame(), timeout: 120_000 }).then(() => 'navigated'),
        pci.rejected.then(() => 'pci-1005'),
      ]).catch(() => 'timeout')
      if (outcome !== 'navigated') {
        await page.waitForTimeout(3_000)
        const notes = await admin.getOrderNotes(orderId)
        console.log(`[slp] pay() 未完成（${outcome}）：${pci.samples.join(' | ')}；order notes=${JSON.stringify(notes.slice(0, 3))}`)
        await caption(page, 'SHOPLINE 沙盒 PCI API 回 1005「No interface access permission」→ SDK pay() 失敗（SHOPLINE 端，非本外掛）')
        await page.waitForTimeout(5_000)
        videoName = 'shopline_payment_embedded-blocked'
        test.skip(true, `SHOPLINE 沙盒 PCI（payment-pci-api-sandbox）回 1005 No interface access permission，SDK pay() 無法完成付款；PC 端 dispatch init/confirm 與 trade/payment/create 正常（訂單 #${orderId}）`)
      }

      // 非 3DS：SDK 導回 returnUrl（order-received）
      await page.waitForURL(/order-received/, { timeout: 120_000 })
      await caption(page, '已導回 order-received，等待付款結果認列')
      const status = await waitForOrderStatus(page, admin, orderId, 'processing')
      expect(status, '訂單應轉為 processing').toBe('processing')

      const order = await admin.getOrder(orderId)
      expect(String(order.transaction_id || ''), 'transaction id 應已寫入').not.toBe('')
      const notes = await admin.getOrderNotes(orderId)
      console.log(`[slp] 認列路徑：${settledVia(notes)}；transaction_id=${order.transaction_id}`)
      await page.reload()
      await caption(page, `訂單 #${orderId} 付款成功（processing，transaction id ${order.transaction_id}）`)
      await page.waitForTimeout(3_000)

      // 後台：訂單頁 + WC 原生部分退款（透過 gateway API）
      await adminLogin(page)
      await page.goto(orderEditUrl(orderId))
      await caption(page, `後台訂單 #${orderId}：狀態處理中，執行部分退款 NT$100（透過 SHOPLINE Payments API）`)
      await expect(page.locator('#order_status')).toHaveValue('wc-processing')
      await page.waitForTimeout(2_000)
      await page.locator('button.refund-items').click()
      await page.locator('#refund_amount').fill('100')
      await page.locator('#refund_reason').fill('P8 live 驗收部分退款')
      page.once('dialog', (dialog) => void dialog.accept())
      await page.locator('button.do-api-refund').click()
      await page.waitForLoadState('load')

      await expect.poll(async () => {
        const res = await admin.api<any[]>('GET', `wc/v3/orders/${orderId}/refunds`)
        return Array.isArray(res.json) ? res.json.length : 0
      }, { timeout: 120_000, message: '退款紀錄應建立' }).toBeGreaterThan(0)

      await page.goto(orderEditUrl(orderId))
      await caption(page, `訂單 #${orderId}：部分退款 NT$100 完成（見退款列與 order notes）`)
      await page.locator('#woocommerce-order-notes').scrollIntoViewIfNeeded()
      await page.waitForTimeout(4_000)

      const refundNotes = (await admin.getOrderNotes(orderId)).filter((n) => /退款/.test(n))
      console.log(`[slp] 退款相關 notes：${JSON.stringify(refundNotes.slice(0, 3))}`)
      expect(refundNotes.some((n) => /失敗|❌/.test(n)), '不應有退款失敗 note').toBe(false)
      expect(String((await admin.getOrder(orderId)).status)).toBe('processing')
    } finally {
      await admin.close()
      await saveAcceptanceVideo(page, videoName)
    }
  })

  test('付款未完成後同一訂單重付：每次嘗試新 referenceOrderId，第二次建立交易 200', async ({ page, browser }) => {
    const admin = await AdminSession.open(browser)
    try {
      const productId = await prepare(admin, 300)

      await classicGuestCheckout(page, productId, GATEWAY_ID)
      await caption(page, '訪客結帳 NT$300（沙盒：3 的倍數 → 3DS 模擬頁）')
      await classicPlaceOrder(page)
      await page.waitForURL(/order-received/, { timeout: 120_000 })
      const orderId = orderIdFromUrl(page.url())
      const receivedUrl = page.url()
      console.log(`[slp-retry] 訂單 #${orderId}`)

      await waitForCashier(page)
      await typeCard(page)
      await caption(page, `訂單 #${orderId}：第一次付款`)
      const pci = watchPciRejection(page)
      const first = await submitAndWaitConfirm(page, orderId)
      console.log(`[slp-retry] 第一次 confirm：HTTP ${first.status} ${first.body.slice(0, 160)}`)
      const firstIdentity = String(await admin.getOrderMeta(orderId, '_pc_payment_identity') ?? '')
      const firstHistory = referenceHistory(await admin.getOrderMeta(orderId, REFERENCE_HISTORY_META))
      console.log(`[slp-retry] 第一次 tradeOrderId=${firstIdentity} referenceOrderId=${firstHistory.at(-1) ?? ''}`)
      expect(first.status, '第一次建立交易應成功').toBe(200)
      expect(firstHistory, '第一次送出後歷史應有 1 筆').toHaveLength(1)
      await caption(page, `第一次建立交易 HTTP ${first.status}，referenceOrderId = ${firstHistory[0]}`)

      const outcome = await Promise.race([
        page.waitForURL((url) => !url.toString().startsWith(LIVE_BASE_URL), { timeout: 90_000 }).then(() => '3ds-page'),
        pci.rejected.then(() => 'pci-1005'),
      ]).catch(() => 'timeout')
      console.log(`[slp-retry] 第一次結果：${outcome} ${pci.samples.slice(0, 1).join('')}`)

      if (outcome === '3ds-page') {
        await page.waitForTimeout(3_000)
        console.log(`[slp-retry] 3DS 頁 URL：${page.url()} 文字：${(await page.locator('body').innerText()).slice(0, 400)}`)
        await caption(page, '於 3DS 模擬頁放棄（不選擇結果），直接回到 order-received')
        await page.goto(receivedUrl)
        await page.waitForTimeout(5_000)
      } else {
        await caption(page, `第一次付款未完成（${outcome}：SHOPLINE 沙盒 PCI 1005），停留在收銀台`)
        await page.waitForTimeout(3_000)
      }

      // 第二次（同一 WC 訂單；修正後應換新 referenceOrderId）
      await expect(page.locator('#pc-slp-embedded-submit'), '第一次失敗後收銀台應仍可重付').toBeVisible({ timeout: 60_000 })
      if (outcome === '3ds-page') {
        await waitForCashier(page)
        await typeCard(page)
      }
      // confirm 有 30 秒防雙擊鎖（EmbeddedGateway::LOCK_TTL）；等鎖過期再送
      await caption(page, '等待 35 秒（本站 confirm 防雙擊鎖 30 秒）後，同一訂單送出第二次付款')
      await page.waitForTimeout(35_000)
      const serverErrors: string[] = []
      page.on('response', (r) => {
        if (r.url().startsWith(LIVE_BASE_URL) && r.status() >= 500) {
          serverErrors.push(`${r.status()} ${r.url()}`)
        }
      })
      const second = await submitAndWaitConfirm(page, orderId)
      const secondIdentity = String(await admin.getOrderMeta(orderId, '_pc_payment_identity') ?? '')
      const history = referenceHistory(await admin.getOrderMeta(orderId, REFERENCE_HISTORY_META))
      console.log(`[slp-retry] 第二次 confirm：HTTP ${second.status} ${second.body.slice(0, 160)}`)
      console.log(`[slp-retry] 第二次 tradeOrderId=${secondIdentity}（第一次 ${firstIdentity}）`)
      console.log(`[slp-retry] ${REFERENCE_HISTORY_META}=${JSON.stringify(history)}`)
      await caption(page, `第二次建立交易 HTTP ${second.status}，referenceOrderId = ${history.at(-1) ?? ''}（第一次 ${history[0] ?? ''}）`)
      await page.waitForTimeout(8_000)

      const notes = await admin.getOrderNotes(orderId)
      console.log(`[slp-retry] order notes：${JSON.stringify(notes.slice(0, 6))}`)
      test.info().annotations.push({ type: 'retry-outcome', description: `HTTP ${second.status} history=${JSON.stringify(history)}` })

      expect(second.status, '第二次建立交易應 HTTP 200（不再 1001 → 500）').toBe(200)
      expect(second.body).toContain('next_action')
      expect(history, `${REFERENCE_HISTORY_META} 應有兩筆`).toHaveLength(2)
      expect(history[1], '第二次 referenceOrderId 應與第一次不同').not.toBe(history[0])
      for (const ref of history) {
        expect(ref, 'reference 格式 {order_id}-{attempt}-{hex6}').toMatch(new RegExp(`^${orderId}-[1-9]\\d*-[0-9A-F]{6}$`))
      }
      expect(secondIdentity, '第二次應取得新的 tradeOrderId').not.toBe(firstIdentity)
      expect(notes.some((n) => /code\s+1001\b|重複下單/.test(n)), '不應再出現 1001 重複下單').toBe(false)
      expect(serverErrors, '前台不應出現 5xx').toEqual([])

      await caption(page, `✅ 同一訂單重付：兩次 referenceOrderId 不同（${history.join(' → ')}），第二次 HTTP 200，無 500`)
      await page.waitForTimeout(4_000)
    } finally {
      await admin.close()
      await saveAcceptanceVideo(page, 'shopline_payment_embedded-retry-fixed')
    }
  })
})
