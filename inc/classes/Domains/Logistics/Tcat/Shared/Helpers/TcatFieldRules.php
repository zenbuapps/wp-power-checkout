<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers;

/**
 * 黑貓欄位規則（純函式）
 *
 * @see .claude/skills/tcat-logistics/references/api-reference.md §1.4、§2.1、§13.8
 */
final class TcatFieldRules {

	/** @var array<int, string> ProductName 禁用字串（子字串比對，19 個） */
	public const FORBIDDEN_PRODUCT_WORDS = [
		'文件',
		'標單',
		'明信片',
		'郵簡',
		'信函',
		'資料',
		'發票',
		'訴訟文件',
		'公文',
		'身分證明文件',
		'報名表',
		'申請書',
		'帳單',
		'訂單',
		'報價單',
		'繳費單',
		'通知單',
		'保險單',
		'文書',
	];

	/** @var int ProductName 長度上限 */
	public const PRODUCT_NAME_MAX = 20;

	/**
	 * PostNumber（如 71-802-24-B）→ 六碼郵號（去連字號取末六碼 → 80224B）
	 *
	 * @param string $post_number ParsingAddress 回傳的郵號或使用者輸入
	 * @return string 取不出六碼回空字串
	 */
	public static function to_six_digit_zip( string $post_number ): string {
		$compact = (string) \preg_replace( '/[^0-9A-Za-z]/', '', $post_number );
		return \strlen( $compact ) >= 6 ? \strtoupper( \substr( $compact, -6 ) ) : '';
	}

	/**
	 * 商品名稱是否含禁用字串
	 *
	 * @param string $name 商品名稱
	 * @return bool
	 */
	public static function has_forbidden_product_word( string $name ): bool {
		foreach ( self::FORBIDDEN_PRODUCT_WORDS as $word ) {
			if (\str_contains( $name, $word )) {
				return true;
			}
		}
		return false;
	}

	/**
	 * OrderId：去空白與黑貓禁用符號（/ _ @ | \），上限 35 字
	 *
	 * @param string $order_number 訂單編號
	 * @return string
	 */
	public static function order_id( string $order_number ): string {
		$clean = (string) \preg_replace( '/[\s\/_@|\\\\]/u', '', $order_number );
		return \mb_substr( $clean, 0, 35 );
	}

	/**
	 * 電話拆成「市話 / 手機」：09 開頭 10 碼為手機，其餘僅保留數字與 - # ,
	 *
	 * @param string $phone 原始電話
	 * @return array{tel: string, mobile: string}
	 */
	public static function split_phone( string $phone ): array {
		$compact = (string) \preg_replace( '/[\s()]/', '', $phone );
		$digits  = (string) \preg_replace( '/\D/', '', $compact );
		if (1 === \preg_match( '/^(?:886)?0?9\d{8}$/', $digits )) {
			return [
				'tel'    => '',
				'mobile' => '09' . \substr( $digits, -8 ),
			];
		}
		$tel = (string) \preg_replace( '/[^0-9\-#,]/', '', $compact );
		return [
			'tel'    => \substr( $tel, 0, 30 ),
			'mobile' => '',
		];
	}
}
