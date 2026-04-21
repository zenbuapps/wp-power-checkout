/**
 * P0 — GET /power-checkout/v1/settings — 取得所有設定
 *
 * 依據：specs/features/settings/provider-settings.feature
 *
 * 測試情境：
 * - 未登入訪客 → 401/403
 * - 成功回傳 gateways / invoices / logistics 三個分類
 * - gateways 包含 shopline_payment_redirect 且有必要欄位
 * - invoices 包含 amego
 * - gateways 清單不暴露敏感金鑰（apiKey、clientKey、signKey）
 * - invoices 每個項目包含必要欄位
 */
import { test, expect } from '@playwright/test'
import { wpGet, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS } from '../fixtures/test-data.js'

test.describe('GET /settings — 取得所有設定', () => {
  let opts: ApiOptions

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
  })

  // ─── P1：前置條件 — 必須具備管理員權限 ─────────────────────
  test('未登入的訪客無法取得設定 → 401 或 403', async ({ request }) => {
    // 不帶 Nonce 的請求視為未授權
    const unauthOpts: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpGet(unauthOpts, EP.SETTINGS_ALL)
    expect([401, 403]).toContain(res.status)
  })

  test('帶無效 Nonce 的請求 → 401 或 403', async ({ request }) => {
    const invalidOpts: ApiOptions = { request, baseURL: BASE_URL, nonce: 'invalid_nonce_12345' }
    const res = await wpGet(invalidOpts, EP.SETTINGS_ALL)
    expect([401, 403]).toContain(res.status)
  })

  // ─── P0：後置 — 回傳三個分類 ───────────────────────────────
  test('成功取得所有設定，回傳 gateways / invoices / logistics', async () => {
    const res = await wpGet(opts, EP.SETTINGS_ALL)
    expect(res.status).toBe(200)

    const body = res.data as Record<string, unknown>
    // spec 要求 code 為 "get_settings_success"
    expect(body.code).toBe('get_settings_success')
    expect(body.message).toBeTruthy()

    const data = (body.data ?? body) as Record<string, unknown>
    expect(data).toHaveProperty('gateways')
    expect(data).toHaveProperty('invoices')
    expect(data).toHaveProperty('logistics')

    expect(Array.isArray(data.gateways)).toBe(true)
    expect(Array.isArray(data.invoices)).toBe(true)
    expect(Array.isArray(data.logistics)).toBe(true)
  })

  // ─── P0：gateways 必須包含 SLP ─────────────────────────────
  test('gateways 包含 shopline_payment_redirect', async () => {
    const res = await wpGet(opts, EP.SETTINGS_ALL)
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
    const gateways = data.gateways as Record<string, unknown>[]
    const slp = gateways.find(g => g.id === PROVIDERS.SLP)

    expect(slp).toBeDefined()
    // spec 要求 ProviderSummary 欄位
    expect(slp).toHaveProperty('title')
    expect(slp).toHaveProperty('enabled')
    expect(slp).toHaveProperty('method_title')
  })

  // ─── P0：invoices 必須包含 amego ───────────────────────────
  test('invoices 包含 amego', async () => {
    const res = await wpGet(opts, EP.SETTINGS_ALL)
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
    const invoices = data.invoices as Record<string, unknown>[]
    const amego = invoices.find(inv => inv.id === PROVIDERS.AMEGO)

    expect(amego).toBeDefined()
  })

  // ─── P1：不暴露敏感金鑰 ────────────────────────────────────
  test('gateways 清單不包含 apiKey / clientKey / signKey', async () => {
    const res = await wpGet(opts, EP.SETTINGS_ALL)
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
    const gateways = data.gateways as Record<string, unknown>[]
    const slp = gateways.find(g => g.id === PROVIDERS.SLP)

    if (slp) {
      // spec 明確要求 Gateway 摘要不包含敏感金鑰
      expect(slp).not.toHaveProperty('apiKey')
      expect(slp).not.toHaveProperty('clientKey')
      expect(slp).not.toHaveProperty('signKey')
    }
  })

  // ─── P2：Invoice Provider 欄位完整性 ───────────────────────
  test('invoices 每個項目必須有 id / title / enabled / method_title 欄位', async () => {
    const res = await wpGet(opts, EP.SETTINGS_ALL)
    expect(res.status).toBe(200)

    const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
    const invoices = data.invoices as Record<string, unknown>[]

    for (const inv of invoices) {
      expect(inv).toHaveProperty('id')
      expect(inv).toHaveProperty('title')
      expect(inv).toHaveProperty('enabled')
      expect(inv).toHaveProperty('method_title')
    }
  })

  // ─── P3：重複請求冪等性 ────────────────────────────────────
  test('連續兩次請求結果一致（冪等）', async () => {
    const res1 = await wpGet(opts, EP.SETTINGS_ALL)
    const res2 = await wpGet(opts, EP.SETTINGS_ALL)

    expect(res1.status).toBe(200)
    expect(res2.status).toBe(200)

    const data1 = ((res1.data as Record<string, unknown>).data ?? res1.data) as Record<string, unknown>
    const data2 = ((res2.data as Record<string, unknown>).data ?? res2.data) as Record<string, unknown>

    // gateways 數量應相同
    expect((data1.gateways as unknown[]).length).toBe((data2.gateways as unknown[]).length)
  })
})
