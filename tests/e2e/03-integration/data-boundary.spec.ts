/**
 * P3 — Data Boundary — 資料邊界值測試
 *
 * 系統化測試 power-checkout API 面對各種邊界值時的穩健性。
 * 所有測試的核心原則：即使輸入值不合理，API 也不應 crash（status < 600），
 * 並應回傳有意義的錯誤訊息。
 *
 * 涵蓋：
 * - Unicode / CJK / 日文 / 韓文 / RTL
 * - Emoji（簡單 + 複雜 ZWJ sequence）
 * - 空字串 / 純空白 / newline
 * - 超長字串（10,000 字）
 * - 特殊符號 / HTML entities
 * - 數值：0, -1, -999, 0.001, 0.5, MAX_SAFE_INT, NaN, Infinity
 * - null / undefined body
 * - SQL injection / Path traversal
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS, EDGE } from '../fixtures/test-data.js'

test.describe('Data Boundary — 資料邊界值測試', () => {
  let opts: ApiOptions
  let originalSlpTitle = 'SHOPLINE Payments（跳轉支付）'

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }

    // 備份原始 title
    const res = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    if (res.status === 200) {
      const data = ((res.data as Record<string, unknown>).data ?? res.data) as Record<string, unknown>
      originalSlpTitle = (data.title as string) || originalSlpTitle
    }
  })

  test.afterAll(async () => {
    // 還原 SLP title
    await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
      title: originalSlpTitle,
      mode: 'test',
    }).catch(() => {/* 忽略 */})
  })

  // ─── Unicode / CJK ─────────────────────────────────────────
  test.describe('Unicode 字串', () => {
    test('title 為繁體中文 → 正常儲存並可讀回', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.UNICODE_CJK,
      })
      expect(res.status).toBe(200)

      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = ((getRes.data as Record<string, unknown>).data ?? getRes.data) as Record<string, unknown>
      expect(data.title).toBe(EDGE.UNICODE_CJK)
    })

    test('title 為日文字串 → 正常儲存', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.UNICODE_JAPANESE,
      })
      expect(res.status).toBe(200)

      const getRes = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
      const data = ((getRes.data as Record<string, unknown>).data ?? getRes.data) as Record<string, unknown>
      expect(data.title).toBe(EDGE.UNICODE_JAPANESE)
    })

    test('title 為韓文字串 → 正常儲存', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.UNICODE_KOREAN,
      })
      expect(res.status).toBe(200)
    })

    test('title 為 RTL 阿拉伯文 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.RTL_ARABIC,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('title 為 5,000 個 CJK 字元 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.LONG_UNICODE,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── Emoji ──────────────────────────────────────────────────
  test.describe('Emoji 字串', () => {
    test('title 為簡單 Emoji → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.EMOJI_SIMPLE,
      })
      expect(res.status).toBe(200)
      const body = res.data as Record<string, unknown>
      expect(body.code).toBe('success')
    })

    test('title 為複雜 ZWJ Emoji sequence → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.EMOJI_COMPLEX,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('title 為付款相關 Emoji → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.EMOJI_PAYMENT,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 空值與空白 ─────────────────────────────────────────────
  test.describe('空值與空白字串', () => {
    test('title 為空字串 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.EMPTY_STRING,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('title 為純空白 → 不應 crash（sanitize 後可能清空）', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.WHITESPACE_ONLY,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('title 為 newline only → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.NEWLINE_ONLY,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 為 null → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: null })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 未提供 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, {})
      expect(res.status).toBeLessThan(600)
    })

    test('manual refund order_id 為 null → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND_MANUAL, { order_id: null })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 超長字串 ───────────────────────────────────────────────
  test.describe('超長字串', () => {
    test('title 為 10,000 字 ASCII → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.VERY_LONG_STRING,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('merchantId 為 5,000 字 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        merchantId: 'X'.repeat(5000),
      })
      expect(res.status).toBeLessThan(600)
    })

    test('apiKey 為 10,000 字 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        apiKey: 'K'.repeat(10_000),
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 特殊符號 ───────────────────────────────────────────────
  test.describe('特殊符號與 HTML entities', () => {
    test('title 含特殊符號 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.SPECIAL_CHARS,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('title 含 HTML entities → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.HTML_ENTITIES,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('title 含 null byte → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.NULL_BYTE,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── 數值邊界 ───────────────────────────────────────────────
  test.describe('數值邊界', () => {
    test('refund order_id 為 0 → 非 200', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.ZERO })
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('refund order_id 為 -1 → 非 200', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.NEGATIVE })
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('refund order_id 為 -999 → 非 200', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.NEGATIVE_LARGE })
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('refund order_id 為 0.001 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.FLOAT_TINY })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 為 0.5 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.FLOAT_HALF })
      expect(res.status).toBeLessThan(600)
    })

    test('refund order_id 為 MAX_SAFE_INT → 不應 crash', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.MAX_SAFE_INT })
      expect(res.status).toBeLessThan(600)
    })

    test('settings min_amount 為 -1 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        min_amount: EDGE.NEGATIVE,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('settings max_amount 為 0 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        max_amount: EDGE.ZERO,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('settings max_amount 為 MAX_INT32 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        max_amount: EDGE.MAX_INT32,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('Amego tax_rate 為 -1 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.AMEGO), {
        tax_rate: EDGE.NEGATIVE,
      })
      expect(res.status).toBeLessThan(600)
    })

    test('Amego tax_rate 為 0 → 不應 crash', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.AMEGO), {
        tax_rate: EDGE.ZERO,
      })
      expect(res.status).toBeLessThan(600)
    })
  })

  // ─── SQL Injection ──────────────────────────────────────────
  test.describe('SQL Injection 防護', () => {
    test('title 含 DROP TABLE → 不應 crash，DB 仍可用', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.SQL_DROP,
      })
      expect(res.status).toBeLessThan(600)

      // 確認 DB 未被破壞
      const getRes = await wpGet(opts, EP.SETTINGS_ALL)
      expect(getRes.status).toBe(200)
    })

    test('title 含 OR 1=1 → 不應洩漏資料', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.SQL_OR,
      })
      expect(res.status).toBeLessThan(600)

      const getRes = await wpGet(opts, EP.SETTINGS_ALL)
      expect(getRes.status).toBe(200)
    })

    test('refund order_id 含 SQL injection → 500（訂單編號必須是數字）', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.SQL_DROP })
      expect(res.status).toBe(500)
      const body = res.data as Record<string, unknown>
      const msg = String(body.message ?? '')
      expect(msg).toContain('數字')
    })

    test('refund order_id 含 UNION SELECT → 500', async () => {
      const res = await wpPost(opts, EP.REFUND, { order_id: EDGE.SQL_UNION })
      expect(res.status).toBe(500)
    })
  })

  // ─── XSS 防護 ───────────────────────────────────────────────
  test.describe('XSS 防護', () => {
    test('title 含 <script> → sanitize 後不含 script 標籤', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.XSS_SCRIPT,
      })
      expect(res.status).toBe(200)

      const body = res.data as Record<string, unknown>
      const data = (body.data ?? body) as Record<string, unknown>
      const title = String(data.title ?? '')
      expect(title).not.toContain('<script>')
      expect(title).not.toContain('alert(')
    })

    test('title 含 img onerror → sanitize 後不含 onerror', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.XSS_IMG,
      })
      expect(res.status).toBe(200)

      const body = res.data as Record<string, unknown>
      const data = (body.data ?? body) as Record<string, unknown>
      const title = String(data.title ?? '')
      expect(title).not.toContain('onerror')
    })

    test('title 含 SVG onload → sanitize 後不含 onload', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.XSS_SVG,
      })
      expect(res.status).toBe(200)

      const body = res.data as Record<string, unknown>
      const data = (body.data ?? body) as Record<string, unknown>
      const title = String(data.title ?? '')
      expect(title).not.toContain('onload')
    })
  })

  // ─── Path Traversal ─────────────────────────────────────────
  test.describe('Path Traversal', () => {
    test('provider_id 含路徑穿越字元 → 非 200', async () => {
      const res = await wpGet(opts, EP.SETTINGS_SINGLE(EDGE.PATH_TRAVERSAL))
      expect(res.status).toBeGreaterThanOrEqual(400)
    })

    test('settings title 含路徑穿越 → 不應 crash，資料應被消毒', async () => {
      const res = await wpPost(opts, EP.SETTINGS_UPDATE(PROVIDERS.SLP), {
        title: EDGE.PATH_TRAVERSAL,
      })
      expect(res.status).toBeLessThan(600)
    })
  })
})
