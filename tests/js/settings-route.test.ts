/**
 * js/src/utils/settings-route.ts 單元測試（齒輪按鈕找不到設定路由時要提示重新整理）
 *
 * 背景：卡片清單由 PHP 產生、路由表在前端 bundle。主機忽略 ?ver= 並長效快取舊 bundle 時，
 * 新廠商卡片在舊 bundle 裡沒有路由，原本 RouterLink 指到不存在的路由 → 點了完全沒反應。
 *
 * 執行：node --experimental-strip-types --test tests/js/settings-route.test.ts
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { resolveSettingsRoute, STALE_BUNDLE_MESSAGE } from '../../js/src/utils/settings-route.ts'

const MAPPER = {
	payuni_upp: '/payments/payuni_upp',
	payuni_logistics: '/logistics/payuni_logistics',
}
const REGISTERED = new Set(['/payments/payuni_upp'])
const exists = (path: string) => REGISTERED.has(path)

test('路由存在 → 回傳 path', () => {
	assert.deepEqual(resolveSettingsRoute('payuni_upp', MAPPER, exists), { ok: true, path: '/payments/payuni_upp' })
})

test('ROUTER_MAPPER 沒有這個廠商（舊 bundle + 新 PHP）→ 提示重新整理', () => {
	assert.deepEqual(resolveSettingsRoute('brand_new_gateway', MAPPER, exists), { ok: false, message: STALE_BUNDLE_MESSAGE })
})

test('MAPPER 有但 router 沒註冊該路由 → 提示重新整理', () => {
	assert.deepEqual(resolveSettingsRoute('payuni_logistics', MAPPER, exists), { ok: false, message: STALE_BUNDLE_MESSAGE })
})

test('原型鏈上的 key（toString / __proto__）不算路由', () => {
	assert.equal(resolveSettingsRoute('toString', MAPPER, () => true).ok, false)
	assert.equal(resolveSettingsRoute('__proto__', MAPPER, () => true).ok, false)
})

test('提示文案', () => {
	assert.equal(STALE_BUNDLE_MESSAGE, '外掛已更新，請重新整理頁面（Ctrl+Shift+R）')
})
