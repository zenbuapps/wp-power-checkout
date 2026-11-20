<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\DTOs;

use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Enums\TcatSpec;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Enums\TcatThermosphere;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatFieldRules;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * 黑貓宅急便直串（tcat_logistics）設定，對應 woocommerce_tcat_logistics_settings
 *
 * - 不繼承 wp-utils DTO（R16：其必填驗證從未生效）；create() 顯式驗證並拋 \InvalidArgumentException。
 * - mode 預設 test（R17）。測試 / 正式授權碼由契客專區分別發送，**沒有公開測試帳號**，兩種模式都必須填。
 * - CustomerToken 只放 API body，永不寫入 log / order note。
 *
 * @see .claude/skills/tcat-logistics/SKILL.md §服務端點速查
 */
final class TcatLogisticsSettingsDTO {

	/** @var string 測試站 API base */
	public const TEST_BASE = 'https://egs.suda.com.tw:8443/api/Egs';

	/** @var string 正式站-1 API base（正式站-2 關係未明，不自動分流） */
	public const PROD_BASE = 'https://api.suda.com.tw/api/Egs';

	/** @var array<string, string> 欄位預設值 */
	private const DEFAULTS = [
		'enabled'              => 'no',
		'title'                => '黑貓宅急便',
		'description'          => '統一速達黑貓宅急便宅配到府',
		'mode'                 => 'test',
		'customer_id'          => '',
		'customer_token'       => '',
		'sender_name'          => '',
		'sender_tel'           => '',
		'sender_mobile'        => '',
		'sender_address'       => '',
		'sender_zip_code'      => '',
		'print_obt_type'       => '01',
		'default_thermosphere' => '0001',
		'default_spec'         => '0001',
		'product_type_id'      => '0015',
		'product_name'         => '商品',
		'delivery_time'        => '04',
	];

	/** @var array<int, string> 允許為空字串的欄位（其餘空字串退回預設） */
	private const NULLABLE = [
		'customer_id',
		'customer_token',
		'sender_name',
		'sender_tel',
		'sender_mobile',
		'sender_address',
		'sender_zip_code',
	];

	/** @var array<int, string> PrintOBT 宅配 PrintOBTType（01 A4 二模 / 02 A4 三模 / 03 熱轉印） */
	public const PRINT_OBT_TYPES = [ '01', '02', '03' ];

	/** @var array<int, string> 希望配達時間（無 03） */
	public const DELIVERY_TIMES = [ '01', '02', '04' ];

	/** @var self|null 單例 */
	private static ?self $instance = null;

	/**
	 * Constructor
	 *
	 * @param string $enabled              是否啟用 yes|no
	 * @param string $title                顯示名稱
	 * @param string $description          描述
	 * @param string $mode                 test|prod
	 * @param string $customer_id          契約客戶代號（10 或 12 碼）
	 * @param string $customer_token       契約客戶授權碼
	 * @param string $sender_name          寄件人姓名
	 * @param string $sender_tel           寄件人電話
	 * @param string $sender_mobile        寄件人手機（09 開頭）
	 * @param string $sender_address       寄件人地址（集貨地址）
	 * @param string $sender_zip_code      寄件人六碼郵號（空 = 以 ParsingAddress 查詢）
	 * @param string $print_obt_type       託運單版型
	 * @param string $default_thermosphere 預設溫層
	 * @param string $default_spec         預設規格
	 * @param string $product_type_id      商品類別 0001~0015
	 * @param string $product_name         商品名稱（訂單商品名含禁用字串時的替代名）
	 * @param string $delivery_time        希望配達時段
	 */
	private function __construct(
		public readonly string $enabled,
		public readonly string $title,
		public readonly string $description,
		public readonly string $mode,
		public readonly string $customer_id,
		public readonly string $customer_token,
		public readonly string $sender_name,
		public readonly string $sender_tel,
		public readonly string $sender_mobile,
		public readonly string $sender_address,
		public readonly string $sender_zip_code,
		public readonly string $print_obt_type,
		public readonly string $default_thermosphere,
		public readonly string $default_spec,
		public readonly string $product_type_id,
		public readonly string $product_name,
		public readonly string $delivery_time,
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

		self::assert_in( 'enabled', $s['enabled'], [ 'yes', 'no' ] );
		self::assert_in( 'mode', $s['mode'], [ 'test', 'prod' ] );
		self::assert_in( 'print_obt_type', $s['print_obt_type'], self::PRINT_OBT_TYPES );
		self::assert_in( 'delivery_time', $s['delivery_time'], self::DELIVERY_TIMES );

		if ('' !== $s['customer_id'] && 1 !== \preg_match( '/^(?:[0-9A-Za-z]{10}|[0-9A-Za-z]{12})$/', $s['customer_id'] )) {
			throw new \InvalidArgumentException( 'TcatLogisticsSettingsDTO：契客代號須為 10 或 12 碼' );
		}
		$thermosphere = TcatThermosphere::tryFrom( $s['default_thermosphere'] );
		$spec         = TcatSpec::tryFrom( $s['default_spec'] );
		if (null === $thermosphere || null === $spec) {
			throw new \InvalidArgumentException( 'TcatLogisticsSettingsDTO：溫層或規格代碼不正確' );
		}
		if ($thermosphere->is_cold() && TcatSpec::CM150 === $spec) {
			throw new \InvalidArgumentException( 'TcatLogisticsSettingsDTO：低溫包裹不可為 150cm' );
		}
		if (1 !== \preg_match( '/^00(?:0[1-9]|1[0-5])$/', $s['product_type_id'] )) {
			throw new \InvalidArgumentException( 'TcatLogisticsSettingsDTO：商品類別須為 0001~0015' );
		}
		if ('' !== $s['sender_mobile'] && 1 !== \preg_match( '/^09\d{8}$/', $s['sender_mobile'] )) {
			throw new \InvalidArgumentException( 'TcatLogisticsSettingsDTO：寄件人手機須為 09 開頭 10 碼' );
		}
		if (TcatFieldRules::has_forbidden_product_word( $s['product_name'] ) || \mb_strlen( $s['product_name'] ) > TcatFieldRules::PRODUCT_NAME_MAX) {
			throw new \InvalidArgumentException( 'TcatLogisticsSettingsDTO：商品名稱含黑貓禁用字串或超過 20 字' );
		}

		$zip = '';
		if ('' !== $s['sender_zip_code']) {
			$zip = TcatFieldRules::to_six_digit_zip( $s['sender_zip_code'] );
			if ('' === $zip) {
				throw new \InvalidArgumentException( 'TcatLogisticsSettingsDTO：寄件人郵號須可取出六碼（如 71-802-24-B → 80224B）' );
			}
		}

		return new self(
			enabled: $s['enabled'],
			title: $s['title'],
			description: $s['description'],
			mode: $s['mode'],
			customer_id: $s['customer_id'],
			customer_token: $s['customer_token'],
			sender_name: $s['sender_name'],
			sender_tel: $s['sender_tel'],
			sender_mobile: $s['sender_mobile'],
			sender_address: $s['sender_address'],
			sender_zip_code: $zip,
			print_obt_type: $s['print_obt_type'],
			default_thermosphere: $s['default_thermosphere'],
			default_spec: $s['default_spec'],
			product_type_id: $s['product_type_id'],
			product_name: $s['product_name'],
			delivery_time: $s['delivery_time'],
		);
	}

