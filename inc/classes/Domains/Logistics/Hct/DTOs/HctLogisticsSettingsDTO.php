<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\DTOs;

use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Enums\HctProductType;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * 新竹物流（hct_logistics）設定，對應 woocommerce_hct_logistics_settings
 *
 * - 不繼承 wp-utils DTO（R16：其必填驗證從未生效）；create() 顯式驗證並拋 \InvalidArgumentException。
 * - mode 預設 test（R17）。⚠️ 2026-11-21 實測：官方文件的測試帳號（test / test1）在 DEV 端點回
 *   「「測試環境」請勿使用正式公司名稱或密碼」、在 PROD 端點可登入 → 它其實是正式系統帳號，DEV 需另向新竹申請。
 * - 號段（waybill_start / waybill_end）為 9 碼本體（不含檢查碼）；起訖皆空 = 由新竹系統配號。
 * - 貨況查詢參數（金鑰日期偏移 / 8 位 IV / 32 位 v）為申請查貨服務後新竹提供的帳號專屬值，三者全填才啟用。
 * - 公司名稱 / 密碼 / 查貨參數只用於 API 請求，永不寫入 log / order note。
 *
 * @see .claude/skills/hct-logistics/SKILL.md
 */
final class HctLogisticsSettingsDTO {

	/** @var string 測試（DEV）EDI 端點 */
	public const TEST_ENDPOINT = 'https://hctrt.hct.com.tw/edi_webservice2_test/service1.asmx';

	/** @var string 正式（PROD）EDI 端點 */
	public const PROD_ENDPOINT = 'https://hctrt.hct.com.tw/edi_webservice2/service1.asmx';

	/** @var string 貨況多筆查詢端點（無測試站） */
	public const TRACK_ENDPOINT = 'https://hctapiweb.hct.com.tw/phone/searchGoods_Main_Xml.ashx';

	/** @var int 號段本體上限（9 碼） */
	public const WAYBILL_MAX = 999999999;

	/** @var array<string, string> 欄位預設值（順序 = to_array 輸出順序） */
	private const DEFAULTS = [
		'enabled'              => 'no',
		'title'                => '新竹物流',
		'description'          => '新竹物流宅配到府',
		'mode'                 => 'test',
		'company'              => '',
		'password'             => '',
		'customer_code'        => '',
		'sender_name'          => '',
		'sender_tel'           => '',
		'sender_address'       => '',
		'waybill_start'        => '',
		'waybill_end'          => '',
		'default_product_type' => '001',
		'default_weight'       => '1',
		'track_offset_days'    => '',
		'track_iv'             => '',
		'track_v'              => '',
	];

	/** @var array<int, string> 允許為空字串的欄位（其餘空字串退回預設） */
	private const NULLABLE = [
		'company',
		'password',
		'customer_code',
		'sender_name',
		'sender_tel',
		'sender_address',
		'waybill_start',
		'waybill_end',
		'track_offset_days',
		'track_iv',
		'track_v',
	];

	/** @var self|null 單例 */
	private static ?self $instance = null;

