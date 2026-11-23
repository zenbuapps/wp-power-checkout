<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions\SfExpressException;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 順豐國際開放平台加解密 / 簽章（官方 BizMsgCrypt 同構，不依賴 WordPress）
 *
 * - AES-256-CBC NoPadding + 自行 PKCS7（block = 32，不是 16）；key = base64_decode(encodingAesKey + "=")（32 bytes）；IV = key 前 16 bytes
 * - 明文組裝：16 字元隨機英數 + 4 bytes 大端序長度（UTF-8 byte 長度）+ JSON + appKey
 * - dispatch signature = sha256_hex( 字典序排序後串接 [token, timestamp, nonce, encrypt] )
 * - 軌跡推送 sign = Base64( MD5( key + 原始 body + key ) )（推送不加密，另一套簽名）
 *
 * 解密後尾碼必須等於 appKey（官方 -40005），不等即視為竄改 / 非本應用密文 → SIGNATURE。
 * 例外訊息不含金鑰與密文內容。
 *
 * @see .claude/skills/sf-express/references/auth-and-crypto.md §0.4–§0.5
 * @see .claude/skills/sf-express/references/php-client.md §2
 */
final class SfCrypto {

	/** @var int PKCS7 block（官方 PKCS7Encoder 用 32） */
	private const BLOCK = 32;

	/** @var int encodingAesKey 長度 */
	public const AES_KEY_LENGTH = 43;

	/** @var string 隨機前綴字元池 */
	private const RANDOM_POOL = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

	/** @var string 32 bytes AES key */
	private readonly string $key;

	/** @var string 16 bytes IV（key 前 16 bytes） */
	private readonly string $iv;

	/**
	 * Constructor
	 *
	 * @param string $app_key          appKey（明文尾碼）
	 * @param string $encoding_aes_key encodingAesKey（43 字元）
	 * @throws SfExpressException VALIDATION 金鑰格式錯誤
	 */
	public function __construct( private readonly string $app_key, string $encoding_aes_key ) {
		if (!self::is_valid_aes_key( $encoding_aes_key )) {
			throw SfExpressException::validation( '順豐 AES 金鑰（encodingAesKey）格式錯誤：須為 43 個字元的 Base64 字串' );
		}
		$this->key = (string) \base64_decode( $encoding_aes_key . '=', true );
		$this->iv  = \substr( $this->key, 0, 16 );
	}

	/**
	 * 驗證 encodingAesKey 是否合法（43 字元、補 = 後可 base64 解出 32 bytes）
	 *
	 * @param string $encoding_aes_key 金鑰
	 * @return bool
	 */
	public static function is_valid_aes_key( string $encoding_aes_key ): bool {
		if (self::AES_KEY_LENGTH !== \strlen( $encoding_aes_key )) {
			return false;
		}
		$decoded = \base64_decode( $encoding_aes_key . '=', true );
		return false !== $decoded && 32 === \strlen( $decoded );
	}

	/**
	 * 明文 JSON → base64 密文（即 HTTP body 本身）
	 *
	 * @param string $plain 明文
	 * @return string
	 * @throws SfExpressException 加密失敗
	 */
	public function encrypt( string $plain ): string {
		$random = '';
		$max    = \strlen( self::RANDOM_POOL ) - 1;
		for ($i = 0; $i < 16; $i++) {
			$random .= self::RANDOM_POOL[ \random_int( 0, $max ) ];
		}

		$data  = $random . \pack( 'N', \strlen( $plain ) ) . $plain . $this->app_key;
		$pad   = self::BLOCK - ( \strlen( $data ) % self::BLOCK );
		$data .= \str_repeat( \chr( $pad ), $pad );

		$cipher = \openssl_encrypt( $data, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv );
		if (false === $cipher) {
			throw new SfExpressException( '順豐報文加密失敗', ErrorCode::UNKNOWN );
		}
		return \base64_encode( $cipher );
	}

	/**
	 * 解密：base64 密文 → 明文 JSON（apiResultData 用）
	 *
	 * @param string $cipher_b64 base64 密文
	 * @return string
	 * @throws SfExpressException SIGNATURE 無法解密 / 格式錯 / appKey 不符
	 */
	public function decrypt( string $cipher_b64 ): string {
		$raw = \base64_decode( $cipher_b64, true );
		if (false === $raw || '' === $raw || 0 !== \strlen( $raw ) % 16) {
			throw self::decrypt_error( '密文格式錯誤' );
		}

		$plain = \openssl_decrypt( $raw, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv );
		if (false === $plain || \strlen( $plain ) < 20) {
			throw self::decrypt_error( '無法解密' );
		}

		$pad   = \ord( \substr( $plain, -1 ) );
		$pad   = ( $pad < 1 || $pad > self::BLOCK ) ? 0 : $pad;
		$plain = \substr( $plain, 0, \strlen( $plain ) - $pad );

		/** @var array{1: int}|false $unpacked */
		$unpacked = \unpack( 'N', \substr( $plain, 16, 4 ) );
		$length   = false !== $unpacked ? $unpacked[1] : -1;
		if ($length < 0 || 20 + $length > \strlen( $plain )) {
			throw self::decrypt_error( '長度欄位不正確' );
		}

		if (!\hash_equals( $this->app_key, \substr( $plain, 20 + $length ) )) {
			throw self::decrypt_error( 'appKey 校驗失敗' );
		}
		return \substr( $plain, 20, $length );
	}

	/**
	 * 計算 dispatch signature
	 *
	 * @param string $token     Access Token
	 * @param string $timestamp 毫秒時間戳（與 header 同值）
	 * @param string $nonce     隨機數（與 header 同值）
	 * @param string $encrypt   本次 body 密文
	 * @return string 小寫 hex
	 */
	public static function signature( string $token, string $timestamp, string $nonce, string $encrypt ): string {
		$parts = [ $token, $timestamp, $nonce, $encrypt ];
		\sort( $parts, SORT_STRING );
		return \hash( 'sha256', \implode( '', $parts ) );
	}

	/**
	 * 軌跡推送 sign
	 *
	 * @param string $raw_body 原始 body（不可 parse 後重組）
	 * @param string $key      推送金鑰（聯調時順豐提供）
	 * @return string
	 */
	public static function push_sign( string $raw_body, string $key ): string {
		return \base64_encode( \md5( $key . $raw_body . $key, true ) );
	}

	/**
	 * 驗證軌跡推送 sign（金鑰或 sign 為空一律 false，fail-closed）
	 *
	 * @param string $raw_body 原始 body
	 * @param string $key      推送金鑰
	 * @param string $sign     header sign
	 * @return bool
	 */
	public static function verify_push_sign( string $raw_body, string $key, string $sign ): bool {
		if ('' === $key || '' === $sign) {
			return false;
		}
		return \hash_equals( self::push_sign( $raw_body, $key ), $sign );
	}

	/**
	 * 解密失敗例外
	 *
	 * @param string $reason 原因
	 * @return SfExpressException
	 */
	private static function decrypt_error( string $reason ): SfExpressException {
		return new SfExpressException( "順豐報文解密失敗：{$reason}", ErrorCode::SIGNATURE );
	}
}
