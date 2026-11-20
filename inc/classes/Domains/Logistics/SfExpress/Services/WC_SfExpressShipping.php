<?php
/**
 * 順豐速運 WC_Shipping_Method（classic 結帳運送方式，宅配 only）
 *
 *  - 固定運費；provider（sf_express）未啟用時不產 rate
 *  - 建單只組台灣收件（receiverInfo.country=TW）→ 目的地非 TW 不產 rate
 *  - 下單經 save_checkout_meta 寫入 _pc_sf_express_provider_id（只有選用本運送方式時）
 *  - 全域設定 option key 改為 woocommerce_sf_express_shipping_settings，避免與 provider option（同 id）互相覆寫
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Services;

use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfExpressMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 順豐速運運送方式（classic checkout） */
final class WC_SfExpressShipping extends \WC_Shipping_Method {

	/** @var string 運送方式 id */
	public const METHOD_ID = 'sf_express';

	/** @var string 固定運費 */
	public string $cost = '0';

	/**
	 * Constructor
	 *
	 * @param int $instance_id Shipping zone instance id（0 = 全域）
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = \absint( $instance_id );
		$this->method_title       = \__( '順豐速運', 'power_checkout' );
		$this->method_description = \__( '順豐速運宅配到府（台灣收件），固定運費。', 'power_checkout' );
		$this->supports           = [
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		];

		$this->init();
	}

	/** @return void 初始化設定欄位與值 */
	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title   = (string) $this->get_option( 'title', \__( '順豐速運', 'power_checkout' ) );
		$this->enabled = (string) $this->get_option( 'enabled', 'yes' );
		$this->cost    = (string) $this->get_option( 'cost', '0' );

		\add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * 全域設定 option key（避開 provider option）
	 *
	 * @return string
	 */
	public function get_option_key(): string {
		return $this->plugin_id . $this->id . '_shipping_settings';
	}

	/** @return void 運送方式設定欄位 */
	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'enabled' => [
				'title'   => \__( '啟用', 'power_checkout' ),
				'type'    => 'checkbox',
				'label'   => \__( '啟用順豐速運運送方式', 'power_checkout' ),
				'default' => 'yes',
			],
			'title'   => [
				'title'       => \__( '名稱', 'power_checkout' ),
				'type'        => 'text',
				'description' => \__( '結帳頁顯示的運送方式名稱。', 'power_checkout' ),
				'default'     => \__( '順豐速運', 'power_checkout' ),
				'desc_tip'    => true,
			],
			'cost'    => [
				'title'       => \__( '運費', 'power_checkout' ),
				'type'        => 'text',
				'description' => \__( '固定運費（新台幣），預設 0。', 'power_checkout' ),
				'default'     => '0',
				'desc_tip'    => true,
			],
		];

		$this->form_fields = $this->instance_form_fields;
	}

	/**
	 * 計算運費（provider 啟用且目的地為台灣時產生 1 個 rate）
	 *
	 * @param array<string, mixed> $package 購物車運送 package
	 * @return void
	 */
	public function calculate_shipping( $package = [] ): void {
		if (!ProviderUtils::is_enabled( SfExpressProvider::ID )) {
			return;
		}
		$destination = \is_array( $package['destination'] ?? null ) ? $package['destination'] : [];
		$country     = \is_string( $destination['country'] ?? null ) ? $destination['country'] : '';
		if ('' !== $country && 'TW' !== $country) {
			return;
		}

		$this->add_rate(
			[
				'id'      => $this->get_rate_id(),
				'label'   => $this->title ?: $this->method_title,
				'cost'    => (float) $this->cost,
				'package' => $package,
			]
		);
	}

	/**
	 * Classic checkout：選用本運送方式時寫入 provider id
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $data  結帳送出資料
	 * @return void
	 */
	public static function save_checkout_meta( \WC_Order $order, array $data = [] ): void {
		if (self::is_chosen( $order )) {
			( new SfExpressMetaKeys( $order ) )->save_checkout_choice();
		}
	}

	/**
	 * 訂單是否選用本運送方式
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	public static function is_chosen( \WC_Order $order ): bool {
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if (self::METHOD_ID === $shipping_item->get_method_id()) {
				return true;
			}
		}
		return false;
	}
}
