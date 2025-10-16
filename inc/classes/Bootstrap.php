<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout;

use J7\PowerCheckout\Utils\Base;
use J7\WpUtils\Classes\WP;
use Kucrut\Vite;

if ( class_exists( 'J7\PowerCheckout\Bootstrap' ) ) {
	return;
}

/** Bootstrap */
final class Bootstrap {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {

		FrontEnd\Entry::instance();
		Admin\CPT::instance();
		Domains\Payment\Loader::register_hooks();
		Domains\Settings\Services\SettingApiService::register_hooks();
		Domains\Settings\Services\SettingTabService::register_hooks();

		\add_action( 'before_woocommerce_init', [ __CLASS__, 'declare_compatibility' ] );
		\add_action('admin_head', [ __CLASS__, 'custom_css' ]);
	}


	/**
	 * 宣告區塊結帳相容性
	 *
	 * @return void
	 */
	public static function declare_compatibility(): void {
		if ( !class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			'power-checkout\plugin.php'
		);
	}

	/** @return void 自訂 css */
	public static function custom_css(): void {
		?>
		<style>
			.order_notes{
				.array_to_html{
					grid-template-columns: 72px 1fr;
				}
			}
			.array_to_html{
				display: grid;
				grid-template-columns: 1fr 2fr;
				gap: 0;width: 100%;
				align-items: start;
				font-size: 12px;
				justify-content: start;
				word-break: break-all;
				white-space: normal;

				& > div:nth-child(odd) {
					padding-right: 4px;
					font-weight: bold;
					border-bottom: 1px solid #aaa;
					height:100%;
				}

				& > div:nth-child(even) {
					border-bottom: 1px solid #aaa;
					height:100%;
					overflow-wrap: anywhere;
				}
			}
			
			.order_data_column > p {
				margin: 0;
			}
		</style>
		<?php
	}
}
