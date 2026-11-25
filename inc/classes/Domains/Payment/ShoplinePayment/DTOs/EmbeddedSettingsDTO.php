<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * SHOPLINE Payments 嵌入式（站內付）設定
 *
 * 只放「顯示欄位」：enabled / title / description / order_button_text。
 * 商店憑證（merchantId / apiKey / clientKey / signKey / platformId）與 mode 一律共用導轉式
 * {@see RedirectSettingsDTO}（藍圖 §9 修正 3）：既有 webhook 驗簽固定讀 RedirectSettingsDTO->signKey，
 * 而 SLP 一個商店只能設一個 webhook URL，兩份憑證會造成驗簽不一致。
 *
 * 不繼承 wp-utils DTO（R16：其必填驗證從未生效）；create() 內顯式驗證並拋 \InvalidArgumentException。
 */
final class EmbeddedSettingsDTO {

	/** @var array<string, string> 預設值 */
	private const DEFAULTS = [
		'enabled'           => 'no',
		'title'             => 'SHOPLINE Payments（嵌入支付）',
		'description'       => '於本站內輸入信用卡完成付款（支援分期），付款由 SHOPLINE Payments 處理',
		'order_button_text' => '前往付款',
	];

	/**
	 * Constructor
	 *
	 * @param string $enabled           是否啟用 yes|no
	 * @param string $title             前台付款方式標題
	 * @param string $description       前台付款方式描述
	 * @param string $order_button_text 結帳按鈕文字
	 */
	private function __construct(
		public readonly string $enabled,
		public readonly string $title,
		public readonly string $description,
		public readonly string $order_button_text,
	) {
	}

	/**
	 * 由陣列建立（嚴格驗證；未知鍵忽略、缺鍵取預設）
	 *
	 * @param array<string, mixed> $data 設定資料
	 * @return self
	 * @throws \InvalidArgumentException 欄位型別錯誤或 enabled 非 yes|no
	 */
	public static function create( array $data ): self {
		$values = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$raw = $data[ $key ] ?? $default;
			if ( ! \is_string( $raw ) ) {
				throw new \InvalidArgumentException( "EmbeddedSettingsDTO：{$key} 必須為字串" );
			}
			$value          = StrHelper::trim_invisible( $raw );
			$values[ $key ] = '' === $value && 'enabled' !== $key ? $default : $value;
		}

		if ( ! \in_array( $values['enabled'], [ 'yes', 'no' ], true ) ) {
			throw new \InvalidArgumentException( 'EmbeddedSettingsDTO：enabled 必須為 yes 或 no' );
		}

		return new self(
			enabled: $values['enabled'],
			title: $values['title'],
			description: $values['description'],
			order_button_text: $values['order_button_text'],
		);
	}

	/**
	 * 從 woocommerce_shopline_payment_embedded_settings 取得設定
	 *
	 * 若 option 毀損不拋錯（gateway 建構期呼叫，拋錯會拖垮整個付款方式列表），記 log 後回預設值。
	 *
	 * @return self
	 */
	public static function instance(): self {
		$option = ProviderUtils::get_option( EmbeddedGateway::ID );
		try {
			return self::create( \is_array( $option ) ? $option : [] );
		} catch ( \InvalidArgumentException $e ) {
			Plugin::logger( 'SLP 嵌入式設定毀損，改用預設值', 'warning', [ 'error' => $e->getMessage() ] );
			return self::create( [] );
		}
	}

	/** @return array{enabled: string, title: string, description: string, order_button_text: string} 轉陣列 */
	public function to_array(): array {
		return [
			'enabled'           => $this->enabled,
			'title'             => $this->title,
			'description'       => $this->description,
			'order_button_text' => $this->order_button_text,
		];
	}
}
