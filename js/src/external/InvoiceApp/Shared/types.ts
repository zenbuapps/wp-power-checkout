import {
	EIndividuals,
	EInvoiceTypes,
} from '@/external/InvoiceApp/Shared/constants'

export type TFormData = {
	provider: string // 服務提供商
	invoiceType?: `${EInvoiceTypes}` // 發票類型 個人、公司、捐贈
	individual?: `${EIndividuals}` // 個人發票類型 雲端發票、手機條碼、自然人憑證
	carrier?: string
	moica?: string
	companyName?: string
	companyId?: string
	donateCode?: string
}
