<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers;

/**
 * LINE Pay Online API v3 / v4 簽章與回應解碼
 *
 * 公式：
 *   message   = ChannelSecret + apiPath + (POST：實際送出的 body 原文 | GET：? 之後的 query) + nonce
 *   signature = Base64(HMAC-SHA256(key = ChannelSecret, message))
 *
 * 三個會靜默壞掉（只得到 1106）的地方：
 *  1. body 要簽「實際送出的那串 bytes」——序列化一次、同一字串簽完就送。
 *  2. 無 body 的 POST 簽空字串且不送 body（簽 "null" 實測 1106）。
 *  3. query 不含開頭的 `?`。
 *
 * transactionId / refundTransactionId / originalTransactionId 為 19 位整數（R4）：
 * 解碼前把 16 位以上的數字值轉成字串，再以 JSON_BIGINT_AS_STRING 解碼，保證不經 float、也不依賴 64-bit int。
 *
 * @see .claude/skills/linepay/references/authentication.md
 * @see zenbu payments/linepay/linepay-signature.ts
 */
final class LinepaySignature {

	/**
	 * 計算 X-LINE-Authorization
	 *
	 * @param string $channel_secret Channel Secret
	 * @param string $api_path       含版本的路徑（不含 host、不含 ?）
	 * @param string $payload        POST body 原文（無 body 為空字串）或 GET query（無則空字串）
	 * @param string $nonce          nonce
	 * @return string Base64 簽章
	 */
	public static function sign( string $channel_secret, string $api_path, string $payload, string $nonce ): string {
		return \base64_encode( \hash_hmac( 'sha256', $channel_secret . $api_path . $payload . $nonce, $channel_secret, true ) );
	}

	/**
	 * 產生 UUID v4 nonce（每次請求唯一）
	 *
	 * @return string
	 */
	public static function nonce(): string {
		$bytes    = \random_bytes( 16 );
		$bytes[6] = \chr( ( \ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = \chr( ( \ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return \vsprintf( '%s%s-%s-%s-%s-%s%s%s', \str_split( \bin2hex( $bytes ), 4 ) );
	}

	/**
	 * 解碼 LINE Pay 回應，16 位以上的整數一律轉字串（保住 19 位交易 ID）
	 *
	 * @param string $raw 回應原文
	 * @return array<string, mixed>|null 非 JSON 物件回 null
	 */
	public static function decode_json( string $raw ): ?array {
		$safe = \preg_replace( '/:\s*(-?\d{16,})(?=\s*[,}\]])/', ':"$1"', $raw );
		$json = \json_decode( \is_string( $safe ) ? $safe : $raw, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! \is_array( $json ) ) {
			return null;
		}
		/** @var array<string, mixed> $json */
		return $json;
	}
}
