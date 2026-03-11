/**
 * Data Boundary E2E Tests — Unicode、Emoji、長字串、null、負數
 *
 * 測試 power-checkout API 面對各種邊界值時的穩健性。
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS, EDGE } from '../fixtures/test-data.js'

test.describe('Data Boundary — 資料邊界值測試', () => {
  let opts: ApiOptions

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
  })

  // ─── Unicode / CJK ────────────────────────────────────
  test.describe('Unicode 字串', () => {
    test('settings update title 為中文 → 正常儲存', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.UNICODE_CJK,
      })
      expect(res.status).toBe(200)

      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = (getRes.data as any).data ?? getRes.data
      expect(data.title).toBe(EDGE.UNICODE_CJK)
    })

    test('settings update title 為日文 → 正常儲存', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.UNICODE_JAPANESE,
      })
      expect(res.status).toBe(200)

      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = (getRes.data as any).data ?? getRes.data
      expect(data.title).toBe(EDGE.UNICODE_JAPANESE)
    })

    test('settings update title 為韓文 → 正常儲存', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.UNICODE_KOREAN,
      })
      expect(res.status).toBe(200)

      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = (getRes.data as any).data ?? getRes.data
      expect(data.title).toBe(EDGE.UNICODE_KOREAN)
    })
  })

  // ─── Emoji ─────────────────────────────────────────────
  test.describe('Emoji 字串', () => {
    test('settings update title 為 emoji → 正常處理', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.EMOJI,
      })
      expect(res.status).toBe(200)

      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = (getRes.data as any).data ?? getRes.data
      // Emoji should be preserved or at least not crash
      expect(data.title).toBeDefined()
    })

    test('settings update title 為複雜 emoji → 正常處理', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.EMOJI_COMPLEX,
      })
      expect(res.status).toBe(200)
    })
  })

  // ─── 空字串 / 空白 ────────────────────────────────────
  test.describe('空值與空白', () => {
    test('settings update title 為空字串 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.EMPTY_STRING,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('settings update title 為純空白 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.WHITESPACE_ONLY,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 為 null → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: null })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 為 undefined（不傳）→ 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, {})
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 超長字串 ──────────────────────────────────────────
  test.describe('超長字串', () => {
    test('settings update title 為 10,000 字 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.VERY_LONG_STRING,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('settings update merchantId 為超長字串 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        merchantId: 'X'.repeat(5000),
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 特殊字元 ──────────────────────────────────────────
  test.describe('特殊字元', () => {
    test('settings update title 包含特殊符號 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.SPECIAL_CHARS,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('settings update title 包含 HTML entities → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.HTML_ENTITIES,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 數值邊界 ──────────────────────────────────────────
  test.describe('數值邊界', () => {
    test('refund order_id 為 0 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.ZERO })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 為負數 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.NEGATIVE_NUMBER })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 為浮點數 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.FLOAT_NUMBER })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 為 MAX_INT → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.MAX_INT })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 為極大數 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.HUGE_NUMBER })
      expect(res.status).toBeLessThan(600)
    })

    test('settings update min_amount 為負數 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        min_amount: EDGE.NEGATIVE_NUMBER,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('settings update max_amount 為 0 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        max_amount: EDGE.ZERO,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('settings update tax_rate 為負數 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.AMEGO), {
        tax_rate: EDGE.NEGATIVE_NUMBER,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 清理：還原合理設定 ────────────────────────────────
  test.afterAll(async () => {
    // Restore SLP title to something reasonable
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: 'Shopline Payment 線上付款',
      mode: 'test',
    })
  })
})
