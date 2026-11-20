<?php
/**
 * 順豐加解密 / 簽章測試（P6：sf_express）
 *
 * golden 向量只取 skill `auth-and-crypto.md` §0.8（官方 Php.zip Demo.php / Python Sample.py 公開樣例；
 * 密文本文在 `php-client.md` §7「測試向量」）：
 *   encodingAesKey=dcLutUPXvxDjzVDgLV0SsAI5UAxmEloNHEZVtgxnvuA、appKey=76a5c52b9027505f4fcc4c358fb58211
 *   → 兩組官方密文解出 `sdfsdafafasdfadfad发生的发生地方`、`1231231`
 * 這兩組是官方公開的 SampleCode 金鑰，不是任何帳號憑證。
 * signature golden：同一份 Demo 的 token / timestamp / nonce + 密文 A，排序後 sha256（PHP sort SORT_STRING）。
 * 推送 sign golden：`php-client.md` §7 key="k"、body='[{"a":1}]' → H58CQELiXLV+IZi9a1oHxg==。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfCrypto --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions\SfExpressException;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfCrypto;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\TestCase;

/**
 * 順豐加解密
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfCryptoTest extends TestCase {

	/** @var string 官方 Demo encodingAesKey（公開樣例） */
	private const DEMO_AES_KEY = 'dcLutUPXvxDjzVDgLV0SsAI5UAxmEloNHEZVtgxnvuA';

	/** @var string 官方 Demo appKey（公開樣例） */
	private const DEMO_APP_KEY = '76a5c52b9027505f4fcc4c358fb58211';

	/** @var string 官方 Demo.php 密文 A */
	private const CIPHER_A = '8J2XeGGpK5oEcIytFfln91CYH4VfeXpV73X7Q+O9lJFTlMr0eV4YH+6r0HpIY8pSJIPqeVqEcjFR+ZS92b9M7jnijYEh6LMkCg85XP5tDBMbbk+eWrU8++mzaa7jKI7W';

	/** @var string 官方 Sample.py 密文 B */
	private const CIPHER_B = 'T/TnaXcxM1qEBijGMtWbUPzVqEgke4CjKXGA63JMD9JGw4U1vlw5MiFQEWDzsjHHQqPkokq6nOxUZFgg3gtDMA==';

	/** @return SfCrypto 官方 Demo 參數 */
	private function demo(): SfCrypto {
		return new SfCrypto( self::DEMO_APP_KEY, self::DEMO_AES_KEY );
	}

	/**
	 * Happy：官方 Demo.php 密文 A（含中文）解密
	 *
	 * @group happy
	 */
	public function test_官方密文A解密(): void {
		$this->assertSame( 'sdfsdafafasdfadfad发生的发生地方', $this->demo()->decrypt( self::CIPHER_A ) );
	}

	/**
	 * Happy：官方 Sample.py 密文 B 解密
	 *
	 * @group happy
	 */
	public function test_官方密文B解密(): void {
		$this->assertSame( '1231231', $this->demo()->decrypt( self::CIPHER_B ) );
	}

	/**
	 * Happy：加密後可解回（中文 JSON；長度欄位是 UTF-8 byte 長度），且每次密文不同（16 bytes 隨機前綴）
	 *
	 * @group happy
	 */
	public function test_加解密往返_含中文(): void {
		$crypto = $this->demo();
		$plain  = (string) \wp_json_encode(
			[
				'customerCode' => 'X',
				'contact'      => '王小明',
				'address'      => '臺中市大雅區中清路三段513號',
			],
			JSON_UNESCAPED_UNICODE
			);

		$a = $crypto->encrypt( $plain );
		$b = $crypto->encrypt( $plain );

		$this->assertNotSame( $a, $b );
		$this->assertSame( $plain, $crypto->decrypt( $a ) );
		$this->assertSame( $plain, $crypto->decrypt( $b ) );
		$this->assertSame( 0, \strlen( (string) \base64_decode( $a, true ) ) % 32, '密文長度須為 32 bytes 的倍數（PKCS7 block 32）' );
	}

	/**
	 * Edge：明文 + 前後綴剛好整除 32 → 補滿 32 bytes 仍可解回
	 *
	 * @group edge
	 */
	public function test_剛好整除32時補滿一整塊(): void {
		$crypto = $this->demo();
		// 16 + 4 + len + 32(appKey) ≡ 0 (mod 32) → len = 12
		$plain  = '123456789012';
		$cipher = $crypto->encrypt( $plain );

		$this->assertSame( 64 + 32, \strlen( (string) \base64_decode( $cipher, true ) ) );
		$this->assertSame( $plain, $crypto->decrypt( $cipher ) );
	}

	/**
	 * Happy：signature = sha256(字典序排序後串接 token / timestamp / nonce / encrypt)
	 *
	 * @group happy
	 */
	public function test_signature_golden(): void {
		$token = 'auth_d830f571-570a-44e7-a51a-c49b1ec6d79f_1637927849980';
		$ts    = '1637927849983';

		$signature = SfCrypto::signature( $token, $ts, $ts, self::CIPHER_A );

		// 排序後順序：兩個數字字串 < 密文（8 開頭）< token（a 開頭）
		$this->assertSame( \hash( 'sha256', $ts . $ts . self::CIPHER_A . $token ), $signature );
		$this->assertSame( '2ba1370b988b1b4bb2b468f102b7eaf5d2258e06574ecb327f6b4a9c2458339d', $signature );
		// 參數順序不影響結果（排序而非固定順序）
		$this->assertSame( $signature, SfCrypto::signature( self::CIPHER_A, $token, $ts, $ts ) );
	}

	/**
	 * Happy：推送 sign = Base64(MD5(key + body + key))
	 *
	 * @group happy
	 */
	public function test_推送sign_golden(): void {
		$body = '[{"a":1}]';

		$this->assertSame( 'H58CQELiXLV+IZi9a1oHxg==', SfCrypto::push_sign( $body, 'k' ) );
		$this->assertTrue( SfCrypto::verify_push_sign( $body, 'k', 'H58CQELiXLV+IZi9a1oHxg==' ) );
	}

	/**
	 * Security：推送 sign 不符 / 金鑰空 / sign 空 → false（fail-closed）
	 *
	 * @group security
	 */
	public function test_推送sign不符一律false(): void {
		$body = '[{"a":1}]';

		$this->assertFalse( SfCrypto::verify_push_sign( $body . ' ', 'k', 'H58CQELiXLV+IZi9a1oHxg==' ) );
		$this->assertFalse( SfCrypto::verify_push_sign( $body, 'other', 'H58CQELiXLV+IZi9a1oHxg==' ) );
		$this->assertFalse( SfCrypto::verify_push_sign( $body, '', SfCrypto::push_sign( $body, '' ) ) );
		$this->assertFalse( SfCrypto::verify_push_sign( $body, 'k', '' ) );
	}

	/**
	 * Security：篡改密文 → 解密失敗例外（SIGNATURE），不回傳任何明文
	 *
	 * @group security
	 */
	public function test_篡改密文解密失敗(): void {
		// 竄改倒數第二個 block（CBC：該 block 明文整塊亂掉 → 落在 appKey 尾碼區，必被偵測）
		$raw           = (string) \base64_decode( self::CIPHER_A, true );
		$index         = \strlen( $raw ) - 20;
		$raw[ $index ] = \chr( \ord( $raw[ $index ] ) ^ 0x01 );
		$tampered      = \base64_encode( $raw );

		try {
			$this->demo()->decrypt( $tampered );
			$this->fail( '篡改密文應拋例外' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::SIGNATURE, $e->error_code );
		}
	}

	/**
	 * Security：appKey 不符（別人的密文）→ 例外
	 *
	 * @group security
	 */
	public function test_appKey不符解密失敗(): void {
		$this->expectException( SfExpressException::class );
		( new SfCrypto( 'another-app-key', self::DEMO_AES_KEY ) )->decrypt( self::CIPHER_A );
	}

	/**
	 * Security：非 base64 / 空字串 → 例外
	 *
	 * @group security
	 */
	public function test_非base64密文例外(): void {
		$this->expectException( SfExpressException::class );
		$this->demo()->decrypt( '%%%not-base64%%%' );
	}

	/**
	 * Error：encodingAesKey 長度不是 43 → VALIDATION
	 *
	 * @group error
	 */
	public function test_AES金鑰長度錯誤(): void {
		$this->assertFalse( SfCrypto::is_valid_aes_key( 'short' ) );
		$this->assertTrue( SfCrypto::is_valid_aes_key( self::DEMO_AES_KEY ) );

		try {
			new SfCrypto( self::DEMO_APP_KEY, 'short' );
			$this->fail( '金鑰長度錯誤應拋例外' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
			$this->assertStringNotContainsString( 'short', $e->getMessage() );
		}
	}
}
