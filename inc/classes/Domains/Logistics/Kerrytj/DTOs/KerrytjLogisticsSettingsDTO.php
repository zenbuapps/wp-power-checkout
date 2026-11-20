<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjTemperature;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjBln;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * 嘉里大榮（kerrytj_logistics）設定，對應 woocommerce_kerrytj_logistics_settings
 *
 * - 不繼承 wp-utils DTO（R16）；create() 顯式驗證並拋 \InvalidArgumentException。mode 預設 test（R17）。
 * - 測試 / 正式的帳密、客編、號段各自一套（規格書第 2 頁帳號表）；切換環境時須一併更換。
 * - 貨況主機 KerryTracingEDI 只有一組（無測試網址），與 EDIEntrance 共用帳密。
 * - 號段：常溫起訖 11 碼；低溫起訖 9 碼流水號或檢查碼正確的 10 碼（原值保存，range() 回流水號）；起訖皆空 = 未設定。
 * - 帳號 / 密碼只用於 Basic Auth header，永不寫入 log / order note。
 *
 * @see .claude/skills/kerrytj-logistics/SKILL.md
 */
final class KerrytjLogisticsSettingsDTO {

	/** @var string 測試環境 EDIEntrance base */
	public const TEST_EDI_BASE = 'https://api.kerrytj.com/EDIEntranceTest';

	/** @var string 正式環境 EDIEntrance base */
	public const PROD_EDI_BASE = 'https://api.kerrytj.com/EDIEntrance';

	/** @var string 貨況查詢 base（只有一組） */
	public const TRACING_BASE = 'https://KerryTracingEDI.kerrytj.com/api/Tracing';

	/** @var array<string, string> 欄位預設值（順序 = to_array 輸出順序） */
	private const DEFAULTS = [
		'enabled'               => 'no',
		'title'                 => '嘉里大榮',
		'description'           => '嘉里大榮物流宅配到府',
		'mode'                  => 'test',
		'username'              => '',
		'password'              => '',
		'customer_no'           => '',
		'sender_name'           => '',
		'sender_phone'          => '',
		'sender_postcode'       => '',
		'sender_address'        => '',
		'ambient_waybill_start' => '',
		'ambient_waybill_end'   => '',
		'cold_waybill_start'    => '',
		'cold_waybill_end'      => '',
		'default_temperature'   => '1',
	];

	/** @var array<int, string> 允許為空字串的欄位（其餘空字串退回預設） */
	private const NULLABLE = [
		'username',
		'password',
		'customer_no',
		'sender_name',
		'sender_phone',
		'sender_postcode',
		'sender_address',
		'ambient_waybill_start',
		'ambient_waybill_end',
		'cold_waybill_start',
		'cold_waybill_end',
	];

	/** @var self|null 單例 */
	private static ?self $instance = null;

	/**
	 * Constructor
	 *
	 * @param string $enabled               是否啟用 yes|no
	 * @param string $title                 顯示名稱
	 * @param string $description           描述
	 * @param string $mode                  test|prod
	 * @param string $username              API 帳號（Basic Auth）
	 * @param string $password              API 密碼（Basic Auth）
	 * @param string $customer_no           客戶編號（8 碼）
	 * @param string $sender_name           寄件人名稱（退貨收件人）
	 * @param string $sender_phone          寄件人電話
	 * @param string $sender_postcode       寄件人郵遞區號（取前 3 碼）
	 * @param string $sender_address        寄件人地址
	 * @param string $ambient_waybill_start 常溫號段起（11 碼）
	 * @param string $ambient_waybill_end   常溫號段迄（11 碼）
	 * @param string $cold_waybill_start    低溫號段起（9 碼流水號或 10 碼單號）
	 * @param string $cold_waybill_end      低溫號段迄（9 碼流水號或 10 碼單號）
	 * @param string $default_temperature   預設溫層（訂單未指定時）1 / 2 / 3
	 */
	private function __construct(
		public readonly string $enabled,
		public readonly string $title,
		public readonly string $description,
		public readonly string $mode,
		public readonly string $username,
		public readonly string $password,
		public readonly string $customer_no,
		public readonly string $sender_name,
		public readonly string $sender_phone,
		public readonly string $sender_postcode,
		public readonly string $sender_address,
		public readonly string $ambient_waybill_start,
		public readonly string $ambient_waybill_end,
		public readonly string $cold_waybill_start,
		public readonly string $cold_waybill_end,
		public readonly string $default_temperature,
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

		if (!\in_array( $s['enabled'], [ 'yes', 'no' ], true )) {
			throw new \InvalidArgumentException( 'KerrytjLogisticsSettingsDTO：enabled 值不正確' );
		}
		if (!\in_array( $s['mode'], [ 'test', 'prod' ], true )) {
			throw new \InvalidArgumentException( 'KerrytjLogisticsSettingsDTO：mode 須為 test / prod' );
		}
		if (null === KerrytjTemperature::tryFrom( $s['default_temperature'] )) {
			throw new \InvalidArgumentException( 'KerrytjLogisticsSettingsDTO：預設溫層須為 1 / 2 / 3' );
		}
		if ('' !== $s['customer_no'] && 1 !== \preg_match( '/^\d{8}$/', $s['customer_no'] )) {
			throw new \InvalidArgumentException( 'KerrytjLogisticsSettingsDTO：客戶編號須為 8 碼數字' );
		}
		if ('' !== $s['sender_postcode'] && 1 !== \preg_match( '/^\d{3,6}$/', $s['sender_postcode'] )) {
			throw new \InvalidArgumentException( 'KerrytjLogisticsSettingsDTO：寄件人郵遞區號須為 3~6 碼數字' );
		}
		self::assert_range( KerrytjBlnKind::AMBIENT, $s['ambient_waybill_start'], $s['ambient_waybill_end'] );
		self::assert_range( KerrytjBlnKind::COLD, $s['cold_waybill_start'], $s['cold_waybill_end'] );

		return new self( ...$s );
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
		$option = ProviderUtils::get_option( 'kerrytj_logistics' );
		try {
			/** @var array<string, mixed> $data */
			$data           = \is_array( $option ) ? $option : [];
			self::$instance = self::create( $data );
		} catch ( \InvalidArgumentException $e ) {
			Plugin::logger( '嘉里大榮設定毀損，改用預設值', 'warning', [ 'error' => $e->getMessage() ] );
			self::$instance = self::create( [] );
		}
		return self::$instance;
	}

