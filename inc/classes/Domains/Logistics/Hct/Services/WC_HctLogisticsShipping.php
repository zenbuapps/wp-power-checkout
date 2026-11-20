<?php
/**
 * 新竹物流 WC_Shipping_Method（classic 結帳運送方式，宅配 only）
 *
 *  - 每個運送方式實例一個商品種類（一般 / 冷藏 / 冷凍）+ 固定運費；需要多溫層時在運送區域加多個實例
 *  - rate meta 帶 product_type，下單時經 save_checkout_meta 寫入 _pc_hct_logistics_product_type（白名單把關），建單沿用
 *  - provider（hct_logistics）未啟用時不產 rate
 *  - 全域設定 option key 改為 woocommerce_hct_logistics_shipping_settings，避免與 provider option（同 id）互相覆寫
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Services;

use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Enums\HctProductType;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctLogisticsMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 新竹物流運送方式（classic checkout） */
final class WC_HctLogisticsShipping extends \WC_Shipping_Method {

	/** @var string 運送方式 id */
	public const METHOD_ID = 'hct_logistics';

	/** @var string 固定運費 */
	public string $cost = '0';

	/** @var string 商品種類 */
	public string $product_type = '001';

	/**
	 * Constructor
	 *
	 * @param int $instance_id Shipping zone instance id（0 = 全域）
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = \absint( $instance_id );
		$this->method_title       = \__( '新竹物流', 'power_checkout' );
		$this->method_description = \__( '新竹物流宅配到府，可設定商品種類（常溫 / 冷藏 / 冷凍）與固定運費。', 'power_checkout' );
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

		$this->title        = (string) $this->get_option( 'title', \__( '新竹物流', 'power_checkout' ) );
		$this->enabled      = (string) $this->get_option( 'enabled', 'yes' );
		$this->cost         = (string) $this->get_option( 'cost', '0' );
		$product_type       = (string) $this->get_option( 'product_type', HctProductType::NORMAL->value );
		$this->product_type = null !== HctProductType::tryFrom( $product_type ) ? $product_type : HctProductType::NORMAL->value;

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
		$options = [];
		foreach ( HctProductType::cases() as $case ) {
			$options[ $case->value ] = $case->label();
		}

		$this->instance_form_fields = [
			'enabled'      => [
				'title'   => \__( '啟用', 'power_checkout' ),
				'type'    => 'checkbox',
				'label'   => \__( '啟用新竹物流運送方式', 'power_checkout' ),
				'default' => 'yes',
			],
			'title'        => [
				'title'       => \__( '名稱', 'power_checkout' ),
				'type'        => 'text',
				'description' => \__( '結帳頁顯示的運送方式名稱。', 'power_checkout' ),
				'default'     => \__( '新竹物流', 'power_checkout' ),
				'desc_tip'    => true,
			],
			'product_type' => [
				'title'       => \__( '商品種類', 'power_checkout' ),
				'type'        => 'select',
				'description' => \__( '此運送方式的溫層（新竹物流商品種類）。', 'power_checkout' ),
				'default'     => HctProductType::NORMAL->value,
				'options'     => $options,
				'desc_tip'    => true,
			],
			'cost'         => [
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
	 * 計算運費（provider 啟用時產生 1 個 rate）
	 *
	 * @param array<string, mixed> $package 購物車運送 package
	 * @return void
	 */
	public function calculate_shipping( $package = [] ): void {
		if (!ProviderUtils::is_enabled( HctLogisticsProvider::ID )) {
			return;
		}

		$product_type = HctProductType::from( $this->product_type );
		$label        = $this->title ?: $this->method_title;
		if (HctProductType::NORMAL !== $product_type) {
			$label .= "（{$product_type->label()}）";
		}

		$this->add_rate(
			[
				'id'        => $this->get_rate_id(),
				'label'     => $label,
				'cost'      => (float) $this->cost,
				'package'   => $package,
				'meta_data' => [ 'product_type' => $product_type->value ],
			]
		);
	}

	/**
	 * Classic checkout：寫入結帳選用的商品種類（白名單把關）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $data  結帳送出資料
	 * @return void
	 */
	public static function save_checkout_meta( \WC_Order $order, array $data = [] ): void {
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if (self::METHOD_ID !== $shipping_item->get_method_id()) {
				continue;
			}
			$product_type = (string) $shipping_item->get_meta( 'product_type' );
			if (null === HctProductType::tryFrom( $product_type )) {
				return;
			}
			( new HctLogisticsMetaKeys( $order ) )->save_checkout_choice( $product_type );
			return;
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
