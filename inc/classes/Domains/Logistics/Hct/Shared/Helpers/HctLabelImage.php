<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 新竹物流標籤圖（TransData `image` 欄位）
 *
 * `image` = 完整標籤圖檔案位元組的 hex 字串（約 2 萬字元）。zenbu 對 DEV 實打解出 PNG（`89504E47`，1-bit 調色盤）；
 * 官方 C# 範例以 Bitmap.FromStream 載入（可吃 PNG / BMP），故兩種容器都辨識。原樣輸出 data URL，不經 GD 轉檔。
 *
 * @see .claude/skills/hct-logistics/references/code-examples.md §4、§11
 */
final class HctLabelImage {

	/**
	 * Constructor
	 *
	 * @param string $bytes  圖檔位元組
	 * @param string $mime   image/png | image/bmp
	 * @param int    $width  寬（px）
	 * @param int    $height 高（px）
	 */
	private function __construct(
		public readonly string $bytes,
		public readonly string $mime,
		public readonly int $width,
		public readonly int $height,
	) {
	}

	/**
	 * 由 TransData image hex 還原
	 *
	 * @param string $hex hex 字串（可含空白 / 大小寫）
	 * @return self
	 * @throws HctLogisticsException Hex 不合法或不是 PNG / BMP
	 */
	public static function from_hex( string $hex ): self {
		$clean = (string) \preg_replace( '/\s+/', '', $hex );
		if ('' === $clean || 0 !== \strlen( $clean ) % 2 || !\ctype_xdigit( $clean )) {
			throw new HctLogisticsException( '新竹物流標籤圖格式錯誤（不是合法的 hex 字串）', ErrorCode::UNKNOWN );
		}
		return self::from_bytes( (string) \hex2bin( $clean ) );
	}

	/**
	 * 由 base64（order meta 儲存值）還原
	 *
	 * @param string $base64 Base64
	 * @return self
	 * @throws HctLogisticsException 不合法
	 */
	public static function from_base64( string $base64 ): self {
		$bytes = \base64_decode( $base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 讀回自存的標籤圖
		if (false === $bytes || '' === $bytes) {
			throw new HctLogisticsException( '新竹物流標籤圖資料毀損', ErrorCode::UNKNOWN );
		}
		return self::from_bytes( $bytes );
	}

	/** @return string Base64（存 order meta 用） */
	public function to_base64(): string {
		return \base64_encode( $this->bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 圖檔以 data URL 輸出
	}

	/** @return string data URL */
	public function to_data_url(): string {
		return "data:{$this->mime};base64," . $this->to_base64();
	}

	/**
	 * 依 magic bytes 辨識 PNG / BMP 並取尺寸
	 *
	 * @param string $bytes 圖檔位元組
	 * @return self
	 * @throws HctLogisticsException 未知格式
	 */
	private static function from_bytes( string $bytes ): self {
		if (\strlen( $bytes ) >= 24 && "\x89PNG\r\n\x1a\n" === \substr( $bytes, 0, 8 )) {
			/** @var array{w: int, h: int}|false $size */
			$size = \unpack( 'Nw/Nh', \substr( $bytes, 16, 8 ) );
			if (\is_array( $size )) {
				return new self( $bytes, 'image/png', $size['w'], $size['h'] );
			}
		}
		if (\strlen( $bytes ) >= 26 && 'BM' === \substr( $bytes, 0, 2 )) {
			/** @var array{w: int, h: int}|false $size */
			$size = \unpack( 'Vw/Vh', \substr( $bytes, 18, 8 ) );
			if (\is_array( $size )) {
				// BMP 高度可為負（top-down）：以 32-bit 有號數解讀後取絕對值
				$height = $size['h'] >= 0x80000000 ? $size['h'] - 0x100000000 : $size['h'];
				return new self( $bytes, 'image/bmp', $size['w'], \abs( $height ) );
			}
		}
		throw new HctLogisticsException( '新竹物流標籤圖不是 PNG / BMP 格式', ErrorCode::UNKNOWN );
	}
}
