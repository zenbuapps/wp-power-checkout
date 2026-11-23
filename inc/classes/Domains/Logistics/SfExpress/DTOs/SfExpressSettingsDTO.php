<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs;

use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfCrypto;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * 順豐（sf_express）設定，對應 woocommerce_sf_express_settings
 *
 * - 不繼承 wp-utils DTO（R16：其必填驗證從未生效）；create() 顯式驗證並拋 \InvalidArgumentException。
 * - mode 預設 test（R17）；所有憑證預設空字串——**官方公開沙箱憑證也不寫成預設值或 fallback**，由站主在設定頁填入。
 * - interProductCode 預設 INT0005（2026-09-23 / 11-21 沙箱 TW→TW 試算有回；正式環境可用產品以順豐簽約為準）。
 * - 寄件人：區劃（regionFirst / regionSecond）由寄件郵遞區號 + 寄件地址開頭的縣市區名換算，不另設縣市欄位。
 * - appKey / appSecret / AES 金鑰 / 月結卡號 / 推送金鑰只用於 API 請求與驗章，永不寫入 log / order note / REST 回應以外的地方。
 *
 * @see .claude/skills/sf-express/references/onboarding-and-faq.md
 */
final class SfExpressSettingsDTO {

	/** @var string 沙箱 base URL（官方 http / https 皆可，一律走 https：token 端點 querystring 帶 appSecret） */
	public const TEST_BASE_URL = 'https://api-ifsp-sit.sf.global';

	/** @var string 正式 base URL */
	public const PROD_BASE_URL = 'https://api-ifsp.sf.global';

	/** @var array<string, string> 欄位預設值（順序 = to_array 輸出順序） */
	private const DEFAULTS = [
		'enabled'           => 'no',
		'title'             => '順豐速運',
		'description'       => '順豐速運宅配到府',
		'mode'              => 'test',
		'app_key'           => '',
		'app_secret'        => '',
		'encoding_aes_key'  => '',
		'customer_code'     => '',
		'month_card'        => '',
		'product_code'      => 'INT0005',
		'push_sign_key'     => '',
		'sender_name'       => '',
		'sender_mobile'     => '',
		'sender_tel'        => '',
		'sender_company'    => '',
		'sender_postcode'   => '',
		'sender_address'    => '',
		'sender_cargo_type' => '2',
		'sender_tax_id'     => '',
		'parcel_name'       => '日用品',
	];

	/** @var array<int, string> 允許為空字串的欄位（其餘空字串退回預設） */
	private const NULLABLE = [
		'app_key',
		'app_secret',
		'encoding_aes_key',
		'customer_code',
		'month_card',
		'push_sign_key',
		'sender_name',
		'sender_mobile',
		'sender_tel',
		'sender_company',
		'sender_postcode',
		'sender_address',
		'sender_tax_id',
	];

	/** @var self|null 單例 */
	private static ?self $instance = null;

