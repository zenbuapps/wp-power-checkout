/**
 * GET /power-checkout/v1/settings/{provider_id} — 取得單一服務設定
 *
 * Based on: spec/features/取得單一服務設定.feature
 * - provider_id 不存在 → 500
 * - shopline_payment_redirect → 包含 merchantId, allowPaymentMethodList, mode
 * - amego → 包含 invoice, app_key, tax_rate
 */
import { test, expect } from '@playwright/test'
import { wpGet, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS } from '../fixtures/test-data.js'

test.describe('GET /settings/{provider_id} — 取得單一服務設定', () => {
  let opts: ApiOptions

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
  })

  // ─── 前置：provider_id 必須存在 ───────────────────────
  test('查詢不存在的 provider → 500', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE('nonexistent_provider'))
    expect(res.status).toBe(500)

    const body = res.data as any
    const msg = JSON.stringify(body)
    expect(msg.toLowerCase()).toContain('provider')
  })

  // ─── 後置：Shopline Payment 設定 ──────────────────────
  test('成功取得 Shopline Payment 設定', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(res.status).toBe(200)

    const body = res.data as any
    const data = body.data ?? body

    // Should contain SLP-specific fields
    expect(data).toHaveProperty('merchantId')
    expect(data).toHaveProperty('allowPaymentMethodList')
    expect(data).toHaveProperty('mode')
  })

  test('SLP 設定包含所有已知欄位', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(res.status).toBe(200)

    const data = (res.data as any).data ?? res.data
    // Core SLP fields
    const expectedFields = [
      'enabled', 'title', 'mode', 'platformId', 'merchantId',
      'apiKey', 'clientKey', 'signKey',
    ]
    for (const field of expectedFields) {
      expect(data).toHaveProperty(field)
    }
  })

  // ─── 後置：Amego 設定 ─────────────────────────────────
  test('成功取得 Amego 電子發票設定', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(res.status).toBe(200)

    const body = res.data as any
    const data = body.data ?? body

    expect(data).toHaveProperty('invoice')
    expect(data).toHaveProperty('app_key')
    expect(data).toHaveProperty('tax_rate')
  })

  test('Amego 設定包含自動開立/作廢欄位', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(res.status).toBe(200)

    const data = (res.data as any).data ?? res.data
    // Amego should have auto trigger fields
    expect(data).toHaveProperty('auto_issue_order_statuses')
    expect(data).toHaveProperty('auto_cancel_order_statuses')
  })
})
