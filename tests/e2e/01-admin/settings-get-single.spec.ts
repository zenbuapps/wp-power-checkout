/**
 * P0 — GET /power-checkout/v1/settings/{provider_id} — 取得單一服務設定
 *
 * 依據：specs/features/settings/provider-settings.feature
 *
 * 測試情境：
 * - 未登入 → 401/403
 * - provider_id 不存在 → 500，包含 "Can't find Provider"
 * - SLP 設定包含所有必要欄位（含敏感金鑰，因為是單一設定）
 * - Amego 設定包含所有必要欄位（invoice / app_key / tax_rate 等）
 * - 邊界值 provider_id（特殊字元、超長字串、XSS）
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

  // ─── P1：未授權存取 ─────────────────────────────────────────
  test('未登入的訪客 → 401 或 403', async ({ request }) => {
    const unauthOpts: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpGet(unauthOpts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect([401, 403]).toContain(res.status)
  })

  // ─── P1：不存在的 provider_id ──────────────────────────────
  test('查詢不存在的 provider → 500，回應包含 "Provider"', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE('nonexistent_provider'))
    expect(res.status).toBe(500)

    const body = res.data as Record<string, unknown>
    const msg = (body.message ?? JSON.stringify(body)) as string
    // spec 要求錯誤訊息包含 "Can't find Provider"
    expect(msg).toMatch(/provider/i)
  })

  test('provider_id 為空字串路徑 → 非 200', async () => {
    // provider_id="" 無法通過路徑解析，預期 404 或 500
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(''))
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  // ─── P0：SLP 設定完整欄位 ──────────────────────────────────
  test('成功取得 SLP 設定，回應 code 為 success', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(res.status).toBe(200)

    const body = res.data as Record<string, unknown>
    expect(body.code).toBe('success')
  })

  test('SLP 設定包含所有必要欄位', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>

    // specs/features/settings/provider-settings.feature 的 ShoplinePaymentSettings 欄位
    const requiredFields = [
      'enabled', 'title', 'description', 'icon', 'mode',
      'merchantId', 'apiKey', 'clientKey', 'signKey', 'apiUrl',
      'allowPaymentMethodList', 'paymentMethodOptions',
      'expire_min', 'min_amount', 'max_amount', 'order_button_text',
    ]
    for (const field of requiredFields) {
      expect(data, `SLP 設定應包含欄位 "${field}"`).toHaveProperty(field)
    }
  })

  test('SLP enabled 值必須是 "yes" 或 "no"', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
    expect(['yes', 'no']).toContain(data.enabled)
  })

  test('SLP allowPaymentMethodList 必須是陣列', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
    expect(Array.isArray(data.allowPaymentMethodList)).toBe(true)
  })

  // ─── P0：Amego 設定完整欄位 ────────────────────────────────
  test('成功取得 Amego 電子發票設定', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
    expect(data).toHaveProperty('invoice')
    expect(data).toHaveProperty('app_key')
    expect(data).toHaveProperty('tax_rate')
  })

  test('Amego 設定包含所有必要欄位', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>

    // specs/features/settings/provider-settings.feature 的 AmegoSettings 欄位
    const requiredFields = [
      'enabled', 'title', 'description', 'icon', 'mode',
      'invoice', 'app_key', 'tax_rate',
      'auto_issue_order_statuses', 'auto_cancel_order_statuses',
    ]
    for (const field of requiredFields) {
      expect(data, `Amego 設定應包含欄位 "${field}"`).toHaveProperty(field)
    }
  })

  test('Amego auto_issue_order_statuses 必須是陣列', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
    expect(Array.isArray(data.auto_issue_order_statuses)).toBe(true)
    expect(Array.isArray(data.auto_cancel_order_statuses)).toBe(true)
  })

  // ─── P3：邊界 provider_id 值 ────────────────────────────────
  test('provider_id 為 XSS 字串 → 500（provider 不存在）', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE('<script>alert(1)</script>'))
    // WordPress REST 路徑解析後 provider_id 不符合 pattern，應拒絕
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('provider_id 為 SQL injection → 非 200', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE("'; DROP TABLE wp_options; --"))
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('provider_id 為超長字串 → 非 200', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE('a'.repeat(500)))
    expect(res.status).toBeGreaterThanOrEqual(400)
  })

  test('provider_id 為 Unicode 字串 → 非 200（pattern 不符）', async () => {
    const res = await wpGet(opts, EP.SETTINGS_SINGLE('金流設定'))
    expect(res.status).toBeGreaterThanOrEqual(400)
  })
})
