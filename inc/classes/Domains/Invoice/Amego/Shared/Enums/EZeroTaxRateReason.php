<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

/** 零稅率原因，若為零稅率發票，則此欄位為必填。 */
enum EZeroTaxRateReason: int {
	// 第一款 外銷貨物
	case EXPORT_GOODS = 71;
	// 第二款 與外銷有關之勞務，或在國內提供而在國外使用之勞務
	case EXPORT_SERVICES = 72;
	// 第三款 依法設立之免稅商店銷售與過境或出境旅客之貨物
	case DUTY_FREE_STORE = 73;
	// 第四款 銷售與保稅區營業人供營運之貨物或勞務
	case BONDED_ZONE_SUPPLY = 74;
	// 第五款 國際間之運輸。但外國運輸事業在中華民國境內經營國際運輸業務者，應以各該國對中華民國國際運輸事業予以相等待遇或免徵類似稅捐者為限
	case INTERNATIONAL_TRANSPORT = 75;
	// 第六款 國際運輸用之船舶、航空器及遠洋漁船
	case TRANSPORTATION_VESSELS = 76;
	// 第七款 銷售與國際運輸用之船舶、航空器及遠洋漁船所使用之貨物或修繕勞務
	case VESSEL_SUPPLY_OR_REPAIR = 77;
	// 第八款 保稅區營業人銷售與課稅區營業人未輸往課稅區而直接出口之貨物
	case DIRECT_EXPORT_FROM_BONDED = 78;
	// 第九款 保稅區營業人銷售與課稅區營業人存入自由港區事業或海關管理之保稅倉庫、物流中心以供外銷之貨物
	case STORED_FOR_EXPORT = 79;
}
