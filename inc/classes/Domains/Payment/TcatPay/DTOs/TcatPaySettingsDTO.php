<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\DTOs;

use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums\TcatPayMethod;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayAmountLimits;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * 黑貓PAY（tcat_pay）設定，對應 woocommerce_tcat_pay_settings
 *
 * - 不繼承 wp-utils DTO（R16：其必填驗證從未生效）；create() 顯式驗證並拋 \InvalidArgumentException。
 * - mode 預設 test（R17）。測試 / 正式環境資料不互通，帳密需分別開通；平台**沒有**公開測試帳號，
 *   test 模式也不提供預設憑證。
 * - 子方式由 enabled_methods 決定；CVS 收單行組合由子方式固定（見 TcatPayMethod），不開放各自設定。
 *
 * @see .claude/skills/tcat-pay/SKILL.md §環境與 Endpoint 組成
 */
final class TcatPaySettingsDTO {

	/** @var string 測試環境 BaseUrl */
	public const SANDBOX_BASE = 'https://test.4128888card.com.tw/app';

	/** @var string 正式環境 BaseUrl */
	public const PROD_BASE = 'https://cocs.4128888card.com.tw';

	/** @var array<int, string> 合法收單銀行 */
	public const ACQUIRER_TYPES = [ 'esun', 'chinatrust', 'payuni' ];

	/** @var array<string, string> 字串欄位預設值 */
	private const STRING_DEFAULTS = [
		'enabled'           => 'no',
		'title'             => '黑貓PAY',
		'description'       => '使用黑貓PAY 信用卡、ibon 超商代碼或 ATM 虛擬帳號付款',
		'order_button_text' => '前往付款',
		'mode'              => 'test',
		'cust_id'           => '',
		'api_password'      => '',
		'hash_base'         => '',
		'acquirer_type'     => 'payuni',
		'limit_product_id'  => '',
	];

	/** @var array<int, string> 允許為空字串的欄位（其餘空字串退回預設） */
	private const NULLABLE_STRINGS = [ 'cust_id', 'api_password', 'hash_base', 'limit_product_id' ];

	/**
	 * Constructor
	 *
	 * @param string             $enabled           是否啟用 yes|no
	 * @param string             $title             前台標題
	 * @param string             $description       前台描述
	 * @param string             $order_button_text 結帳按鈕文字
	 * @param string             $mode              test|prod
	 * @param string             $cust_id           客戶金流代號（= Token username = cust_id）
	 * @param string             $api_password      API 密碼
	 * @param string             $hash_base         COCS 導轉 chk 密鑰
	 * @param string             $acquirer_type     COCS 收單銀行 esun|chinatrust|payuni
	 * @param string             $limit_product_id  COCS 限定產品別（空 = 不限）
	 * @param array<int, string> $enabled_methods   啟用的子方式
	 * @param int                $expire_days       CVS / ATM 繳費天數（1~30）
	 * @param int                $min_amount        最小金額（0 不限）
	 * @param int                $max_amount        最大金額（0 不限）
	 * @param array<string, int> $max_amounts       子方式上限覆寫（method => 元；未設定不含該鍵）
	 */
	private function __construct(
		public readonly string $enabled,
		public readonly string $title,
		public readonly string $description,
		public readonly string $order_button_text,
		public readonly string $mode,
		public readonly string $cust_id,
		public readonly string $api_password,
		public readonly string $hash_base,
		public readonly string $acquirer_type,
		public readonly string $limit_product_id,
		public readonly array $enabled_methods,
		public readonly int $expire_days,
		public readonly int $min_amount,
		public readonly int $max_amount,
		public readonly array $max_amounts,
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
			throw new \InvalidArgumentException( 'TcatPaySettingsDTO：enabled 必須為 yes 或 no' );
		}
		if ( ! \in_array( $s['mode'], [ 'test', 'prod' ], true ) ) {
			throw new \InvalidArgumentException( 'TcatPaySettingsDTO：mode 必須為 test 或 prod' );
		}
		if ( ! \in_array( $s['acquirer_type'], self::ACQUIRER_TYPES, true ) ) {
			throw new \InvalidArgumentException( 'TcatPaySettingsDTO：acquirer_type 不在允許清單' );
		}

		$max_amounts = [];
		foreach ( TcatPayMethod::cases() as $method ) {
			$parsed = TcatPayAmountLimits::parse( $data[ $method->max_amount_setting_key() ] ?? null );
			if ( null !== $parsed ) {
				$max_amounts[ $method->value ] = $parsed;
			}
		}

