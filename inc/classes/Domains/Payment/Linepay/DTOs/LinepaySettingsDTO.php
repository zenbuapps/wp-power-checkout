<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\DTOs;

use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * LINE Pay 直連（linepay）設定，對應 woocommerce_linepay_settings
 *
 * - 不繼承 wp-utils DTO（R16：其必填驗證從未生效）；create() 顯式驗證並拋 \InvalidArgumentException。
 * - mode 預設 test（R17）。官方**沒有**公開測試 Channel；sandbox 憑證需自助申請，
 *   sandbox 與正式憑證不可混用（混用回 1104）。
 * - Channel Secret 只用於簽章，永不送出、永不寫入 log / order note。
 *
 * @see .claude/skills/linepay/SKILL.md §環境與 Host、§測試憑證
 */
final class LinepaySettingsDTO {

	/** @var string Sandbox API host */
	public const SANDBOX_BASE = 'https://sandbox-api-pay.line.me';

	/** @var string 正式 API host */
	public const PROD_BASE = 'https://api-pay.line.me';

	/** @var int 金額上限（驗證用） */
	private const AMOUNT_UPPER_BOUND = 100000000;

	/** @var array<string, string> 字串欄位預設值 */
	private const STRING_DEFAULTS = [
		'enabled'           => 'no',
		'title'             => 'LINE Pay',
		'description'       => '使用 LINE Pay 付款（將導向 LINE Pay 付款頁）',
		'order_button_text' => '前往 LINE Pay 付款',
		'mode'              => 'test',
		'channel_id'        => '',
		'channel_secret'    => '',
	];

	/** @var array<int, string> 允許為空字串的欄位（其餘空字串退回預設） */
	private const NULLABLE_STRINGS = [ 'channel_id', 'channel_secret' ];

	/**
	 * Constructor
	 *
	 * @param string $enabled           是否啟用 yes|no
	 * @param string $title             前台標題
	 * @param string $description       前台描述
	 * @param string $order_button_text 結帳按鈕文字
	 * @param string $mode              test|prod
	 * @param string $channel_id        Channel ID（數字）
	 * @param string $channel_secret    Channel Secret
	 * @param int    $min_amount        最小金額（0 不限）
	 * @param int    $max_amount        最大金額（0 不限）
	 */
	private function __construct(
		public readonly string $enabled,
		public readonly string $title,
		public readonly string $description,
		public readonly string $order_button_text,
		public readonly string $mode,
		public readonly string $channel_id,
		public readonly string $channel_secret,
		public readonly int $min_amount,
		public readonly int $max_amount,
	) {
	}

	/**
	 * 由陣列建立（嚴格驗證；未知鍵忽略、缺鍵取預設）
	 *
	 * @param array<string, mixed> $data 設定資料
	 * @return self
	 * @throws \InvalidArgumentException 欄位型別或值不合法
	 */
	public static function create( array $data ): self {
		$s = self::parse_strings( $data );

		if ( ! \in_array( $s['enabled'], [ 'yes', 'no' ], true ) ) {
			throw new \InvalidArgumentException( 'LinepaySettingsDTO：enabled 必須為 yes 或 no' );
		}
		if ( ! \in_array( $s['mode'], [ 'test', 'prod' ], true ) ) {
			throw new \InvalidArgumentException( 'LinepaySettingsDTO：mode 必須為 test 或 prod' );
		}
		if ( '' !== $s['channel_id'] && 1 !== \preg_match( '/^\d+$/', $s['channel_id'] ) ) {
			throw new \InvalidArgumentException( 'LinepaySettingsDTO：channel_id 只能為數字' );
		}

		return new self(
			enabled: $s['enabled'],
			title: $s['title'],
			description: $s['description'],
			order_button_text: $s['order_button_text'],
			mode: $s['mode'],
			channel_id: $s['channel_id'],
			channel_secret: $s['channel_secret'],
			min_amount: self::parse_int( $data, 'min_amount' ),
			max_amount: self::parse_int( $data, 'max_amount' ),
		);
	}

	/**
	 * 從 WC option 取得設定；option 毀損不拋錯（gateway 建構期呼叫），記 log 後回預設值
	 *
	 * @return self
	 */
	public static function instance(): self {
		$option = ProviderUtils::get_option( 'linepay' );
		try {
			/** @var array<string, mixed> $data */
			$data = \is_array( $option ) ? $option : [];
			return self::create( $data );
		} catch ( \InvalidArgumentException $e ) {
			Plugin::logger( 'LINE Pay 設定毀損，改用預設值', 'warning', [ 'error' => $e->getMessage() ] );
			return self::create( [] );
		}
	}

	/** @return string 依 mode 的 API host（無尾斜線） */
	public function get_api_base(): string {
		return 'prod' === $this->mode ? self::PROD_BASE : self::SANDBOX_BASE;
	}

	/** @return bool 憑證是否齊全（Channel ID + Channel Secret） */
	public function has_credentials(): bool {
		return '' !== $this->channel_id && '' !== $this->channel_secret;
	}

	/** @return array<string, mixed> 轉陣列（admin 設定頁讀寫；與 create() 往返一致） */
	public function to_array(): array {
		return [
			'enabled'           => $this->enabled,
			'title'             => $this->title,
			'description'       => $this->description,
			'order_button_text' => $this->order_button_text,
			'mode'              => $this->mode,
			'channel_id'        => $this->channel_id,
			'channel_secret'    => $this->channel_secret,
			'min_amount'        => $this->min_amount,
			'max_amount'        => $this->max_amount,
		];
	}

	/**
	 * 解析字串欄位（去不可見字元；非字串 / 非整數拋錯）
	 *
	 * @param array<string, mixed> $data 設定
	 * @return array<string, string>
	 * @throws \InvalidArgumentException 型別錯誤
	 */
	private static function parse_strings( array $data ): array {
		$values = [];
		foreach ( self::STRING_DEFAULTS as $key => $default ) {
			$raw = $data[ $key ] ?? $default;
			if ( \is_int( $raw ) ) {
				$raw = (string) $raw;
			}
			if ( ! \is_string( $raw ) ) {
				throw new \InvalidArgumentException( "LinepaySettingsDTO：{$key} 必須為字串" );
			}
			$value          = StrHelper::trim_invisible( $raw );
			$values[ $key ] = '' === $value && ! \in_array( $key, self::NULLABLE_STRINGS, true ) ? $default : $value;
		}
		return $values;
	}

	/**
	 * 解析金額欄位（數字字串可；空白為 0；超出範圍拋錯）
	 *
	 * @param array<string, mixed> $data 設定
	 * @param string               $key  欄位
	 * @return int
	 * @throws \InvalidArgumentException 非整數或超出範圍
	 */
	private static function parse_int( array $data, string $key ): int {
		$raw = $data[ $key ] ?? 0;
		if ( \is_string( $raw ) ) {
			$raw = \trim( $raw );
			if ( '' === $raw ) {
				return 0;
			}
			if ( 1 === \preg_match( '/^\d+$/', $raw ) ) {
				$raw = (int) $raw;
			}
		}
		if ( ! \is_int( $raw ) || $raw < 0 || $raw > self::AMOUNT_UPPER_BOUND ) {
			throw new \InvalidArgumentException( "LinepaySettingsDTO：{$key} 必須為 0~" . self::AMOUNT_UPPER_BOUND . ' 的整數' );
		}
		return $raw;
	}
}
