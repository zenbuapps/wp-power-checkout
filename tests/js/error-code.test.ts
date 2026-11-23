/**
 * js/src/utils/error-code.ts 單元測試（P8 fix #3：物流設定頁 AUTH 文案）
 *
 * P8 驗收：嘉里大榮「測試連線」帳密錯誤（424 AUTH）時，設定頁通知顯示
 * 「金流 / 發票憑證錯誤，請至設定頁確認金鑰」—— 共用對照表沒有區分領域。
 *
 * 契約：
 *  - 物流 API（power-checkout/v1/logistics/...）的 AUTH → 物流適用文案
 *  - 物流領域沒有專屬文案的 code → fallback 後端 message（不可出現「發票 / 退款」等他域文案）
 *  - 金流 / 發票（預設領域）原文案不變
 *
 * 執行（Node 22+ 原生 TS strip，無需額外套件）：
 *   node --experimental-strip-types --test tests/js/error-code.test.ts
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'

import {
	resolveErrorMessage,
	resolveErrorDomain,
} from '../../js/src/utils/error-code.ts'

test('物流 API 路徑判定為 logistics 領域', () => {
	assert.equal(resolveErrorDomain('logistics/kerrytj_logistics/ping'), 'logistics')
	assert.equal(resolveErrorDomain('logistics/123/create-shipment'), 'logistics')
	assert.equal(resolveErrorDomain('/logistics/123'), 'logistics')
	assert.equal(
		resolveErrorDomain('https://example.com/wp-json/power-checkout/v1/logistics/1/print'),
		'logistics'
	)
})

test('非物流路徑為預設領域', () => {
	assert.equal(resolveErrorDomain('refund'), 'default')
	assert.equal(resolveErrorDomain('invoices/issue/1'), 'default')
	assert.equal(resolveErrorDomain(undefined), 'default')
})

test('物流 AUTH 顯示物流憑證文案，不出現金流 / 發票', () => {
	const message = resolveErrorMessage(
		{ error_code: 'AUTH', message: '嘉里大榮拒絕連線：帳號或密碼錯誤' },
		'logistics'
	)
	assert.match(message, /物流憑證錯誤/)
	assert.doesNotMatch(message, /金流|發票/)
})

test('物流領域無專屬文案的 code fallback 後端 message', () => {
	assert.equal(
		resolveErrorMessage({ error_code: 'VALIDATION', message: '收件人電話格式錯誤' }, 'logistics'),
		'收件人電話格式錯誤'
	)
	assert.equal(
		resolveErrorMessage({ error_code: 'NOT_FOUND', message: '查無物流單' }, 'logistics'),
		'查無物流單'
	)
})

test('金流 / 發票（預設領域）原文案不變', () => {
	assert.equal(
		resolveErrorMessage({ error_code: 'AUTH', message: 'x' }),
		'金流 / 發票憑證錯誤，請至設定頁確認金鑰'
	)
	assert.equal(
		resolveErrorMessage({ error_code: 'AUTH', message: 'x' }, 'default'),
		'金流 / 發票憑證錯誤，請至設定頁確認金鑰'
	)
	assert.equal(
		resolveErrorMessage({ error_code: 'VALIDATION' }),
		'資料驗證失敗，請檢查發票 / 退款欄位'
	)
})
