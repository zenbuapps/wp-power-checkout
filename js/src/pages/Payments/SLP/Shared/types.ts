import { EPaymentMethods } from '@/pages/Payments/SLP/Shared/enums'

export const PAYMENT_METHODS = [
	{
		value: EPaymentMethods.CREDIT_CARD,
		label: '信用卡',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EPaymentMethods.ATM,
		label: 'ATM 虛擬帳號',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EPaymentMethods.JKO_PAY,
		label: '街口支付',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EPaymentMethods.APPLE_PAY,
		label: 'Apple Pay',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EPaymentMethods.LINE_PAY,
		label: 'Line Pay',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EPaymentMethods.CHAILEASE_BNPL,
		label: '中租',
		disabled: false,
		tooltip: undefined,
	},
] as const

export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	order_button_text: string
	min_amount: number
	max_amount: number
	expire_min: number
	// --- API --- //
	mode: string
	// platformId?: string
	merchantId: string
	apiKey: string
	clientKey: string
	signKey: string
	allowPaymentMethodList: string[]
	paymentMethodOptions: {
		CreditCard: {
			installmentCounts: string[]
		}
		ChaileaseBNPL: {
			installmentCounts: string[]
		}
	}
}
