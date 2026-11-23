import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { getSetting } from '@woocommerce/settings'
import { decodeEntities } from '@wordpress/html-entities'

const id = 'shopline_payment_embedded'
const settings = getSetting(`${id}_data`, {})
const { name, order_button_text, supports: features } = settings
const label = decodeEntities(settings.title)

const Content = () => {
	return decodeEntities(settings.description || '')
}

/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = (props: any) => {
	const { PaymentMethodLabel } = props.components
	return <PaymentMethodLabel text={label} />
}

const options = {
	name,
	label: <Label />,
	ariaLabel: label,
	placeOrderButtonLabel: order_button_text,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	paymentMethodId: id,
	supports: {
		features,

		// SHOPLINE Payments 嵌入式（站內付）：SDK 收銀台與 3DS 流程於 order-received 頁由
		// MountShoplineEmbedded 處理（比照 ECPG / PAYUNi UNi Embed / PayNow），block 結帳階段不渲染卡片表單、不存綁定卡片。
		showSavedCards: false,
		showSaveOption: false,
	},
}

/**
 * 註冊 SHOPLINE Payments 嵌入式（站內付）付款方式
 */
registerPaymentMethod(options)
