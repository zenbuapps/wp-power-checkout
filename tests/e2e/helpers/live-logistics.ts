/**
 * Live Logistics — 錄影驗收（04-live）物流操作：以 admin nonce 呼叫物流 REST，並把結果呈現在錄影畫面
 *
 * repo 沒有物流訂單頁 UI（藍圖 §9 修正 4：物流操作只有 REST），因此錄影以：
 * - 錄影 page 內以 fetch（同源、帶 X-WP-Nonce）呼叫 REST → 畫面顯示請求與回應
 * - 列印輸出（PDF）寫入 test output 後以瀏覽器開啟
 * - 訂單編輯頁顯示 order notes
 */
import { expect, type Page } from '@playwright/test'
import * as fs from 'fs'
import { LIVE_BASE_URL } from './live-env.js'
import { caption, fetchRestNonce, orderEditUrl } from './live-setup.js'

/** 物流 REST 呼叫結果 */
export interface LogisticsCallResult {
  status: number
  contentType: string
  json: any
  body: Buffer
}

/**
 * 在錄影 page 內呼叫物流 REST（power-checkout/v1/logistics/{orderId}/{action}?provider=…）並把結果渲染到畫面
 *
 * @param action create-shipment | print | cancel | return | ''（GET 查詢）
 */
export async function callLogistics(
  page: Page,
  orderId: number,
  providerId: string,
  action: '' | 'create-shipment' | 'print' | 'cancel' | 'return',
  title: string,
): Promise<LogisticsCallResult> {
  const nonce = await fetchRestNonce(page)
  const route = `power-checkout/v1/logistics/${orderId}${action ? `/${action}` : ''}?provider=${providerId}`
  const method = action ? 'POST' : 'GET'
  const response = await page.request.fetch(`${LIVE_BASE_URL}/wp-json/${route}`, {
    method,
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: method === 'POST' ? JSON.stringify({ provider: providerId }) : undefined,
    timeout: 180_000,
  })
  const body = await response.body()
  const contentType = response.headers()['content-type'] || ''
  let json: any = null
  if (!contentType.includes('pdf')) {
    try {
      json = JSON.parse(body.toString('utf-8'))
    } catch {
      json = null
    }
  }
  const shown = contentType.includes('pdf')
    ? `(PDF ${body.length} bytes)`
    : JSON.stringify(json ?? body.toString('utf-8').slice(0, 2000), null, 2)
  await renderPanel(page, title, `${method} /wp-json/${route}`, `HTTP ${response.status()}  ${contentType}\n\n${shown}`)
  return { status: response.status(), contentType, json, body }
}

/** 以簡單 HTML 面板呈現請求 / 回應（錄影可讀） */
export async function renderPanel(page: Page, title: string, request: string, responseText: string): Promise<void> {
  const escape = (s: string): string => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c] as string))
  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"><title>${escape(title)}</title>
<style>body{font:15px/1.5 system-ui,sans-serif;margin:24px;background:#f8fafc;color:#0f172a}
h1{font-size:22px}pre{background:#0f172a;color:#e2e8f0;padding:16px;border-radius:8px;white-space:pre-wrap;word-break:break-all;max-height:640px;overflow:auto}
.req{background:#e2e8f0;padding:8px 12px;border-radius:6px;font-family:monospace}</style></head>
<body><h1>${escape(title)}</h1><div class="req">${escape(request)}</div><pre>${escape(responseText)}</pre></body></html>`)
  await page.waitForTimeout(3_500)
}

/** 把 PDF 輸出寫檔並在瀏覽器開啟（Chrome PDF viewer） */
export async function showPdf(page: Page, body: Buffer, file: string): Promise<void> {
  expect(body.subarray(0, 4).toString('latin1'), '列印輸出應為 PDF').toBe('%PDF')
  fs.writeFileSync(file, body)
  await page.goto(`file:///${file.replace(/\\/g, '/')}`)
  await page.waitForTimeout(5_000)
}

/** 開訂單編輯頁並捲到 order notes */
export async function showOrderNotes(page: Page, orderId: number, text: string): Promise<void> {
  await page.goto(orderEditUrl(orderId))
  await caption(page, text)
  await page.locator('#woocommerce-order-notes').scrollIntoViewIfNeeded()
  await page.waitForTimeout(4_000)
}
