<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 新竹物流貨況查詢（searchGoods）加解密：DES-CBC + PKCS7 + Base64
 *
 * - KEY = 查詢當日（台北時間）± n 天的 `yyyyMMdd`（UTF-8 恰 8 bytes）；IV = 帳號專屬 8 位固定值
 * - 單組 DES 在 OpenSSL 3 屬 legacy（預設停用），改用 `des-ede3-cbc` + 三重相同金鑰 (K,K,K)：
 *   E(D(E(x,K),K),K) = E(x,K)，輸出與單組 DES 位元相同，且不需載入 legacy provider（skill cargo-tracking-decrypt.md §2 方案 0）
 * - 已對官方 Ver3.1 test vector 驗證（HctTrackCryptoTest）
 *
 * @see .claude/skills/hct-logistics/references/cargo-tracking-decrypt.md
 */
final class HctTrackCrypto {

	/** @var string OpenSSL cipher */
	private const CIPHER = 'des-ede3-cbc';

	/** @return bool 此環境 OpenSSL 是否提供 des-ede3-cbc */
	public static function is_available(): bool {
		return \function_exists( 'openssl_get_cipher_methods' ) && \in_array( self::CIPHER, \openssl_get_cipher_methods(), true );
	}

	/**
	 * 金鑰日期：台北時間「今天」+ offset 天
	 *
	 * @param int                     $offset_days 偏移天數（可為負）
	 * @param \DateTimeImmutable|null $now         現在時間（測試用）
	 * @return string yyyyMMdd
	 */
	public static function key_date( int $offset_days, ?\DateTimeImmutable $now = null ): string {
		$taipei = ( $now ?? new \DateTimeImmutable( 'now' ) )->setTimezone( new \DateTimeZone( 'Asia/Taipei' ) );
		return $taipei->setTime( 0, 0 )->modify( \sprintf( '%+d days', $offset_days ) )->format( 'Ymd' );
	}

	/**
	 * 加密
	 *
	 * @param string $plain    明文（貨號 / qrylist XML）
	 * @param string $key_date 金鑰日期 yyyyMMdd
	 * @param string $iv       8 位 IV
	 * @return string Base64
	 * @throws HctLogisticsException 參數錯誤 / 環境不支援 / 加密失敗
	 */
	public static function encrypt( string $plain, string $key_date, string $iv ): string {
		self::assert_params( $key_date, $iv );
		$cipher = \openssl_encrypt( $plain, self::CIPHER, \str_repeat( $key_date, 3 ), OPENSSL_RAW_DATA, $iv );
		if (false === $cipher) {
			throw new HctLogisticsException( '新竹物流貨況查詢加密失敗', ErrorCode::UNKNOWN );
		}
		return \base64_encode( $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 官方規格輸出 Base64
	}

	/**
	 * 解密
	 *
	 * @param string $cipher_b64 Base64 密文
	 * @param string $key_date   金鑰日期 yyyyMMdd
	 * @param string $iv         8 位 IV
	 * @return string 明文
	 * @throws HctLogisticsException 參數錯誤 / 解密失敗
	 */
	public static function decrypt( string $cipher_b64, string $key_date, string $iv ): string {
		self::assert_params( $key_date, $iv );
		$raw   = \base64_decode( \trim( $cipher_b64 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 官方規格輸入 Base64
		$plain = false === $raw ? false : \openssl_decrypt( $raw, self::CIPHER, \str_repeat( $key_date, 3 ), OPENSSL_RAW_DATA, $iv );
		if (false === $plain) {
			throw new HctLogisticsException( '新竹物流貨況查詢回應解密失敗（請確認查貨 IV / 金鑰偏移天數）', ErrorCode::UNKNOWN );
		}
		return $plain;
	}

	/**
	 * 參數檢查
	 *
	 * @param string $key_date 金鑰日期
	 * @param string $iv       IV
	 * @return void
	 * @throws HctLogisticsException 不合法 / 環境不支援
	 */
	private static function assert_params( string $key_date, string $iv ): void {
		if (8 !== \strlen( $key_date ) || 8 !== \strlen( $iv )) {
			throw HctLogisticsException::validation( '新竹物流貨況查詢金鑰日期與 IV 皆須為 8 位' );
		}
		if (!self::is_available()) {
			throw new HctLogisticsException( '此主機 OpenSSL 不支援 des-ede3-cbc，無法使用新竹物流貨況查詢', ErrorCode::UNSUPPORTED );
		}
	}
}
