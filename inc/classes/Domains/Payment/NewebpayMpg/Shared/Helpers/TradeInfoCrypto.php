<?php
/**
 * 藍新 NewebPay MPG TradeInfo 加解密 + 驗章（AES-256-CBC + SHA256）
 *
 * ⚠️ 絕對不可複用 J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto
 *    —— 那是 AES-128-CBC + base64；藍新 MPG 是 AES-256-CBC + hex，演算法與輸出格式皆不同。
 *
 * 加密規格（NDNF-1.2.2，以 newebpay-mpg skill 為準）：
 *  - 演算法：aes-256-cbc
 *  - Key：HashKey padEnd(32,'0') 取前 32 bytes
 *  - IV：HashIV padEnd(16,'0') 取前 16 bytes
 *  - Padding：加密用 PKCS#7（openssl CBC 預設，16 bytes 區塊，藍新可解）；
 *    解密須相容藍新的 32 bytes 區塊 PKCS#7（補位值 1～32，見 decrypt）
 *  - 輸出：hex（小寫，bin2hex）
 *  - 輸入：已組好的 key=value&... 明文字串（value 須先 URL-encode，由呼叫端負責）
 *
 * TradeSha（整包驗章）：SHA256("HashKey={K}&{hex}&HashIV={IV}") → 大寫
 *  - ⚠️ 必須大寫，小寫會被藍新回 MPG03012。
 *
 * CheckCode（callback 內層交易結果驗章，與 TradeSha 是兩個不同雜湊）：
 *  - 固定欄位順序：HashIV, Amt, MerchantID, MerchantOrderNo, TradeNo, HashKey
 *  - SHA256(...) → 大寫
 *  - key 名為 HashIV / HashKey（與 TradeSha 相同字面），但欄位順序與夾帶內容不同，不可混用。
 *
 * HashKey / HashIV 由建構子參數注入（不依賴任何 Settings 單例），方便測試與多帳號情境。
 *
 * @see .claude/skills/newebpay-mpg/SKILL.md §AES-256-CBC / SHA256
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §CheckCode Verification
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers;

/** 藍新 MPG AES-256-CBC 加解密 + TradeSha / CheckCode 驗章 Helper */
final class TradeInfoCrypto {

	/** @var string AES 演算法（256，與 Ecpg 的 128 不同） */
	private const CIPHER = 'aes-256-cbc';

	/** @var int Key 長度（bytes） */
	private const KEY_LENGTH = 32;

	/** @var int IV 長度（bytes） */
	private const IV_LENGTH = 16;

	/** @var int 藍新 PKCS#7 補位區塊大小（bytes；補位值最大 32，見 decrypt） */
	private const PADDING_BLOCK_SIZE = 32;

	/** Constructor */
	public function __construct(
		/** @var string 藍新 HashKey */
		private readonly string $hash_key,
		/** @var string 藍新 HashIV */
		private readonly string $hash_iv,
	) {}

	/**
	 * AES-256-CBC 加密，輸出 hex
	 *
	 * @param string $plaintext 已組好的 key=value&... 明文字串（value 須已 URL-encode）
	 *
	 * @return string hex 字串（小寫）
	 * @throws \RuntimeException When openssl_encrypt 失敗.
	 */
	public function encrypt( string $plaintext ): string {
		$encrypted = \openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA, // 原始二進位 + PKCS#7（CBC 預設）
			$this->get_iv()
		);

		if ( false === $encrypted ) {
			throw new \RuntimeException( 'NewebPay MPG TradeInfo encrypt 失敗' );
		}

