/**
 * 結帳強化 — WC 區塊購物車 / 結帳「差多少免運」提示
 *
 * 非付款方式，故與 pc_invoice 同樣以 registerPlugin + 官方 slot fill 插入：
 *   - 選用 `ExperimentalOrderMeta`（訂單摘要 / 購物車總計區）：購物車與結帳兩頁都會渲染。
 *     原先評估的 `ExperimentalOrderShippingPackages` 實測只在結帳頁「運送選項」出現，
 *     購物車頁（總計區僅顯示運費金額、無運送方式選擇器）不渲染，故不採用。
 *   - scope `woocommerce-checkout` 同時涵蓋 Cart block 與 Checkout block 的 slot。
 *
 * 資料來源：後端 FreeShippingHintService 以 Store API cart extension 注入
 *   cartData.extensions['power-checkout'].free_shipping_hint
 *   - null → 不渲染
 *   - 物件 → { achieved, diff, text, bg_color, text_color }
 * 數量變更時 Store API 重算 cart，wc/store/cart 更新後此元件自動 re-render。
 *
 * 安全：text 後端已 esc_html，前端以 decodeEntities 還原後交給 React 當純文字
 * （React 會再跳脫），不使用 dangerouslySetInnerHTML。色碼後端保證 #RRGGBB。
 *
 * @see inc/classes/Domains/Checkout/Services/FreeShippingHintService.php
 * @see inc/assets/blocks/pc_invoice.tsx（同類 slot fill 範例）
 */

import { ExperimentalOrderMeta } from '@woocommerce/blocks-checkout'
import { useSelect } from '@wordpress/data'
import { createElement, Fragment } from '@wordpress/element'
import { decodeEntities } from '@wordpress/html-entities'
import { registerPlugin } from '@wordpress/plugins'

/** 後端 Store API extension namespace（對齊 FreeShippingHintService） */
const NAMESPACE = 'power-checkout'

/** WC 區塊購物車 data store 名稱 */
const CART_STORE = 'wc/store/cart'

/** 後端注入的免運提示資料 */
type TFreeShippingHint = {
	/** 是否已達免運門檻 */
	achieved: boolean

	/** 距離免運門檻的差額 */
	diff: number

	/** 已 esc_html 的提示文案 */
	text: string

	/** 背景色（#RRGGBB） */
	bg_color: string

	/** 文字色（#RRGGBB） */
	text_color: string
}

/** wc/store/cart getCartData() 中本元件需要的部分 */
type TCartData = {
	extensions?: Record<
		string,
		{ free_shipping_hint?: TFreeShippingHint | null } | undefined
	>
}

/**
 * 型別守衛：確認 extension 資料為有效的免運提示物件
 * @param value 未知資料
 */
const isFreeShippingHint = (value: unknown): value is TFreeShippingHint => {
	if (!value || typeof value !== 'object') return false
	const hint = value as Record<string, unknown>
	return (
		typeof hint.text === 'string' &&
		typeof hint.bg_color === 'string' &&
		typeof hint.text_color === 'string'
	)
}

/**
 * 從 wc/store/cart 讀取免運提示資料的 Hook
 * @return 免運提示；未啟用或無資料時為 null
 */
const useFreeShippingHint = (): TFreeShippingHint | null =>
	useSelect((select) => {
		const cartData: TCartData | undefined = select(CART_STORE)?.getCartData?.()
		const hint = cartData?.extensions?.[NAMESPACE]?.free_shipping_hint
		return isFreeShippingHint(hint) ? hint : null
	}, [])

/**
 * 免運提示元件
 * 依後端資料顯示「再買 X 元即可享免運」或「已享免運」；無資料時不渲染
 */
const FreeShippingHint = (): unknown => {
	const hint = useFreeShippingHint()
	if (!hint) return null

	const text = decodeEntities(hint.text)
	if (!text) return null

	return createElement(
		'div',
		{
			className: `pc-free-shipping-hint ${hint.achieved ? 'pc-free-shipping-hint--achieved' : 'pc-free-shipping-hint--short'}`,
			role: 'status',
			'aria-live': 'polite',
			style: {
				backgroundColor: hint.bg_color,
				color: hint.text_color,
				padding: '8px 12px',
				borderRadius: '8px',
				margin: '8px 0',
				fontSize: '14px',
			},
		},
		text
	)
}

/** Slot fill render：把提示包進訂單摘要 slot（購物車 / 結帳皆會渲染） */
const render = (): unknown =>
	createElement(
		Fragment,
		null,
		createElement(
			ExperimentalOrderMeta,
			null,
			createElement(FreeShippingHint, null)
		)
	)

registerPlugin('pc-free-shipping-hint', {
	render,
	scope: 'woocommerce-checkout',
})
