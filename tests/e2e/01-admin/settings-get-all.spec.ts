/**
 * GET /power-checkout/v1/settings — 取得所有設定
 *
 * Based on: spec/features/取得所有設定.feature
 * - 未登入 → 401
 * - 成功回傳 gateways / invoices / logistics 三個分類
 * - Gateway 清單不暴露敏感金鑰 (apiKey, clientKey, signKey)
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

  // ─── 前置：必須具備管理員權限 ──────────────────────────
  test('未登入的訪客無法取得設定 → 401', async ({ request }) => {
    const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpGet(noAuth, EP.SETTINGS_ALL)
    // WordPress may return 401 or 403 for unauthenticated requests
    expect([401, 403]).toContain(res.status)
  })

  // ─── 後置：回傳三個分類 ────────────────────────────────
  test('成功取得所有設定，回傳 gateways / invoices / logistics', async () => {
    const res = await wpGet(opts, EP.SETTINGS_ALL)
    expect(res.status).toBe(200)

    const body = res.data as any
    // The response should contain data with three categories
    const data = body.data ?? body
    expect(data).toHaveProperty('gateways')
    expect(data).toHaveProperty('invoices')
    expect(data).toHaveProperty('logistics')

    // gateways and invoices should be arrays
    expect(Array.isArray(data.gateways)).toBe(true)
    expect(Array.isArray(data.invoices)).toBe(true)
    expect(Array.isArray(data.logistics)).toBe(true)
  })

  test('gateways 包含 shopline_payment_redirect', async () => {
    const res = await wpGet(opts, EP.SETTINGS_ALL)
    expect(res.status).toBe(200)

    const data = (res.data as any).data ?? res.data
    const gateways = data.gateways as any[]
    const slp = gateways.find(
      (g: any) => g.id === PROVIDERS.SLP,
    )
    expect(slp).toBeTruthy()
    if (slp) {
      expect(slp).toHaveProperty('title')
      expect(slp).toHaveProperty('enabled')
    }
  })

  test('invoices 包含 amego', async () => {
    const res = await wpGet(opts, EP.SETTINGS_ALL)
    expect(res.status).toBe(200)

    const data = (res.data as any).data ?? res.data
    const invoices = data.invoices as any[]
    const amego = invoices.find(
      (inv: any) => inv.id === PROVIDERS.AMEGO,
    )
    expect(amego).toBeTruthy()
  })

  // ─── 後置：不暴露敏感金鑰 ─────────────────────────────
  test('gateways 清單中不暴露 apiKey / clientKey / signKey', async () => {
    const res = await wpGet(opts, EP.SETTINGS_ALL)
    expect(res.status).toBe(200)

    const data = (res.data as any).data ?? res.data
    const gateways = data.gateways as any[]
    const slp = gateways.find(
      (g: any) => g.id === PROVIDERS.SLP,
    )

    if (slp) {
      expect(slp).not.toHaveProperty('apiKey')
      expect(slp).not.toHaveProperty('clientKey')
      expect(slp).not.toHaveProperty('signKey')
    }
  })
})