	/**
	 * Constructor
	 *
	 * @param string $enabled              是否啟用 yes|no
	 * @param string $title                顯示名稱
	 * @param string $description          描述
	 * @param string $mode                 test|prod
	 * @param string $company              EDI 公司名稱（SOAP 第 1 參數）
	 * @param string $password             EDI 密碼（SOAP 第 2 參數）
	 * @param string $customer_code        客戶代號 escsno（11 碼，空 = 帳號預設）
	 * @param string $sender_name          出貨人名稱 etcsig（空 = 帳號預設）
	 * @param string $sender_tel           出貨人電話 ettel1（空 = 帳號預設）
	 * @param string $sender_address       出貨地址 etaddr（影響拒收退回地址；空 = 帳號預設）
	 * @param string $waybill_start        號段起號（9 碼本體）
	 * @param string $waybill_end          號段迄號（9 碼本體）
	 * @param string $default_product_type 預設商品種類（001 / 003 / 008）
	 * @param string $default_weight       預設重量（kg，商品無重量時使用）
	 * @param string $track_offset_days    查貨金鑰日期偏移天數
	 * @param string $track_iv             查貨 IV（8 位）
	 * @param string $track_v              查貨 v（32 位）
	 */
	private function __construct(
		public readonly string $enabled,
		public readonly string $title,
		public readonly string $description,
		public readonly string $mode,
		public readonly string $company,
		public readonly string $password,
		public readonly string $customer_code,
		public readonly string $sender_name,
		public readonly string $sender_tel,
		public readonly string $sender_address,
		public readonly string $waybill_start,
		public readonly string $waybill_end,
		public readonly string $default_product_type,
		public readonly string $default_weight,
		public readonly string $track_offset_days,
		public readonly string $track_iv,
		public readonly string $track_v,
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
		if (null === HctProductType::tryFrom( $s['default_product_type'] )) {
			throw new \InvalidArgumentException( 'HctLogisticsSettingsDTO：商品種類須為 001 / 003 / 008' );
		}
		if (1 !== \preg_match( '/^[1-9]\d{0,4}$/', $s['default_weight'] )) {
			throw new \InvalidArgumentException( 'HctLogisticsSettingsDTO：預設重量須為 1~99999 的整數' );
		}
		if ('' !== $s['customer_code'] && 1 !== \preg_match( '/^\d{11}$/', $s['customer_code'] )) {
			throw new \InvalidArgumentException( 'HctLogisticsSettingsDTO：客戶代號須為 11 碼數字' );
		}
		self::assert_waybill_range( $s['waybill_start'], $s['waybill_end'] );
		self::assert_track_query( $s['track_offset_days'], $s['track_iv'], $s['track_v'] );

		return new self(
			enabled: $s['enabled'],
			title: $s['title'],
			description: $s['description'],
			mode: $s['mode'],
			company: $s['company'],
			password: $s['password'],
			customer_code: $s['customer_code'],
			sender_name: $s['sender_name'],
			sender_tel: $s['sender_tel'],
			sender_address: $s['sender_address'],
			waybill_start: $s['waybill_start'],
			waybill_end: $s['waybill_end'],
			default_product_type: $s['default_product_type'],
			default_weight: $s['default_weight'],
			track_offset_days: $s['track_offset_days'],
			track_iv: $s['track_iv'],
			track_v: $s['track_v'],
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
		$option = ProviderUtils::get_option( 'hct_logistics' );
		try {
			/** @var array<string, mixed> $data */
			$data           = \is_array( $option ) ? $option : [];
			self::$instance = self::create( $data );
		} catch ( \InvalidArgumentException $e ) {
			Plugin::logger( '新竹物流設定毀損，改用預設值', 'warning', [ 'error' => $e->getMessage() ] );
			self::$instance = self::create( [] );
		}
		return self::$instance;
	}

	/** @return void 重置單例（設定變更 / 測試用） */
	public static function reset(): void {
		self::$instance = null;
	}

	/** @return string 依 mode 的 EDI 端點 */
	public function endpoint(): string {
		return 'prod' === $this->mode ? self::PROD_ENDPOINT : self::TEST_ENDPOINT;
	}

	/** @return bool 公司名稱與密碼是否齊全 */
	public function has_credentials(): bool {
		return '' !== $this->company && '' !== $this->password;
	}

	/** @return bool 是否設定號段（我方配號） */
	public function has_waybill_range(): bool {
		return '' !== $this->waybill_start && '' !== $this->waybill_end;
	}

	/** @return bool 貨況查詢參數是否齊全 */
	public function has_track_query(): bool {
		return '' !== $this->track_offset_days && '' !== $this->track_iv && '' !== $this->track_v;
	}

	/** @return int 查貨金鑰日期偏移天數 */
	public function track_offset(): int {
		return (int) $this->track_offset_days;
	}

	/** @return array<string, string> 轉陣列（admin 設定頁讀寫；與 create() 往返一致） */
	public function to_array(): array {
		return [
			'enabled'              => $this->enabled,
			'title'                => $this->title,
			'description'          => $this->description,
			'mode'                 => $this->mode,
			'company'              => $this->company,
			'password'             => $this->password,
			'customer_code'        => $this->customer_code,
			'sender_name'          => $this->sender_name,
			'sender_tel'           => $this->sender_tel,
			'sender_address'       => $this->sender_address,
			'waybill_start'        => $this->waybill_start,
			'waybill_end'          => $this->waybill_end,
			'default_product_type' => $this->default_product_type,
			'default_weight'       => $this->default_weight,
			'track_offset_days'    => $this->track_offset_days,
			'track_iv'             => $this->track_iv,
			'track_v'              => $this->track_v,
		];
	}

	/**
	 * 號段驗證：起訖皆空或皆為 1~9 碼正整數且起 ≤ 迄
	 *
	 * @param string $start 起號
	 * @param string $end   迄號
	 * @return void
	 * @throws \InvalidArgumentException 不合法
	 */
	private static function assert_waybill_range( string $start, string $end ): void {
		if ('' === $start && '' === $end) {
			return;
		}
		if (1 !== \preg_match( '/^\d{1,9}$/', $start ) || 1 !== \preg_match( '/^\d{1,9}$/', $end )) {
			throw new \InvalidArgumentException( 'HctLogisticsSettingsDTO：號段起訖須同時填寫 9 碼以內數字（不含檢查碼）' );
		}
		if ( (int) $start < 1 || (int) $start > (int) $end) {
			throw new \InvalidArgumentException( 'HctLogisticsSettingsDTO：號段起號須 ≥ 1 且 ≤ 迄號' );
		}
	}

	/**
	 * 查貨參數驗證：各自有填就須合法（偏移 = 整數、IV = 8 位、v = 32 位）
	 *
	 * @param string $offset 偏移天數
	 * @param string $iv     IV
	 * @param string $v      v
	 * @return void
	 * @throws \InvalidArgumentException 不合法
	 */
	private static function assert_track_query( string $offset, string $iv, string $v ): void {
		if ('' !== $offset && 1 !== \preg_match( '/^-?\d{1,4}$/', $offset )) {
			throw new \InvalidArgumentException( 'HctLogisticsSettingsDTO：查貨金鑰偏移天數須為整數' );
		}
		if ('' !== $iv && 8 !== \strlen( $iv )) {
			throw new \InvalidArgumentException( 'HctLogisticsSettingsDTO：查貨 IV 須為 8 位' );
		}
		if ('' !== $v && 32 !== \strlen( $v )) {
			throw new \InvalidArgumentException( 'HctLogisticsSettingsDTO：查貨 v 值須為 32 位' );
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
				throw new \InvalidArgumentException( "HctLogisticsSettingsDTO：{$key} 必須為字串" );
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
			throw new \InvalidArgumentException( "HctLogisticsSettingsDTO：{$key} 值不正確" );
		}
	}
}
