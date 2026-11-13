<?php
/**
 * F6：「差多少免運」提示（DF5）
 *
 * - 計算：FreeShippingCalculator（與 WC_Shipping_Free_Shipping::is_available 同算法）
 * - 傳統結帳：woocommerce_cart_totals_before_shipping（購物車頁）/ woocommerce_review_order_before_shipping（結帳頁）
 *   → render_row() echo `<tr class="pc-free-shipping-hint">`
 * - 區塊結帳：Store API cart extension（namespace power-checkout）→ extensions['power-checkout']['free_shipping_hint']
 *   （結構見 specs/api.yml FreeShippingHint）；前端 inc/assets/blocks/pc_free_shipping_hint.tsx 只負責呈現
 * - 區塊腳本：僅在含 woocommerce/cart 或 woocommerce/checkout block 的頁面、開關開啟、建置產物存在時 enqueue
 * - 文案 {price} 以差額純數字（商店小數位、千分位）替換；文案 esc_html、色碼限 #RRGGBB（否則用預設值）且輸出前 esc_attr
 * - never-throw：任何 \Throwable 記 log，傳統不輸出、區塊回 null
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\Services;

use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use J7\PowerCheckout\Domains\Checkout\Shared\Helpers\FreeShippingCalculator;
use J7\PowerCheckout\Plugin;

/** 差多少免運提示 */
final class FreeShippingHintService {

	/** @var string 設定開關鍵 */
	private const SETTING_KEY = 'free_shipping_hint';

	/** @var string Store API cart extension namespace */
	public const NAMESPACE = 'power-checkout';

	/** @var string Store API extension 資料鍵 */
	public const DATA_KEY = 'free_shipping_hint';

	/** @var string 區塊腳本 handle */
	public const SCRIPT_HANDLE = 'pc-free-shipping-hint-blocks';

	/** @var string 區塊腳本建置產物（相對外掛根目錄） */
	private const SCRIPT_PATH = 'inc/assets/dist/blocks/pc_free_shipping_hint.js';

	/** @var string 傳統提示列 CSS class */
	private const ROW_CLASS = 'pc-free-shipping-hint';

	/** @var string 色碼格式 */
	private const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/D';

	/** @var array<int, string> 需要 enqueue 區塊腳本的 block */
	private const TARGET_BLOCKS = [ 'woocommerce/cart', 'woocommerce/checkout' ];

