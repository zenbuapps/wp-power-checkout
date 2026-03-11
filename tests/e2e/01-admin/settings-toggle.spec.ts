/**
 * POST /power-checkout/v1/settings/{provider_id}/toggle — 開關服務
 *
 * Based on: spec/features/開關服務.feature
 * - 未登入 → 401
 * - 切換 enabled yes → no（禁用成功）
 * - 切換 enabled no → yes（啟用成功）
 */
import { test, expect } from '@playwright/test'
import { wpGet, wpPost, type ApiOptions } from '../helpers/api-client.js'
import { getNonce } from '../helpers/admin-setup.js'
import { BASE_URL, EP, PROVIDERS } from '../fixtures/test-data.js'

test.describe('POST /settings/{provider_id}/toggle — 開關服務', () => {
  let opts: ApiOptions

  test.beforeAll(async ({ request }) => {
    const nonce = getNonce()
    opts = { request, baseURL: BASE_URL, nonce }
  })

  // ─── 前置：必須具備管理員權限 ──────────────────────────
  test('未登入的訪客無法切換服務 → 401', async ({ request }) => {
    const noAuth: ApiOptions = { request, baseURL: BASE_URL, nonce: '' }
    const res = await wpPost(noAuth, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
    expect([401, 403]).toContain(res.status)
  })

  // ─── 後置：切換 enabled ────────────────────────────────
  test('toggle amego 會切換 enabled 狀態', async () => {
    // 先取得目前狀態
    const before = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(before.status).toBe(200)
    const dataBefore = (before.data as any).data ?? before.data
    const enabledBefore = dataBefore.enabled

    // toggle
    const toggleRes = await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
    expect(toggleRes.status).toBe(200)

    const toggleBody = toggleRes.data as any
    // 回應 message 應包含「啟用」或「禁用」
    if (toggleBody.message) {
      expect(
        toggleBody.message.includes('啟用') || toggleBody.message.includes('禁用'),
      ).toBe(true)
    }

    // 驗證狀態確實改變了
    const after = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.AMEGO))
    expect(after.status).toBe(200)
    const dataAfter = (after.data as any).data ?? after.data
    const enabledAfter = dataAfter.enabled

    expect(enabledAfter).not.toBe(enabledBefore)

    // Toggle back to restore original state
    await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
  })

  test('toggle SLP 後 enabled 從 yes → no 或 no → yes', async () => {
    const before = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    expect(before.status).toBe(200)
    const dataBefore = (before.data as any).data ?? before.data
    const expectedAfter = dataBefore.enabled === 'yes' ? 'no' : 'yes'

    const toggleRes = await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.SLP), {})
    expect(toggleRes.status).toBe(200)

    const after = await wpGet(opts, EP.SETTINGS_SINGLE(PROVIDERS.SLP))
    const dataAfter = (after.data as any).data ?? after.data
    expect(dataAfter.enabled).toBe(expectedAfter)

    // Restore
    await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.SLP), {})
  })

  test('toggle 回應包含 provider_id', async () => {
    const res = await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
    expect(res.status).toBe(200)

    const body = res.data as any
    const data = body.data ?? body
    // data should be the provider_id string or contain it
    const dataStr = typeof data === 'string' ? data : JSON.stringify(data)
    expect(dataStr).toContain(PROVIDERS.AMEGO)

    // Restore
    await wpPost(opts, EP.SETTINGS_TOGGLE(PROVIDERS.AMEGO), {})
  })
})