	/** @return void 重置單例（設定變更 / 測試用） */
	public static function reset(): void {
		self::$instance = null;
	}

	/** @return string 依 mode 的 EDIEntrance base（無尾斜線） */
	public function edi_base(): string {
		return 'prod' === $this->mode ? self::PROD_EDI_BASE : self::TEST_EDI_BASE;
	}

	/** @return bool 帳號與密碼是否齊全 */
	public function has_credentials(): bool {
		return '' !== $this->username && '' !== $this->password;
	}

	/** @return array<int, string> 缺漏的寄件人設定欄位名稱（不回值） */
	public function missing_sender_fields(): array {
		$missing = [];
		foreach ( [
			'sender_name'     => $this->sender_name,
			'sender_phone'    => $this->sender_phone,
			'sender_postcode' => $this->sender_postcode,
			'sender_address'  => $this->sender_address,
		] as $key => $value ) {
			if ('' === $value) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/**
	 * 某種類號段（流水號本體）
	 *
	 * @param KerrytjBlnKind $kind 種類
	 * @return array{start: int, end: int}|null 未設定回 null
	 */
	public function range( KerrytjBlnKind $kind ): ?array {
		[ $start, $end ] = KerrytjBlnKind::AMBIENT === $kind
		? [ $this->ambient_waybill_start, $this->ambient_waybill_end ]
		: [ $this->cold_waybill_start, $this->cold_waybill_end ];
		$start_serial    = KerrytjBln::parse_range_bound( $kind, $start );
		$end_serial      = KerrytjBln::parse_range_bound( $kind, $end );
		if (null === $start_serial || null === $end_serial) {
			return null;
		}
		return [
			'start' => $start_serial,
			'end'   => $end_serial,
		];
	}

	/** @return array<string, string> 轉陣列（admin 設定頁讀寫；與 create() 往返一致） */
	public function to_array(): array {
		$values = [];
		foreach ( \array_keys( self::DEFAULTS ) as $key ) {
			$values[ $key ] = $this->{$key};
		}
		return $values;
	}

	/**
	 * 號段驗證：起訖皆空，或皆合法且起 ≤ 迄
	 *
	 * @param KerrytjBlnKind $kind  種類
	 * @param string         $start 起
	 * @param string         $end   迄
	 * @return void
	 * @throws \InvalidArgumentException 不合法
	 */
	private static function assert_range( KerrytjBlnKind $kind, string $start, string $end ): void {
		if ('' === $start && '' === $end) {
			return;
		}
		$start_serial = KerrytjBln::parse_range_bound( $kind, $start );
		$end_serial   = KerrytjBln::parse_range_bound( $kind, $end );
		if (null === $start_serial || null === $end_serial) {
			throw new \InvalidArgumentException(
				KerrytjBlnKind::AMBIENT === $kind
				? 'KerrytjLogisticsSettingsDTO：常溫號段起訖須同時填寫 11 碼數字'
				: 'KerrytjLogisticsSettingsDTO：低溫號段起訖須同時填寫 9 碼流水號或檢查碼正確的 10 碼單號'
			);
		}
		if ($start_serial < 1 || $start_serial > $end_serial) {
			throw new \InvalidArgumentException( "KerrytjLogisticsSettingsDTO：{$kind->label()}號段起號須 ≥ 1 且 ≤ 迄號" );
		}
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
				throw new \InvalidArgumentException( "KerrytjLogisticsSettingsDTO：{$key} 必須為字串" );
			}
			$value          = StrHelper::trim_invisible( $raw );
			$values[ $key ] = '' === $value && !\in_array( $key, self::NULLABLE, true ) ? $default : $value;
		}
		return $values;
	}
}
