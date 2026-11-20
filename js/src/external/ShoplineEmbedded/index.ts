/**
 * SHOPLINE Payments 嵌入式（站內付）order-received 頁收銀台掛載模組
 *
 * 流程（計劃流程 B，兩段式 dispatch，皆打 POST power-checkout/v1/pay/{order_id}/dispatch，以 order_key 授權）：
 *   1. 偵測 power_checkout_slp_embedded_data（透過 @/utils/env，不直接讀 window）；無資料即不執行
 *   2. dispatch（init）→ 取 SDK 初始化參數（clientKey / merchantId / amount 等，金額由後端計算）
 *   3. 載入官方 SDK（CDN v1）→ ShoplinePayments(options) 於容器渲染收銀台
 *   4. 顧客按「確認付款」→ payment.createPayment() 取 paySession（點擊事件內立刻呼叫，不先做非同步）
 *   5. dispatch（confirm, pay_session）→ 後端 trade/payment/create → 回 next_action（原樣 JSON 字串）
 *   6. payment.pay(nextAction) → 3DS / 導回 order-received；最終結果以 webhook / 主動查詢為準
 *
 * SDK 版本：官方 CDN 只提供 major 路徑（/sdk/v1/），無法鎖精確版本（藍圖 §9 修正 10），URL 由後端常數提供。
 *
 * @see inc/classes/Domains/Payment/ShoplinePayment/Services/EmbeddedGateway.php
 * @see .claude/skills/shopline-payments-v1/references/embedded-sdk.md
 */

import { ElMessage } from 'element-plus'

import {
	IDispatchConfirmData,
	IDispatchInitData,
	IDispatchResponse,
	IShoplineEmbeddedData,
	IShoplinePayment,
} from '@/external/ShoplineEmbedded/types'
import { SLP_EMBEDDED_DATA } from '@/utils/env'

/** 觸發付款的按鈕 id */
const SUBMIT_BUTTON_ID = 'pc-slp-embedded-submit'

/** 通用錯誤訊息（不直接把 SDK / 金流錯誤顯示給顧客） */
const GENERIC_ERROR = '付款處理失敗，請稍後再試或聯繫商家'

/**
 * 載入官方 SDK script（同 src 冪等）
 *
 * @param src SDK URL
 * @return 載入完成 resolve；失敗 reject
 */
const loadSdk = (src: string): Promise<void> =>
	new Promise<void>((resolve, reject) => {
		if (window.ShoplinePayments) {
			resolve()
			return
		}
		const script = document.createElement('script')
		script.src = src
		script.async = false
		script.onload = () => resolve()
		script.onerror = () =>
			reject(new Error(`SHOPLINE Payments SDK 載入失敗：${src}`))
		document.head.appendChild(script)
	})

/**
 * 確保收銀台容器存在（SDK element 需以 querySelector 取得唯一 DOM）
 *
 * @param data localize 資料
 * @return 容器
 */
const ensureContainer = (data: IShoplineEmbeddedData): HTMLElement => {
	const existing = document.getElementById(data.container_id)
	if (existing) {
		return existing
	}

	const container = document.createElement('div')
	container.id = data.container_id
	container.style.marginBottom = '12px'

	const host =
		document.querySelector('.woocommerce-order') ||
		document.querySelector('.entry-content') ||
		document.body
	host.prepend(container)
	return container
}

/**
 * POST dispatch（order_key 授權，支援訪客結帳，不帶 nonce）
 *
 * @param data localize 資料
 * @param body 額外 body（confirm 帶 pay_session）
 * @return 回應 envelope 與 HTTP 是否成功
 */
const postDispatch = async <T>(
	data: IShoplineEmbeddedData,
	body: Record<string, unknown> = {}
): Promise<{ ok: boolean; json: IDispatchResponse<T> | null }> => {
	try {
		const resp = await fetch(data.dispatch_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ order_key: data.order_key, ...body }),
		})
		const json = (await resp.json()) as IDispatchResponse<T>
		return { ok: resp.ok, json }
	} catch (err) {
		console.error('SHOPLINE Payments dispatch 失敗', err)
		return { ok: false, json: null }
	}
}

/** 切換按鈕 loading / disabled */
const setButtonLoading = (
	button: HTMLButtonElement,
	loading: boolean
): void => {
	button.disabled = loading
	button.textContent = loading ? '處理中…' : '確認付款'
}

