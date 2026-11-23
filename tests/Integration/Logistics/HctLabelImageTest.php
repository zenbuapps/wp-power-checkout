<?php
/**
 * 新竹物流標籤圖（TransData image hex）測試（P5）
 *
 * TransData 回傳 `image` = hex 編碼的完整標籤圖（zenbu 對 DEV 實打：解 hex 後 magic 為 PNG `89504E47`，1-bit 調色盤；
 * 官方 C# 範例以 Bitmap 類別載入，故同時容錯 BMP `BM`）。不經 GD 轉檔，原樣以 data URL 輸出。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter HctLabelImage --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctLabelImage;
use Tests\Integration\Logistics\Doubles\HctLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 新竹物流標籤圖
 *
 * @group integration
 * @group logistics
 * @group hct_logistics
 */
final class HctLabelImageTest extends TestCase {
	use HctLogisticsTestTrait;

	/**
	 * Happy：PNG hex → mime / 尺寸 / data URL
	 *
	 * @group happy
	 */
	public function test_PNG_hex還原(): void {
		$label = HctLabelImage::from_hex( $this->hct_label_hex() );

		$this->assertSame( 'image/png', $label->mime );
		$this->assertSame( 24, $label->width );
		$this->assertSame( 12, $label->height );
		$this->assertStringStartsWith( 'data:image/png;base64,', $label->to_data_url() );
		$this->assertSame( (string) \file_get_contents( __DIR__ . '/Fixtures/hct_logistics/label.png' ), \base64_decode( $label->to_base64() ) );
	}

	/**
	 * Edge：hex 含空白 / 小寫也可還原
	 *
	 * @group edge
	 */
	public function test_hex含空白與小寫(): void {
		$hex = \chunk_split( \strtolower( $this->hct_label_hex() ), 64, "\n" );

		$this->assertSame( 24, HctLabelImage::from_hex( $hex )->width );
	}

	/**
	 * Edge：BMP（官方 C# Bitmap 範例）也可辨識尺寸
	 *
	 * @group edge
	 */
	public function test_BMP辨識(): void {
		$bmp = 'BM' . \pack( 'V', 70 ) . \pack( 'v', 0 ) . \pack( 'v', 0 ) . \pack( 'V', 62 ) . \pack( 'V', 40 ) . \pack( 'V', 8 ) . \pack( 'V', 2 ) . \str_repeat( "\0", 40 );

		$label = HctLabelImage::from_hex( \bin2hex( $bmp ) );

		$this->assertSame( 'image/bmp', $label->mime );
		$this->assertSame( 8, $label->width );
		$this->assertSame( 2, $label->height );
	}

	/**
	 * Happy：由 base64（order meta 儲存值）還原
	 *
	 * @group happy
	 */
	public function test_由base64還原(): void {
		$label = HctLabelImage::from_hex( $this->hct_label_hex() );

		$restored = HctLabelImage::from_base64( $label->to_base64() );

		$this->assertSame( 'image/png', $restored->mime );
		$this->assertSame( 12, $restored->height );
	}

	/**
	 * Error：奇數長度 / 非 hex / 未知格式 → 例外
	 *
	 * @group error
	 */
	public function test_非法hex(): void {
		foreach ( [ 'ABC', 'ZZZZ', \bin2hex( 'GIF89a-not-supported' ), '' ] as $hex ) {
			try {
				HctLabelImage::from_hex( $hex );
				$this->fail( "預期 {$hex} 拋例外" );
			} catch ( HctLogisticsException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}
