<?php
/**
 * 新竹物流貨況查詢（searchGoods）DES-CBC 加解密測試（P5）
 *
 * - 規格：DES-CBC + PKCS7 + Base64；KEY = 查詢當日 ± n 天的 yyyyMMdd（UTF-8 8 bytes）；IV = 8 位固定值
 * - 實作以 `des-ede3-cbc` + 三重相同金鑰（K,K,K）等價單組 DES，避開 OpenSSL 3 legacy provider
 * - test vector：skill cargo-tracking-decrypt.md §5（官方 Ver3.1 文件範例：2025-03-13 + 235 天 = 20251103、IV TCAVZYKU）
 * - 容器 OpenSSL 若不提供 des-ede3-cbc → markTestSkipped（藍圖 §6.5；設定頁亦會警示）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter HctTrackCrypto --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctTrackCrypto;
use Tests\Integration\TestCase;

/**
 * 新竹物流貨況加解密
 *
 * @group integration
 * @group logistics
 * @group hct_logistics
 */
final class HctTrackCryptoTest extends TestCase {

	/** @var string 官方範例金鑰日期 */
	private const KEY_DATE = '20251103';

	/** @var string 官方範例 IV */
	private const IV = 'TCAVZYKU';

	/** 不支援的環境跳過 */
	public function set_up(): void {
		parent::set_up();
		if ( ! HctTrackCrypto::is_available() ) {
			$this->markTestSkipped( '此環境 OpenSSL 不提供 des-ede3-cbc，新竹貨況查詢不可用（設定頁會顯示警示）' );
		}
	}

	/**
	 * Happy：單筆 test vector
	 *
	 * @group happy
	 */
	public function test_單筆向量(): void {
		$this->assertSame( 'W/19ch1m+WBi9kMnQo7Hog==', HctTrackCrypto::encrypt( '1234567890', self::KEY_DATE, self::IV ) );
	}

	/**
	 * Happy：多筆 XML test vector
	 *
	 * @group happy
	 */
	public function test_多筆XML向量(): void {
		$plain = '<?xml version="1.0" encoding="utf-8"?><qrylist><order orderid="1234567890"></order></qrylist>';

		$this->assertSame(
			'U0Yhj0joZ8rHf4C6RXHqhYUscbDg9KZKq/qeMC9+94l76fRrA8XLthCdYB+IeoR83ZBno3S9e1/GZFJnahkG/DSvH39qdvqURQcsxV31mun8EMvjE7f6z2uxTl5n974K',
			HctTrackCrypto::encrypt( $plain, self::KEY_DATE, self::IV )
		);
	}

	/**
	 * Happy：解密還原
	 *
	 * @group happy
	 */
	public function test_解密還原(): void {
		$this->assertSame( '1234567890', HctTrackCrypto::decrypt( 'W/19ch1m+WBi9kMnQo7Hog==', self::KEY_DATE, self::IV ) );
	}

	/**
	 * Happy：金鑰日期 = 查詢當日（台北時間）+ 偏移天數
	 *
	 * @group happy
	 */
	public function test_金鑰日期(): void {
		$now = new \DateTimeImmutable( '2025-03-13 10:00:00', new \DateTimeZone( 'Asia/Taipei' ) );

		$this->assertSame( '20251103', HctTrackCrypto::key_date( 235, $now ) );
		$this->assertSame( '20250208', HctTrackCrypto::key_date( -33, $now ) );
	}

	/**
	 * Edge：UTC 跨日——台北已是隔天時以台北日期為準
	 *
	 * @group edge
	 */
	public function test_金鑰日期以台北時間計(): void {
		$now = new \DateTimeImmutable( '2025-03-12 17:30:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '20250313', HctTrackCrypto::key_date( 0, $now ) );
	}

	/**
	 * Error：密文錯誤 / 金鑰長度不對 → 例外
	 *
	 * @group error
	 */
	public function test_解密失敗與參數錯誤(): void {
		try {
			HctTrackCrypto::decrypt( 'not-base64-cipher', self::KEY_DATE, self::IV );
			$this->fail( '預期解密失敗' );
		} catch ( HctLogisticsException $e ) {
			$this->assertStringContainsString( '解密', $e->getMessage() );
		}

		$this->expectException( HctLogisticsException::class );
		HctTrackCrypto::encrypt( '1234567890', self::KEY_DATE, 'SHORT' );
	}
}
