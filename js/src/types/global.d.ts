import { IOrderData } from '@/external/RefundDialog/types'

export {}

export interface IEnv {
	SITE_URL: string
	API_URL: string
	CURRENT_USER_ID: number
	CURRENT_POST_ID: number
	PERMALINK: string
	APP_NAME: string
	KEBAB: string
	SNAKE: string
	NONCE: string
	APP1_SELECTOR: string
	IS_LOCAL: boolean
}

declare global {
	interface Window {
		power_checkout_data: {
			env: IEnv
		} // 或更精確的型別
		power_checkout_order_data: IOrderData
		power_checkout_invoice_metabox_app_data: {
			render_ids: string[]
			order: {
				id: ''
			}
			is_admin: boolean
			is_issued: boolean
			invoice_providers: {
				id: string
				icon: string
				title: string
				description: string
				method_title: string
				method_description: string
				mode: 'test' | 'prod'
			}[]
		}
	}
}