/**
 * 在容器下方建立「確認付款」按鈕
 *
 * @param container 收銀台容器
 * @param onClick   點擊處理
 * @return 按鈕
 */
const renderSubmitButton = (
	container: HTMLElement,
	onClick: (button: HTMLButtonElement) => void
): HTMLButtonElement => {
	const existing = document.getElementById(SUBMIT_BUTTON_ID)
	if (existing instanceof HTMLButtonElement) {
		return existing
	}
	const button = document.createElement('button')
	button.id = SUBMIT_BUTTON_ID
	button.type = 'button'
	button.className = 'el-button el-button--primary'
	button.style.marginTop = '16px'
	button.addEventListener('click', () => onClick(button))
	container.insertAdjacentElement('afterend', button)
	setButtonLoading(button, false)
	return button
}

/**
 * 點擊付款：createPayment → dispatch confirm → pay(nextAction)
 *
 * @param data    localize 資料
 * @param payment SDK 付款實例
 * @param button  付款按鈕
 */
const submitPayment = async (
	data: IShoplineEmbeddedData,
	payment: IShoplinePayment,
	button: HTMLButtonElement
): Promise<void> => {
	setButtonLoading(button, true)

	// 官方：createPayment() 務必在點擊事件內立刻呼叫（之前不可有其他非同步）
	const { paySession, error } = await payment.createPayment()
	if (error || !paySession) {
		// 顧客輸入錯誤時 SDK 會在欄位下方提示
		setButtonLoading(button, false)
		return
	}

	const { ok, json } = await postDispatch<IDispatchConfirmData>(data, {
		pay_session: paySession,
	})
	const nextActionJson = json?.data?.next_action
	if (!ok || !nextActionJson) {
		ElMessage.error(json?.message || GENERIC_ERROR)
		setButtonLoading(button, false)
		return
	}

	let nextAction: unknown
	try {
		nextAction = JSON.parse(nextActionJson)
	} catch (err) {
		console.error('SHOPLINE Payments nextAction 解析失敗', err)
		ElMessage.error(GENERIC_ERROR)
		setButtonLoading(button, false)
		return
	}

	const payResult = await payment.pay(nextAction)
	if (payResult?.error) {
		console.error('SHOPLINE Payments pay() 失敗', payResult.error)
		ElMessage.error('付款未完成，請確認卡片資訊後再試')
		setButtonLoading(button, false)
		return
	}

	// 正常情況 SDK 會自行導向 returnUrl（或 3DS 頁）；保險起見導回 order-received（結果以後端為準）
	window.location.assign(data.order_received_url)
}

/**
 * 啟動收銀台
 *
 * @param data localize 資料
 */
const startShoplineEmbedded = async (
	data: IShoplineEmbeddedData
): Promise<void> => {
	const container = ensureContainer(data)

	if (data.awaiting_confirmation === 'yes') {
		container.textContent =
			'付款確認中，請稍候幾分鐘後重新整理本頁查看付款結果，請勿重複付款。'
		return
	}

	const { ok, json } = await postDispatch<IDispatchInitData>(data)
	const sdk = json?.data?.sdk
	if (!ok || !sdk) {
		ElMessage.error(json?.message || GENERIC_ERROR)
		return
	}

	try {
		await loadSdk(data.sdk_url)
	} catch (err) {
		console.error(err)
		ElMessage.error('付款元件載入失敗，請重新整理頁面')
		return
	}

	if (!window.ShoplinePayments) {
		ElMessage.error('付款元件載入失敗，請重新整理頁面')
		return
	}

	const { payment, error } = await window.ShoplinePayments({
		...sdk,
		element: `#${data.container_id}`,
	})
	if (error || !payment) {
		// 官方建議不要直接把 SDK 錯誤訊息顯示給顧客；收銀台位置會呈現 SDK 錯誤介面
		console.error('SHOPLINE Payments SDK 初始化失敗', error)
		return
	}

	renderSubmitButton(container, (button) => {
		void submitPayment(data, payment, button)
	})
}

/**
 * 掛載入口（由 js/src/index.ts 呼叫）
 *
 * 只有 SHOPLINE Payments 嵌入式訂單的 order-received 頁才有 localize 資料，其他頁面靜默 return。
 */
const MountShoplineEmbedded = (): void => {
	const data = SLP_EMBEDDED_DATA
	if (!data || !data.dispatch_url || !data.order_key) {
		return
	}
	void startShoplineEmbedded(data)
}

export default MountShoplineEmbedded