	/**
	 * Constructor
	 *
	 * @param string $enabled           是否啟用 yes|no
	 * @param string $title             顯示名稱
	 * @param string $description       描述
	 * @param string $mode              test|prod
	 * @param string $app_key           appKey
	 * @param string $app_secret        appSecret（只用來換 token）
	 * @param string $encoding_aes_key  AES 金鑰（43 字元）
	 * @param string $customer_code     IUOP 客戶編碼
	 * @param string $month_card        月結卡號
	 * @param string $product_code      產品映射碼 interProductCode
	 * @param string $push_sign_key     軌跡推送簽章金鑰（聯調時順豐提供）
	 * @param string $sender_name       寄件人姓名
	 * @param string $sender_mobile     寄件人手機
	 * @param string $sender_tel        寄件人市話（選填）
	 * @param string $sender_company    寄件公司（選填）
	 * @param string $sender_postcode   寄件郵遞區號（3 / 5 / 6 碼）
	 * @param string $sender_address    寄件地址（含縣市區，中文）
	 * @param string $sender_cargo_type 寄件類型 1 個人件 / 2 公司件
	 * @param string $sender_tax_id     寄件公司統編（公司件選填，證件類型 009）
	 * @param string $parcel_name       託寄物品名
	 */
	private function __construct(
		public readonly string $enabled,
		public readonly string $title,
		public readonly string $description,
		public readonly string $mode,
		public readonly string $app_key,
		public readonly string $app_secret,
		public readonly string $encoding_aes_key,
		public readonly string $customer_code,
		public readonly string $month_card,
		public readonly string $product_code,
		public readonly string $push_sign_key,
		public readonly string $sender_name,
		public readonly string $sender_mobile,
		public readonly string $sender_tel,
		public readonly string $sender_company,
		public readonly string $sender_postcode,
		public readonly string $sender_address,
		public readonly string $sender_cargo_type,
		public readonly string $sender_tax_id,
		public readonly string $parcel_name,
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
		self::assert_in( 'sender_cargo_type', $s['sender_cargo_type'], [ '1', '2' ] );
		if ('' !== $s['encoding_aes_key'] && !SfCrypto::is_valid_aes_key( $s['encoding_aes_key'] )) {
			throw new \InvalidArgumentException( 'SfExpressSettingsDTO：AES 金鑰須為 43 個字元' );
		}
		if (1 !== \preg_match( '/^[A-Za-z0-9]{2,30}$/', $s['product_code'] )) {
			throw new \InvalidArgumentException( 'SfExpressSettingsDTO：產品代碼格式不正確（例：INT0005）' );
		}
		if ('' !== $s['sender_tax_id'] && 1 !== \preg_match( '/^\d{8}$/', $s['sender_tax_id'] )) {
			throw new \InvalidArgumentException( 'SfExpressSettingsDTO：統一編號須為 8 碼數字' );
		}
		if ('' !== $s['sender_postcode'] && 1 !== \preg_match( '/^\d{3}(\d{2,3})?$/', $s['sender_postcode'] )) {
			throw new \InvalidArgumentException( 'SfExpressSettingsDTO：寄件郵遞區號須為 3、5 或 6 碼數字' );
		}

		return new self(
			enabled: $s['enabled'],
			title: $s['title'],
			description: $s['description'],
			mode: $s['mode'],
			app_key: $s['app_key'],
			app_secret: $s['app_secret'],
			encoding_aes_key: $s['encoding_aes_key'],
			customer_code: $s['customer_code'],
			month_card: $s['month_card'],
			product_code: $s['product_code'],
			push_sign_key: $s['push_sign_key'],
			sender_name: $s['sender_name'],
			sender_mobile: $s['sender_mobile'],
			sender_tel: $s['sender_tel'],
			sender_company: $s['sender_company'],
			sender_postcode: $s['sender_postcode'],
			sender_address: $s['sender_address'],
			sender_cargo_type: $s['sender_cargo_type'],
			sender_tax_id: $s['sender_tax_id'],
			parcel_name: $s['parcel_name'],
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
		$option = ProviderUtils::get_option( 'sf_express' );
		try {
			/** @var array<string, mixed> $data */
			$data           = \is_array( $option ) ? $option : [];
			self::$instance = self::create( $data );
		} catch ( \InvalidArgumentException $e ) {
			Plugin::logger( '順豐設定毀損，改用預設值', 'warning', [ 'error' => $e->getMessage() ] );
			self::$instance = self::create( [] );
		}
		return self::$instance;
	}

	/** @return void 重置單例（設定變更 / 測試用） */
	public static function reset(): void {
		self::$instance = null;
	}

	/** @return string 依 mode 的 base URL */
	public function base_url(): string {
		return 'prod' === $this->mode ? self::PROD_BASE_URL : self::TEST_BASE_URL;
	}

	/** @return bool API 呼叫所需憑證（appKey / appSecret / AES / 客戶編碼）是否齊全 */
	public function has_api_credentials(): bool {
		return [] === $this->missing_api_credentials();
	}

	/** @return array<int, string> 缺少的 API 憑證欄位名（不含值） */
	public function missing_api_credentials(): array {
		return self::missing(
			[
				'appKey'    => $this->app_key,
				'appSecret' => $this->app_secret,
				'AES 金鑰'    => $this->encoding_aes_key,
				'客戶編碼'      => $this->customer_code,
			]
		);
	}

	/** @return array<int, string> 建單另需的欄位（月結卡號）缺漏清單 */
	public function missing_shipment_settings(): array {
		return self::missing( [ '月結卡號' => $this->month_card ] );
	}

	/** @return array<int, string> 寄件人必要欄位缺漏清單 */
	public function missing_sender(): array {
		return self::missing(
			[
				'寄件人姓名'  => $this->sender_name,
				'寄件人手機'  => $this->sender_mobile,
				'寄件郵遞區號' => $this->sender_postcode,
				'寄件地址'   => $this->sender_address,
			]
		);
	}

	/** @return bool 是否已設定推送簽章金鑰 */
	public function has_push_key(): bool {
		return '' !== $this->push_sign_key;
	}

	/** @return array<string, string> 轉陣列（admin 設定頁讀寫；與 create() 往返一致） */
	public function to_array(): array {
		return [
			'enabled'           => $this->enabled,
			'title'             => $this->title,
			'description'       => $this->description,
			'mode'              => $this->mode,
			'app_key'           => $this->app_key,
			'app_secret'        => $this->app_secret,
			'encoding_aes_key'  => $this->encoding_aes_key,
			'customer_code'     => $this->customer_code,
			'month_card'        => $this->month_card,
			'product_code'      => $this->product_code,
			'push_sign_key'     => $this->push_sign_key,
			'sender_name'       => $this->sender_name,
			'sender_mobile'     => $this->sender_mobile,
			'sender_tel'        => $this->sender_tel,
			'sender_company'    => $this->sender_company,
			'sender_postcode'   => $this->sender_postcode,
			'sender_address'    => $this->sender_address,
			'sender_cargo_type' => $this->sender_cargo_type,
			'sender_tax_id'     => $this->sender_tax_id,
			'parcel_name'       => $this->parcel_name,
		];
	}

	/**
	 * 列出空值欄位名
	 *
	 * @param array<string, string> $fields 欄位名 => 值
	 * @return array<int, string>
	 */
	private static function missing( array $fields ): array {
		return \array_keys( \array_filter( $fields, static fn( string $value ): bool => '' === $value ) );
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
				throw new \InvalidArgumentException( "SfExpressSettingsDTO：{$key} 必須為字串" );
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
			throw new \InvalidArgumentException( "SfExpressSettingsDTO：{$key} 值不正確" );
		}
	}
}
