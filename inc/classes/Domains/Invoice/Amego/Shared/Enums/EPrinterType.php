<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

/**
 * 熱感應機型號代碼
 *
 * @see https://invoice.amego.tw/info_detail?mid=77
 */
enum EPrinterType: int {
	case STAR_MC_PRINT3   = 1;
	case XPRINTER         = 2;
	case EPSON_TM_T82III  = 3;
	case    HPRT_TP805L   = 4;
	case XP_P201A         = 5;
	case GM_Q99K          = 7;
	case STAR_TSP650II    = 8;
	case RONGTA_ACE_G1_UE = 9;
	case    BIRCH_QM3     = 10;
	case WP_T810          = 11;
	case    HPRT_TP801    = 12;
}
