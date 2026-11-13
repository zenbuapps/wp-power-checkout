/**
 * 結帳體驗強化設定（對齊 CheckoutEnhancementSettingsDTO / specs/api.yml CheckoutEnhancementSettings）
 *
 * 開關值為 'yes' / 'no'；色碼為 #RRGGBB；未達門檻文案須包含 {price}。
 */
export type TToggleValue = 'yes' | 'no'

export type TCheckoutEnhancementSettings = {
	// --- 地址與姓名 --- //
	last_name_first: TToggleValue
	tw_address: TToggleValue

	// --- 欄位檢查 --- //
	tw_phone_validation: TToggleValue

	// --- 虛擬商品 --- //
	virtual_hide_address: TToggleValue
	virtual_auto_complete: TToggleValue

	// --- 免運提示 --- //
	free_shipping_hint: TToggleValue
	free_shipping_text_left: string
	free_shipping_text_achieved: string
	free_shipping_bg_color: string
	free_shipping_text_color: string
}

/** 未達門檻文案的金額佔位符 */
export const PRICE_PLACEHOLDER = '{price}'
