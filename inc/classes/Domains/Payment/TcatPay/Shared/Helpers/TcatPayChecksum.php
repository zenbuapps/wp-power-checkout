<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers;

/**
 * 黑貓PAY 驗章（兩套 MD5，勿混用）—— 純函式
 *
 * 1. checksum（`:` 串接，**不含任何金鑰**）：APN、CvsIbonUpdateDate
 *    → 只能證明「欄位自洽」，**不能**證明來源是平台（R1）；入帳一律另以平台查詢確認。
 * 2. chk（`$` 串接，首段為 hash_base 密鑰）：COCS 授權導轉回報
 *
 * 所有欄位取原樣字串參與計算（不 parse、不補小數）。摘要比對大小寫不敏感、長度不同一律不相等。
 *
 * @see .claude/skills/tcat-pay/SKILL.md §驗章機制
 * @see .claude/skills/tcat-pay/references/callbacks-apn.md
 */
final class TcatPayChecksum {

	/** @var array<int, string> APN 參與 checksum 的必要欄位 */
	private const APN_FIELDS = [ 'api_id', 'trans_id', 'amount', 'status', 'nonce' ];

	/** @var array<int, string> 導轉授權成功 chk 欄位（hash_base 之後的順序） */
	private const SUCCESS_FIELDS = [ 'order_amount', 'send_time', 'ret', 'acquire_time', 'auth_code', 'card_no', 'notify_time', 'cust_order_no' ];

	/** @var array<int, string> 導轉授權失敗 chk 欄位（少 acquire_time / auth_code / card_no） */
	private const FAIL_FIELDS = [ 'order_amount', 'send_time', 'ret', 'notify_time', 'cust_order_no' ];

	/**
	 * APN checksum = MD5(api_id:trans_id:amount:status:nonce)
	 *
	 * @param string $api_id   契客代號
	 * @param string $trans_id 交易識別碼
	 * @param string $amount   金額（原樣字串）
	 * @param string $status   狀態碼
	 * @param string $nonce    隨機數
	 * @return string 32 碼小寫 hex
	 */
	public static function apn_checksum( string $api_id, string $trans_id, string $amount, string $status, string $nonce ): string {
		return \md5( "{$api_id}:{$trans_id}:{$amount}:{$status}:{$nonce}" );
	}

	/**
	 * CvsIbonUpdateDate checksum = MD5(cust_order_no:order_amount:nonce)
	 *
	 * @param string $cust_order_no 契客訂單編號
	 * @param string $order_amount  金額
	 * @param string $nonce         隨機數
	 * @return string
	 */
	public static function ibon_update_date_checksum( string $cust_order_no, string $order_amount, string $nonce ): string {
		return \md5( "{$cust_order_no}:{$order_amount}:{$nonce}" );
	}

	/**
	 * COCS 授權成功導轉 chk
	 *
	 * @param string               $hash_base hash_base 密鑰
	 * @param array<string, mixed> $query     導轉 query
	 * @return string
	 */
	public static function cocs_chk_success( string $hash_base, array $query ): string {
		return self::dollar_md5( $hash_base, $query, self::SUCCESS_FIELDS );
	}

	/**
	 * COCS 授權失敗導轉 chk
	 *
	 * @param string               $hash_base hash_base 密鑰
	 * @param array<string, mixed> $query     導轉 query
	 * @return string
	 */
	public static function cocs_chk_fail( string $hash_base, array $query ): string {
		return self::dollar_md5( $hash_base, $query, self::FAIL_FIELDS );
	}

	/**
	 * 驗 APN checksum（缺必要欄位一律 false）
	 *
	 * @param array<mixed> $payload APN payload
	 * @return bool
	 */
	public static function verify_apn( array $payload ): bool {
		foreach ( [ ...self::APN_FIELDS, 'checksum' ] as $field ) {
			if ( ! isset( $payload[ $field ] ) || ! \is_scalar( $payload[ $field ] ) || '' === (string) $payload[ $field ] ) {
				return false;
			}
		}

		$expected = self::apn_checksum(
			(string) $payload['api_id'],
			(string) $payload['trans_id'],
			(string) $payload['amount'],
			(string) $payload['status'],
			(string) $payload['nonce']
		);

		return self::equals( $expected, (string) $payload['checksum'] );
	}

	/**
	 * 驗 COCS 導轉 chk（依 ret 選公式；hash_base 空 / 缺欄一律 false，fail-closed）
	 *
	 * @param string               $hash_base hash_base 密鑰
	 * @param array<string, mixed> $query     導轉 query
	 * @return bool
	 */
	public static function verify_return( string $hash_base, array $query ): bool {
		if ( '' === $hash_base || ! isset( $query['chk'], $query['ret'] ) || ! \is_string( $query['chk'] ) ) {
			return false;
		}

		$fields = 'OK' === $query['ret'] ? self::SUCCESS_FIELDS : self::FAIL_FIELDS;
		foreach ( $fields as $field ) {
			if ( ! isset( $query[ $field ] ) || ! \is_scalar( $query[ $field ] ) ) {
				return false;
			}
		}

		return self::equals( self::dollar_md5( $hash_base, $query, $fields ), $query['chk'] );
	}

	/**
	 * 定速比較兩個 hex 摘要（大小寫不敏感；空或長度不同 false）
	 *
	 * @param string $expected 預期
	 * @param string $actual   實際
	 * @return bool
	 */
	public static function equals( string $expected, string $actual ): bool {
		$a = \strtolower( \trim( $expected ) );
		$b = \strtolower( \trim( $actual ) );
		if ( '' === $a || \strlen( $a ) !== \strlen( $b ) ) {
			return false;
		}
		return \hash_equals( $a, $b );
	}

	/**
	 * MD5(hash_base$f1$f2…)
	 *
	 * @param string               $hash_base hash_base
	 * @param array<string, mixed> $query     query
	 * @param array<int, string>   $fields    欄位順序
	 * @return string
	 */
	private static function dollar_md5( string $hash_base, array $query, array $fields ): string {
		$parts = [ $hash_base ];
		foreach ( $fields as $field ) {
			$value   = $query[ $field ] ?? '';
			$parts[] = \is_scalar( $value ) ? (string) $value : '';
		}
		return \md5( \implode( '$', $parts ) );
	}
}