	/**
	 * 註冊 hooks（無條件掛上，開關於 callback 內判斷）
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_action( 'woocommerce_cart_totals_before_shipping', [ __CLASS__, 'render_row' ] );
		\add_action( 'woocommerce_review_order_before_shipping', [ __CLASS__, 'render_row' ] );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_block_assets' ], 20 );

		if ( \did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
		} else {
			\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_store_api' ] );
		}
	}

	/**
	 * 傳統結帳：於運送列上方輸出提示列
	 *
	 * @return void
	 */
	public static function render_row(): void {
		try {
			$hint = self::build_hint();
			if ( null === $hint ) {
				return;
			}

			$class      = \esc_attr( self::ROW_CLASS );
			$bg_color   = \esc_attr( $hint['bg_color'] );
			$text_color = \esc_attr( $hint['text_color'] );
			$text       = \esc_html( $hint['text'] );

			// phpcs:disable WordPress.Security.EscapeOutput.HeredocOutputNotEscaped -- 各值已於上方 esc_attr / esc_html
			echo <<<HTML
			<tr class="{$class}">
				<td colspan="2" style="background-color:{$bg_color};color:{$text_color};text-align:center;">{$text}</td>
			</tr>
			HTML;
			// phpcs:enable WordPress.Security.EscapeOutput.HeredocOutputNotEscaped
		} catch ( \Throwable $e ) {
			Plugin::logger( "FreeShippingHintService::render_row 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * 註冊 Store API cart extension
	 *
	 * @return void
	 */
	public static function register_store_api(): void {
		try {
			if ( !\function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}

			\woocommerce_store_api_register_endpoint_data(
				[
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => [ __CLASS__, 'store_api_data' ],
					'schema_callback' => [ __CLASS__, 'store_api_schema' ],
					'schema_type'     => 'ARRAY_A', // 同 WP 常數 ARRAY_A 的值
				]
			);
		} catch ( \Throwable $e ) {
			Plugin::logger( "FreeShippingHintService::register_store_api 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * Store API cart extension data callback
	 *
	 * @return array{free_shipping_hint: array{achieved: bool, diff: float, text: string, bg_color: string, text_color: string}|null} 提示資料
	 */
	public static function store_api_data(): array {
		try {
			return [ self::DATA_KEY => self::build_hint() ];
		} catch ( \Throwable $e ) {
			Plugin::logger( "FreeShippingHintService::store_api_data 失敗：{$e->getMessage()}", 'error' );
			return [ self::DATA_KEY => null ];
		}
	}

	/**
	 * Store API cart extension schema callback
	 *
	 * @return array<string, array<string, mixed>> schema
	 */
	public static function store_api_schema(): array {
		return [
			self::DATA_KEY => [
				'description' => \__( '差多少免運提示（null 代表不顯示）', 'power_checkout' ),
				'type'        => [ 'object', 'null' ],
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
				'properties'  => [
					'achieved'   => [
						'description' => \__( '是否已達免運', 'power_checkout' ),
						'type'        => 'boolean',
						'readonly'    => true,
					],
					'diff'       => [
						'description' => \__( '差額（已達時為 0）', 'power_checkout' ),
						'type'        => 'number',
						'readonly'    => true,
					],
					'text'       => [
						'description' => \__( '已替換 {price} 並跳脫的提示文案', 'power_checkout' ),
						'type'        => 'string',
						'readonly'    => true,
					],
					'bg_color'   => [
						'description' => \__( '提示底色', 'power_checkout' ),
						'type'        => 'string',
						'readonly'    => true,
					],
					'text_color' => [
						'description' => \__( '提示文字色', 'power_checkout' ),
						'type'        => 'string',
						'readonly'    => true,
					],
				],
			],
		];
	}

	/**
	 * 區塊購物車 / 結帳頁 enqueue 前端呈現腳本
	 *
	 * @return void
	 */
	public static function enqueue_block_assets(): void {
		try {
			if ( !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) || !self::has_target_block() ) {
				return;
			}

			$file = Plugin::$dir . '/' . self::SCRIPT_PATH;
			if ( !\file_exists( $file ) ) {
				return; // 建置產物不存在時安全略過
			}

			\wp_enqueue_script(
				self::SCRIPT_HANDLE,
				Plugin::$url . '/' . self::SCRIPT_PATH,
				[
					'wc-blocks-checkout',
					'wp-plugins',
					'wp-data',
					'wp-element',
					'wp-html-entities',
					'wp-i18n',
				],
				Plugin::$version,
				true
			);
			// Vite 產出 ESM（含 import），須以 type="module" 載入
			Plugin::instance()->add_module_handle( self::SCRIPT_HANDLE );
		} catch ( \Throwable $e ) {
			Plugin::logger( "FreeShippingHintService::enqueue_block_assets 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * 組裝提示資料（開關關閉或無候選時為 null）
	 *
	 * @return array{achieved: bool, diff: float, text: string, bg_color: string, text_color: string}|null 提示資料
	 */
	private static function build_hint(): ?array {
		if ( !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) ) {
			return null;
		}

		$status = FreeShippingCalculator::calculate();
		if ( null === $status ) {
			return null;
		}

		$text = CheckoutEnhancementSettingsDTO::get_string( 'free_shipping_text_achieved' );
		if ( !$status->achieved ) {
			$text = \str_replace(
				CheckoutEnhancementSettingsDTO::PRICE_PLACEHOLDER,
				self::format_price( $status->diff ),
				CheckoutEnhancementSettingsDTO::get_string( 'free_shipping_text_left' )
			);
		}

		return [
			'achieved'   => $status->achieved,
			'diff'       => $status->diff,
			'text'       => \esc_html( $text ),
			'bg_color'   => self::color( 'free_shipping_bg_color' ),
			'text_color' => self::color( 'free_shipping_text_color' ),
		];
	}

	/**
	 * 差額格式化為純數字（商店小數位、千分位）
	 *
	 * @param float $diff 差額
	 * @return string 格式化後字串
	 */
	private static function format_price( float $diff ): string {
		return \number_format(
			$diff,
			\wc_get_price_decimals(),
			\wc_get_price_decimal_separator(),
			\wc_get_price_thousand_separator()
		);
	}

	/**
	 * 取得合法色碼（不符 #RRGGBB 時回退預設值）
	 *
	 * @param string $key 色碼設定鍵
	 * @return string 色碼
	 */
	private static function color( string $key ): string {
		$value = CheckoutEnhancementSettingsDTO::get_string( $key );
		return 1 === \preg_match( self::COLOR_PATTERN, $value ) ? $value : CheckoutEnhancementSettingsDTO::DEFAULTS[ $key ];
	}

	/**
	 * 目前頁面是否含區塊購物車或區塊結帳
	 *
	 * @return bool 是否含目標 block
	 */
	private static function has_target_block(): bool {
		$post = \get_post();
		if ( !$post instanceof \WP_Post ) {
			return false;
		}
		foreach ( self::TARGET_BLOCKS as $block ) {
			if ( \has_block( $block, $post ) ) {
				return true;
			}
		}
		return false;
	}
}