	/**
	 * 從 WC option 取得設定（單例）；option 毀損不拋錯，記 log 後回預設值
	 *
	 * @return self
	 */
	public static function instance(): self {
		if (null !== self::$instance) {
			return self::$instance;
		}
		$option = ProviderUtils::get_option( 'tcat_logistics' );
		try {
			/** @var array<string, mixed> $data */
			$data           = \is_array( $option ) ? $option : [];
			self::$instance = self::create( $data );
		} catch ( \InvalidArgumentException $e ) {
			Plugin::logger( '黑貓宅急便設定毀損，改用預設值', 'warning', [ 'error' => $e->getMessage() ] );
			self::$instance = self::create( [] );
		}
		return self::$instance;
	}

	/** @return void 重置單例（設定變更 / 測試用） */
	public static function reset(): void {
		self::$instance = null;
	}

	/** @return string 依 mode 的 API base（無尾斜線） */
	public function api_base(): string {
		return 'prod' === $this->mode ? self::PROD_BASE : self::TEST_BASE;
	}

	/** @return bool 憑證是否齊全 */
	public function has_credentials(): bool {
		return '' !== $this->customer_id && '' !== $this->customer_token;
	}

	/** @return array<string, string> 轉陣列（admin 設定頁讀寫；與 create() 往返一致） */
	public function to_array(): array {
		return [
			'enabled'              => $this->enabled,
			'title'                => $this->title,
			'description'          => $this->description,
			'mode'                 => $this->mode,
			'customer_id'          => $this->customer_id,
			'customer_token'       => $this->customer_token,
			'sender_name'          => $this->sender_name,
			'sender_tel'           => $this->sender_tel,
			'sender_mobile'        => $this->sender_mobile,
			'sender_address'       => $this->sender_address,
			'sender_zip_code'      => $this->sender_zip_code,
			'print_obt_type'       => $this->print_obt_type,
			'default_thermosphere' => $this->default_thermosphere,
			'default_spec'         => $this->default_spec,
			'product_type_id'      => $this->product_type_id,
			'product_name'         => $this->product_name,
			'delivery_time'        => $this->delivery_time,
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
		foreach ( self::DEFAULTS as $key => $default ) {
			$raw = $data[ $key ] ?? $default;
			if (\is_int( $raw )) {
				$raw = (string) $raw;
			}
			if (!\is_string( $raw )) {
				throw new \InvalidArgumentException( "TcatLogisticsSettingsDTO：{$key} 必須為字串" );
			}
			$value          = StrHelper::trim_invisible( $raw );
			$values[ $key ] = '' === $value && !\in_array( $key, self::NULLABLE, true ) ? $default : $value;
		}
		return $values;
	}

	/**
	 * 值域檢查
	 *
	 * @param string             $key     欄位
	 * @param string             $value   值
	 * @param array<int, string> $allowed 允許值
	 * @return void
	 * @throws \InvalidArgumentException 不在值域
	 */
	private static function assert_in( string $key, string $value, array $allowed ): void {
		if (!\in_array( $value, $allowed, true )) {
			throw new \InvalidArgumentException( "TcatLogisticsSettingsDTO：{$key} 值不正確" );
		}
	}
}
