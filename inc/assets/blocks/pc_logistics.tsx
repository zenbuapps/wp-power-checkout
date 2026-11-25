/**
 * 物流共用 — WooCommerce Blocks 結帳選店（給實作 ISupportsCheckoutStoreSelection 的 provider）
 *
 * 物流是「運送方式」，Blocks 沒有 registerShippingMethod；用官方 slot fill
 * ExperimentalOrderShippingPackages 把「選擇門市」放到運送方式下方。
 *
 * 1. 訂閱 wc/store/cart：選定的 rate 若屬於共用整合的 provider，且 rate meta `sub_type` 是超商子類型 → 顯示按鈕。
 * 2. 按鈕 → POST power-checkout/v1/logistics/store-selection（provider + sub_type，session 權杖綁定）
 *    → 拿到門市地圖 auto-submit 表單 → 整頁開啟。
 * 3. 選完門市 provider 回呼寫回 session 並導回結帳頁；已選門市從 cart.extensions['pc_logistics'] 讀回。
 * 4. 沒選門市就送出：後端 RouteException 擋下（顯示在結帳頁錯誤區）。
 *
 * 前端設定：window.pcLogistics（store_selection_url / nonce / providers / i18n）
 * 後端：inc/classes/Domains/Logistics/Shared/Services/CheckoutLogisticsIntegration.php
 */

import {
	ExperimentalOrderShippingPackages,
	extensionCartUpdate,
} from '@woocommerce/blocks-checkout'
import { getSetting } from '@woocommerce/settings'
import { useSelect } from '@wordpress/data'
import {
	createElement,
	useState,
	useEffect,
	Fragment,
} from '@wordpress/element'
import { decodeEntities } from '@wordpress/html-entities'
import { registerPlugin } from '@wordpress/plugins'

const NAMESPACE = 'pc_logistics'

type TProvider = {
	provider: string
	method_id: string
	cvs_sub_types: string[]
}

type TSettings = {
	store_selection_url?: string
	nonce?: string
	providers?: TProvider[]
	i18n?: Record<string, string>
}

type TStore = {
	store_id: string
	store_name: string
	store_addr: string
	sub_type: string
}

type TRate = {
	rate_id?: string
	selected?: boolean
	method_id?: string
	meta_data?: Array<{ key: string; value: string }>
}

// 後端以 wp_localize_script 輸出 window.pcLogistics（nonce 需在確定登入者後才產生）；getSetting 作備援
const settings: TSettings =
	(window as unknown as { pcLogistics?: TSettings }).pcLogistics ??
	getSetting(`${NAMESPACE}_data`, {})
const i18n = settings.i18n ?? {}

/**
 * 選定的 rate 若需要選門市，回傳 { provider, subType }
 *
 * @param packages cart.shippingRates
 */
const getChosenCvs = (
	packages: Array<{ shipping_rates?: TRate[] }>
): { provider: string; subType: string } | null => {
	for (const pkg of packages ?? []) {
		for (const rate of pkg.shipping_rates ?? []) {
			if (!rate.selected) {
				continue
			}
			const methodId = rate.method_id || (rate.rate_id ?? '').split(':')[0]
			const provider = (settings.providers ?? []).find(
				(p) => p.method_id === methodId
			)
			if (!provider) {
				continue
			}
			const metaSubType =
				(rate.meta_data ?? []).find((m) => m.key === 'sub_type')?.value ?? ''
			const subType = metaSubType || (rate.rate_id ?? '').split(':').pop() || ''
			if (provider.cvs_sub_types.includes(subType)) {
				return { provider: provider.provider, subType }
			}
		}
	}
	return null
}

const readStore = (
	extensions: Record<string, unknown> | undefined
): TStore | null => {
	const ext = extensions?.[NAMESPACE] as Partial<TStore> | undefined
	if (!ext?.store_id) {
		return null
	}
	return {
		store_id: String(ext.store_id),
		store_name: String(ext.store_name ?? ''),
		store_addr: String(ext.store_addr ?? ''),
		sub_type: String(ext.sub_type ?? ''),
	}
}

const requestMapHtml = async (
	provider: string,
	subType: string
): Promise<string | null> => {
	if (!settings.store_selection_url) {
		return null
	}
	try {
		const resp = await fetch(settings.store_selection_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce ?? '',
			},
			credentials: 'same-origin',
			body: JSON.stringify({ provider, sub_type: subType }),
		})
		const result = (await resp.json()) as {
			code?: string
			data?: { redirect_target?: string }
		}
		if (!resp.ok || result.code !== 'success') {
			return null
		}
		return result.data?.redirect_target ?? null
	} catch {
		return null
	}
}

const StoreSelector = (): unknown => {
	const [isLoading, setIsLoading] = useState(false)
	const [notice, setNotice] = useState('')

	const { chosen, store } = useSelect((select) => {
		const cartData = select('wc/store/cart')?.getCartData?.() ?? {}
		return {
			chosen: getChosenCvs(cartData.shippingRates ?? []),
			store: readStore(cartData.extensions),
		}
	}, [])

	// 從門市地圖導回結帳頁時，讓 cart extension 重新讀 session 裡剛選好的門市
	useEffect(() => {
		extensionCartUpdate({ namespace: NAMESPACE, data: {} }).catch(() => {})
	}, [])

	if (!chosen) {
		return null
	}

	const matchedStore = store && store.sub_type === chosen.subType ? store : null

	const handleClick = async (): Promise<void> => {
		setNotice('')
		setIsLoading(true)
		const html = await requestMapHtml(chosen.provider, chosen.subType)
		setIsLoading(false)
		if (!html) {
			setNotice(i18n.error ?? '')
			return
		}
		document.open()
		document.write(html)
		document.close()
	}

	let label = i18n.select ?? '選擇門市'
	if (isLoading) {
		label = i18n.loading ?? '處理中…'
	} else if (matchedStore) {
		label = i18n.reselect ?? '重新選擇門市'
	}

	return createElement(
		'div',
		{ className: 'pc-logistics-store-selector', style: { marginTop: '12px' } },
		matchedStore
			? createElement(
					'div',
					{
						className: 'pc-logistics-selected-store',
						style: { marginBottom: '8px', fontSize: '14px', lineHeight: 1.5 },
					},
					createElement('span', null, `${i18n.pickup ?? '取貨門市'}：`),
					createElement(
						'strong',
						null,
						decodeEntities(matchedStore.store_name)
					),
					createElement('br', null),
					createElement(
						'span',
						{ style: { color: '#6b7280' } },
						decodeEntities(matchedStore.store_addr)
					)
				)
			: null,
		createElement(
			'button',
			{
				type: 'button',
				className:
					'wc-block-components-button wp-element-button pc-logistics-select-store',
				disabled: isLoading,
				onClick: handleClick,
				style: { padding: '8px 16px' },
			},
			label
		),
		notice
			? createElement(
					'div',
					{
						role: 'alert',
						style: { marginTop: '8px', color: '#cc1818', fontSize: '13px' },
					},
					notice
				)
			: null
	)
}

registerPlugin('pc-logistics-store-selection', {
	render: (): unknown =>
		createElement(
			Fragment,
			null,
			createElement(
				ExperimentalOrderShippingPackages,
				null,
				createElement(StoreSelector, null)
			)
		),
	scope: 'woocommerce-checkout',
})
