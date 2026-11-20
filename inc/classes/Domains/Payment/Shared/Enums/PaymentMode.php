<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Enums;

/**
 * 金流付款模式（對齊 zenbu PaymentGateway 的 paymentMode）
 *
 * - FORM_REDIRECT：後端組表單、瀏覽器 auto-submit 導向金流頁（綠界 AIO / 藍新 MPG / PAYUNi UPP）
 * - API_REDIRECT：後端呼叫金流 API 取得付款網址再導轉（SLP 導轉式 / LINE Pay）
 * - CLIENT_SDK：前端載入金流 SDK 於站內完成付款（ECPG / UNi Embed / PayNow）
 */
enum PaymentMode: string {
	/** 表單導轉 */
	case FORM_REDIRECT = 'form-redirect';

	/** API 取付款網址後導轉 */
	case API_REDIRECT = 'api-redirect';

	/** 前端 SDK 站內付 */
	case CLIENT_SDK = 'client-sdk';
}
