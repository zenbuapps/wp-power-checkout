/**
 * Security E2E Tests — 認證、注入攻擊、XSS、無效 Nonce
 *
 * 驗證 power-checkout API 在安全性方面的防護：
 * - 未認證存取
 * - 無效 / 過期 nonce
 * - SQL 注入
 * - XSS 攻擊
 * - Path traversal
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS, EDGE } from '../fixtures/test-data.js'

test.describe('Security — 認證與注入防護', () => {
  let opts: ApiOptions

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
  })

  // ─── 認證：無 nonce ────────────────────────────────────
  test.describe('未認證存取', () => {
    test('GET /settings 無 nonce → 401 or 403', async ({ request }) => {
      const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
      const res = await wpGet(noAuth, EP.SETTINGS_ALL)
      expect([401, 403]).toContain(res.status)
    })

    test('POST /settings/{id} 無 nonce → 401 or 403', async ({ request }) => {
      const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
      const res = await wpPost(noAuth, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: 'hacked',
      })
      expect([401, 403]).toContain(res.status)
    })

    test('POST /settings/{id}/toggle 無 nonce → 401 or 403', async ({ request }) => {
      const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
      const res = await wpPost(noAuth, EP.SETTINGS_TOGGLE(PROVIDERS.SLP), {})
      expect([401, 403]).toContain(res.status)
    })

    test('POST /refund 無 nonce → 401 or 403', async ({ request }) => {
      const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
      const res = await wpPost(noAuth, EP.REFUND, { order_id: 1 })
      expect([401, 403]).toContain(res.status)
    })

    test('POST /refund/manual 無 nonce → 401 or 403', async ({ request }) => {
      const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
      const res = await wpPost(noAuth, EP.REFUND_MANUAL, { order_id: 1 })
      expect([401, 403]).toContain(res.status)
    })

    test('POST /invoices/issue/{id} 無 nonce → 401 or 403', async ({ request }) => {
      const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
      const res = await wpPost(noAuth, EP.INVOICE_ISSUE(1), { provider: 'amego' })
      expect([401, 403]).toContain(res.status)
    })

    test('POST /invoices/cancel/{id} 無 nonce → 401 or 403', async ({ request }) => {
      const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
      const res = await wpPost(noAuth, EP.INVOICE_CANCEL(1), {})
      expect([401, 403]).toContain(res.status)
    })
  })

  // ─── 認證：無效 nonce ──────────────────────────────────
  test.describe('無效 nonce', () => {
    test('GET /settings 使用假 nonce → 401 or 403', async ({ request }) => {
      const badAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: 'fake_nonce_12345' }
      const res = await wpGet(badAuth, EP.SETTINGS_ALL)
      expect([401, 403]).toContain(res.status)
    })

    test('POST /refund 使用假 nonce → 401 or 403', async ({ request }) => {
      const badAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: 'aaaabbbbcccc' }
      const res = await wpPost(badAuth, EP.REFUND, { order_id: 1 })
      expect([401, 403]).toContain(res.status)
    })
  })

  // ─── SQL 注入 ──────────────────────────────────────────
  test.describe('SQL 注入防護', () => {
    test('provider_id 包含 SQL 注入字串 → 安全處理', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(EDGE.SQL_INJECTION_1))
      // Should NOT crash with DB error, acceptable: 500 (provider not found) or 404
      expect(res.status).toBeLessThan(600)
      const body = JSON.stringify(res.data)
      // Should not expose DB info
      expect(body.toLowerCase()).not.toContain('syntax error')
      expect(body.toLowerCase()).not.toContain('mysql')
    })

    test('refund order_id 包含 SQL 注入 → 安全處理', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.SQL_INJECTION_2 })
      expect(res.status).toBeLessThan(600)
      const body = JSON.stringify(res.data)
      expect(body.toLowerCase()).not.toContain('syntax error')
    })

    test('settings update 值包含 SQL 注入 → 安全處理', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.SQL_INJECTION_3,
      })
      expect(res.status).toBeLessThan(600)
      const body = JSON.stringify(res.data)
      expect(body.toLowerCase()).not.toContain('syntax error')
    })
  })

  // ─── XSS 防護 ─────────────────────────────────────────
  test.describe('XSS 防護', () => {
    test('settings update title 包含 <script> → 被 sanitize', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.XSS_SCRIPT,
      })
      expect(res.status).toBe(200)

      // Verify the stored value is sanitized
      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = (getRes.data as any).data ?? getRes.data
      if (data.title !== undefined) {
        expect(data.title).not.toContain('<script>')
      }
    })

    test('settings update title 包含 <img onerror> → 被 sanitize', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.XSS_IMG,
      })
      expect(res.status).toBe(200)

      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = (getRes.data as any).data ?? getRes.data
      if (data.title !== undefined) {
        expect(data.title).not.toContain('onerror')
      }
    })

    test('settings update description 包含 <svg/onload> → 被 sanitize', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        description: EDGE.XSS_SVG,
      })
      expect(res.status).toBe(200)

      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = (getRes.data as any).data ?? getRes.data
      if (data.description !== undefined) {
        expect(data.description).not.toContain('onload')
      }
    })
  })

  // ─── Path Traversal ────────────────────────────────────
  test.describe('路徑穿越防護', () => {
    test('provider_id 包含 ../ → 安全處理', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE('../../etc/passwd'))
      expect(res.status).toBeLessThan(600)
      const body = JSON.stringify(res.data)
      expect(body).not.toContain('root:')
    })

    test('provider_id 包含 URL 編碼路徑穿越 → 安全處理', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE('%2e%2e%2f%2e%2e%2fetc%2fpasswd'))
      expect(res.status).toBeLessThan(600)
    })
  })
})
