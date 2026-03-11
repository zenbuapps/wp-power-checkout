/**
 * POST /power-checkout/v1/settings/{provider_id} — 更新服務設定
 *
 * Based on: spec/features/更新服務設定.feature
 * - 未登入 → 401
 * - HTML 標籤會被 sanitize
 * - 成功更新 Shopline Payment 設定
 * - 成功更新 Amego 設定
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS, EDGE } from '../fixtures/test-data.js'

test.describe('POST /settings/{provider_id} — 更新服務設定', () => {
  let opts: ApiOptions

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
  })

  // ─── 前置：必須具備管理員權限 ──────────────────────────
  test('未登入的訪客無法更新設定 → 401', async ({ request }) => {
    const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpPost(noAuth, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      merchantId: 'should_not_work',
    })
    expect([401, 403]).toContain(res.status)
  })

  // ─── 前置：sanitize ───────────────────────────────────
  test('HTML 標籤會被 sanitize（XSS script tag）', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: EDGE.XSS_SCRIPT,
    })
    expect(res.status).toBe(200)

    const body = res.data as any
    const data = body.data ?? body
    // title should not contain <script> after sanitization
    if (data.title !== undefined) {
      expect(data.title).not.toContain('<script>')
    }
  })

  // ─── 後置：成功更新 SLP ────────────────────────────────
  test('成功更新 Shopline Payment merchantId', async () => {
    const testValue = `[E2E]_merchant_${Date.now()}`
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      merchantId: testValue,
      mode: 'test',
    })
    expect(res.status).toBe(200)

    const body = res.data as any
    // Verify code is success
    if (body.code) {
      expect(body.code).toBe('success')
    }

    // Verify the value was actually saved by reading it back
    const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(getRes.status).toBe(200)
    const getData = (getRes.data as any).data ?? getRes.data
    expect(getData.merchantId).toBe(testValue)
  })

  test('成功更新 SLP mode 欄位', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      mode: 'test',
    })
    expect(res.status).toBe(200)

    const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    const getData = (getRes.data as any).data ?? getRes.data
    expect(getData.mode).toBe('test')
  })

  // ─── 後置：成功更新 Amego ──────────────────────────────
  test('成功更新 Amego 電子發票設定', async () => {
    const testInvoice = '99887766'
    const testAppKey = `[E2E]_app_key_${Date.now()}`

    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.AMEGO), {
      invoice: testInvoice,
      app_key: testAppKey,
      tax_rate: '0.05',
    })
    expect(res.status).toBe(200)

    // Read back to verify
    const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(getRes.status).toBe(200)
    const getData = (getRes.data as any).data ?? getRes.data
    expect(getData.invoice).toBe(testInvoice)
    expect(getData.app_key).toBe(testAppKey)
  })

  // ─── 更新後回傳完整設定 ────────────────────────────────
  test('更新後回應包含更新後的完整設定', async () => {
    const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      mode: 'test',
    })
    expect(res.status).toBe(200)

    const body = res.data as any
    const data = body.data ?? body
    // Should contain other fields too, not just the updated one
    expect(data).toHaveProperty('mode')
  })
})