		return \bin2hex( $encrypted );
	}

	/**
	 * AES-256-CBC 解密，輸入 hex
	 *
	 * ⚠️ 藍新加密時以 32 bytes 為區塊做 PKCS#7 補位（官方 PHP 範例 addpadding blocksize=32，ezPay 同體系亦同），
	 *    補位值可能是 17～32；OpenSSL 預設的 PKCS#7 解補位只認 1～16，遇到 > 16 會直接回 false
	 *    （ATM / 超商取號回傳明文較長，約一半機率踩到，issue #24）。
	 *    故以 OPENSSL_ZERO_PADDING 關掉 OpenSSL 解補位，自行移除 1～32 的 PKCS#7 補位（同時相容 16 bytes 區塊）。
	 *    TradeSha 已在解密前驗過，自行解補位不降低安全性。
	 *
	 * @param string $encrypted_hex hex 密文字串
	 *
	 * @return string 解密後明文（JSON 或 key=value&... 字串）
	 * @throws \RuntimeException When hex 非法 / openssl_decrypt 失敗 / 補位格式不符.
	 */
	public function decrypt( string $encrypted_hex ): string {
		$raw = \hex2bin( \trim( $encrypted_hex ) );
		if ( false === $raw ) {
			throw new \RuntimeException( 'NewebPay MPG TradeInfo decrypt：hex2bin 失敗' );
		}

		$decrypted = \openssl_decrypt(
			$raw,
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, // 不讓 OpenSSL 解補位，由 strip_padding 處理
			$this->get_iv()
		);

		if ( false === $decrypted || '' === $decrypted ) {
			throw new \RuntimeException( 'NewebPay MPG TradeInfo decrypt：openssl_decrypt 失敗' );
		}

		return self::strip_padding( $decrypted );
	}

	/**
	 * 移除 PKCS#7 補位（補位值 1～32，相容藍新 32 bytes 區塊與標準 16 bytes 區塊）
	 *
	 * @param string $decrypted 含補位的明文
	 *
	 * @return string 去除補位後的明文
	 * @throws \RuntimeException When 補位格式不符.
	 */
	private static function strip_padding( string $decrypted ): string {
		$length = \strlen( $decrypted );
		$pad    = \ord( $decrypted[ $length - 1 ] );

		if ( $pad < 1 || $pad > self::PADDING_BLOCK_SIZE || $pad > $length
			|| \substr( $decrypted, -$pad ) !== \str_repeat( \chr( $pad ), $pad ) ) {
			throw new \RuntimeException( 'NewebPay MPG TradeInfo decrypt：補位格式不符' );
		}

		return \substr( $decrypted, 0, $length - $pad );
	}

	/**
	 * 產生 TradeSha（整包驗章）
	 *
	 * 公式：SHA256("HashKey={K}&{hex}&HashIV={IV}") → 大寫。
	 * ⚠️ 必為大寫，小寫會被藍新回 MPG03012。
	 *
	 * @param string $encrypted_hex 已加密的 TradeInfo（hex）
	 *
	 * @return string TradeSha（64 字大寫 hex）
	 */
	public function generate_trade_sha( string $encrypted_hex ): string {
		$raw = "HashKey={$this->hash_key}&{$encrypted_hex}&HashIV={$this->hash_iv}";
		return \strtoupper( \hash( 'sha256', $raw ) );
	}

	/**
	 * 驗證 callback 內層 Result 的 CheckCode（timing-safe）
	 *
	 * 固定欄位順序：HashIV, Amt, MerchantID, MerchantOrderNo, TradeNo, HashKey。
	 * ⚠️ 與 generate_trade_sha 是兩個不同雜湊，欄位順序與夾帶內容皆不同。
	 *
	 * @param array<string, mixed> $result   藍新回傳的 Result（取 Amt/MerchantID/MerchantOrderNo/TradeNo）
	 * @param string               $received 藍新回傳的 CheckCode
	 *
	 * @return bool
	 */
	public function verify_check_code( array $result, string $received ): bool {
		if ( '' === $received ) {
			return false;
		}

		$calculated = $this->generate_check_code( $result );

		return \hash_equals( $calculated, \strtoupper( $received ) );
	}

	/**
	 * 產生 CheckCode（固定欄位順序）
	 *
	 * @param array<string, mixed> $result Result 欄位
	 *
	 * @return string CheckCode（64 字大寫 hex）
	 */
	public function generate_check_code( array $result ): string {
		$amt               = (string) ( $result['Amt'] ?? '' );
		$merchant_id       = (string) ( $result['MerchantID'] ?? '' );
		$merchant_order_no = (string) ( $result['MerchantOrderNo'] ?? '' );
		$trade_no          = (string) ( $result['TradeNo'] ?? '' );

		// 官方固定順序：HashIV, Amt, MerchantID, MerchantOrderNo, TradeNo, HashKey
		$raw = "HashIV={$this->hash_iv}"
		. "&Amt={$amt}"
		. "&MerchantID={$merchant_id}"
		. "&MerchantOrderNo={$merchant_order_no}"
		. "&TradeNo={$trade_no}"
		. "&HashKey={$this->hash_key}";

		return \strtoupper( \hash( 'sha256', $raw ) );
	}

	/**
	 * 產生 QueryTradeInfo 的 CheckValue（對帳查詢用）
	 *
	 * ⚠️ 與 TradeSha / CheckCode 是「第三種」雜湊，最易混淆之處：
	 *  - 欄位順序固定：IV, Amt, MerchantID, MerchantOrderNo, Key
	 *  - key 名為 IV= / Key=（注意：不是 HashIV= / HashKey=，那是 TradeSha / CheckCode 用的）
	 *  - SHA256(...) → 大寫
	 *
	 * @param string $merchant_id       特店編號
	 * @param string $merchant_order_no 查詢的訂單編號
	 * @param int    $amt               原始金額
	 *
	 * @return string CheckValue（64 字大寫 hex）
	 */
	public function generate_check_value( string $merchant_id, string $merchant_order_no, int $amt ): string {
		// 官方固定順序：IV, Amt, MerchantID, MerchantOrderNo, Key（鍵名為 IV / Key，非 HashIV / HashKey）
		$raw = "IV={$this->hash_iv}"
		. "&Amt={$amt}"
		. "&MerchantID={$merchant_id}"
		. "&MerchantOrderNo={$merchant_order_no}"
		. "&Key={$this->hash_key}";

		return \strtoupper( \hash( 'sha256', $raw ) );
	}

	/** @return string AES Key（HashKey padEnd(32,'0') 取前 32 bytes） */
	private function get_key(): string {
		return \substr( \str_pad( $this->hash_key, self::KEY_LENGTH, '0' ), 0, self::KEY_LENGTH );
	}

	/** @return string AES IV（HashIV padEnd(16,'0') 取前 16 bytes） */
	private function get_iv(): string {
		return \substr( \str_pad( $this->hash_iv, self::IV_LENGTH, '0' ), 0, self::IV_LENGTH );
	}
}