		return new self(
			enabled: $s['enabled'],
			title: $s['title'],
			description: $s['description'],
			order_button_text: $s['order_button_text'],
			mode: $s['mode'],
			cust_id: $s['cust_id'],
			api_password: $s['api_password'],
			hash_base: $s['hash_base'],
			acquirer_type: $s['acquirer_type'],
			limit_product_id: $s['limit_product_id'],
			enabled_methods: self::parse_methods( $data['enabled_methods'] ?? null ),
			expire_days: self::parse_int( $data, 'expire_days', 3, 1, 30 ),
			min_amount: self::parse_int( $data, 'min_amount', 0, 0, TcatPayAmountLimits::UPPER_BOUND ),
			max_amount: self::parse_int( $data, 'max_amount', 0, 0, TcatPayAmountLimits::UPPER_BOUND ),
			max_amounts: $max_amounts,
		);
	}

	/**
	 * 從 WC option 取得設定；option 毀損不拋錯（gateway 建構期呼叫），記 log 後回預設值
	 *
	 * @return self
	 */
	public static function instance(): self {
		$option = ProviderUtils::get_option( 'tcat_pay' );
		try {
			/** @var array<string, mixed> $data */
			$data = \is_array( $option ) ? $option : [];
			return self::create( $data );
		} catch ( \InvalidArgumentException $e ) {
			Plugin::logger( '黑貓PAY 設定毀損，改用預設值', 'warning', [ 'error' => $e->getMessage() ] );
			return self::create( [] );
		}
	}

	/** @return string 依 mode 的 BaseUrl（無尾斜線） */
	public function get_api_base(): string {
		return 'prod' === $this->mode ? self::PROD_BASE : self::SANDBOX_BASE;
	}

	/** @return bool 憑證是否齊全（cust_id + api_password） */
	public function has_credentials(): bool {
		return '' !== $this->cust_id && '' !== $this->api_password;
	}

	/** @return array<string, mixed> 轉陣列（admin 設定頁讀寫；與 create() 往返一致） */
	public function to_array(): array {
		$data = [
			'enabled'           => $this->enabled,
			'title'             => $this->title,
			'description'       => $this->description,
			'order_button_text' => $this->order_button_text,
			'mode'              => $this->mode,
			'cust_id'           => $this->cust_id,
			'api_password'      => $this->api_password,
			'hash_base'         => $this->hash_base,
			'acquirer_type'     => $this->acquirer_type,
			'limit_product_id'  => $this->limit_product_id,
			'enabled_methods'   => $this->enabled_methods,
			'expire_days'       => $this->expire_days,
			'min_amount'        => $this->min_amount,
			'max_amount'        => $this->max_amount,
		];
		foreach ( TcatPayMethod::cases() as $method ) {
			$data[ $method->max_amount_setting_key() ] = $this->max_amounts[ $method->value ] ?? '';
		}
		return $data;
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
				throw new \InvalidArgumentException( "TcatPaySettingsDTO：{$key} 必須為字串" );
			}
			$value          = StrHelper::trim_invisible( $raw );
			$values[ $key ] = '' === $value && ! \in_array( $key, self::NULLABLE_STRINGS, true ) ? $default : $value;
		}
		return $values;
	}

	/**
	 * 解析啟用子方式（缺值預設只開刷卡；空陣列 / 不合法值拋錯）
	 *
	 * @param mixed $raw 原始值
	 * @return array<int, string>
	 * @throws \InvalidArgumentException 不合法
	 */
	private static function parse_methods( mixed $raw ): array {
		if ( null === $raw ) {
			return [ TcatPayMethod::CREDIT->value ];
		}
		if ( ! \is_array( $raw ) || [] === $raw ) {
			throw new \InvalidArgumentException( 'TcatPaySettingsDTO：enabled_methods 至少需啟用一種子方式' );
		}

		$methods = [];
		foreach ( $raw as $value ) {
			$method = \is_string( $value ) ? TcatPayMethod::tryFrom( \trim( $value ) ) : null;
			if ( null === $method ) {
				throw new \InvalidArgumentException( 'TcatPaySettingsDTO：enabled_methods 含不支援的子方式' );
			}
			$methods[] = $method->value;
		}
		return \array_values( \array_unique( $methods ) );
	}

	/**
	 * 解析整數欄位（數字字串可；超出範圍拋錯）
	 *
	 * @param array<string, mixed> $data    設定
	 * @param string               $key     欄位
	 * @param int                  $default 預設
	 * @param int                  $min     下限
	 * @param int                  $max     上限
	 * @return int
	 * @throws \InvalidArgumentException 非整數或超出範圍
	 */
	private static function parse_int( array $data, string $key, int $default, int $min, int $max ): int {
		$raw = $data[ $key ] ?? $default;
		if ( \is_string( $raw ) && '' === \trim( $raw ) ) {
			return $default;
		}
		if ( \is_string( $raw ) && 1 === \preg_match( '/^-?\d+$/', \trim( $raw ) ) ) {
			$raw = (int) \trim( $raw );
		}
		if ( ! \is_int( $raw ) || $raw < $min || $raw > $max ) {
			throw new \InvalidArgumentException( "TcatPaySettingsDTO：{$key} 必須為 {$min}~{$max} 的整數" );
		}
		return $raw;
	}
}
