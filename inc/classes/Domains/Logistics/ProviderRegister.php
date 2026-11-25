<?php
/**
 * Logistics domain Loader（鏡像 Invoice\ProviderRegister）
 *
 * 1. $logistics_providers：本次唯一 provider ecpay_logistics（設計可容未來 PAYUNi）。
 * 2. register_hooks：
 *    - 啟用的 provider 才實例化放入 ProviderUtils::$container。
 *    - 任一 provider 啟用才註冊對外 REST（LogisticsApiService）+ callback（LogisticsCallback）。
 *    - 透過 woocommerce_shipping_methods filter 加入 WC_EcpayLogisticsShipping（classic 結帳運送方式）。
 *    - 透過 woocommerce_checkout_create_order 寫結帳選擇的物流子類型 / 付款情境 meta。
 * 3. get_registered_provider_dtos：給 SettingApiService GET /settings 的 data.logistics。
 *
 * @see Domains\Invoice\ProviderRegister
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\Http\LogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\BlocksLogisticsIntegration;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\WC_EcpayLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Hct\Services\HctLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Hct\Services\WC_HctLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Http\KerrytjAdminApiService;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\WC_KerrytjLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsCallback as PaynowLogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\WC_PaynowLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Payuni\Http\PayuniLogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\WC_PayuniLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\CartLogisticsApiService;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\CheckoutLogisticsIntegration;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\ShipmentStatusPoller;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsPingApiService;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Http\SfTrackPushCallback;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\WC_SfExpressShipping;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\WC_TcatLogisticsShipping;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** Loader 載入物流方式 */
final class ProviderRegister {

	/** @var array<string, string> $logistics_providers [id, class]（綠界 / PAYUNi / PayNow / 黑貓宅急便直串 / 新竹物流 / 順豐 / 嘉里大榮並存可切換） */
	private static array $logistics_providers = [
		EcpayLogisticsProvider::ID   => EcpayLogisticsProvider::class,
		PayuniLogisticsProvider::ID  => PayuniLogisticsProvider::class,
		PaynowLogisticsProvider::ID  => PaynowLogisticsProvider::class,
		TcatLogisticsProvider::ID    => TcatLogisticsProvider::class,
		HctLogisticsProvider::ID     => HctLogisticsProvider::class,
		SfExpressProvider::ID        => SfExpressProvider::class,
		KerrytjLogisticsProvider::ID => KerrytjLogisticsProvider::class,
	];

	/** 註冊 hooks @return void */
	public static function register_hooks(): void {
		// WC 運送方式（classic 結帳）— 不論啟用與否皆註冊類別，啟用狀態由運送方式自身設定控制
		\add_filter( 'woocommerce_shipping_methods', [ __CLASS__, 'add_shipping_method' ] );
		\add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_checkout_meta' ], 10, 2 );
		// 物流「測試連線」ping（admin；不需任何物流已啟用，設定頁啟用前即可測試）
		LogisticsPingApiService::register_hooks();
		// 新竹物流：設定儲存時同步號段到配號器（不需啟用）
		HctLogisticsProvider::register_hooks();
		// 嘉里大榮：設定儲存時同步常溫 / 低溫號段到配號器（不需啟用）
		KerrytjLogisticsProvider::register_hooks();
		// 順豐軌跡推送接收端（不需啟用：關掉順豐後在途包裹的貨態仍要更新；未設推送金鑰則整批拒收）
		SfTrackPushCallback::register_hooks();

		// 共用結帳整合（傳統 + Blocks 選子類型 / 選門市 / 下單寫 meta）；執行時才判斷哪些 provider 已啟用
		CheckoutLogisticsIntegration::register_hooks();
		// 共用貨態自動查詢（沒設推播網址時的保底；Action Scheduler）
		ShipmentStatusPoller::register_hooks();

		$any_enabled = false;
		foreach ( self::$logistics_providers as $id => $class_name ) {
			// 啟用才實例化放入容器
			if (!ProviderUtils::is_enabled( $id )) {
				continue;
			}
			self::register_provider_hooks( $id, $class_name );
			$any_enabled = true;
		}

		// 任一物流服務啟用才註冊對外 REST
		if ($any_enabled) {
			LogisticsApiService::instance();
			// cart 級選店端點（結帳下單前，無訂單；目前僅綠界支援 RWD 選店）
			CartLogisticsApiService::register_hooks();
		}

		// 各 provider 的 callback 各自註冊（端點 namespace 不同：ecpay / payuni）
		if (ProviderUtils::is_enabled( EcpayLogisticsProvider::ID )) {
			LogisticsCallback::register_hooks();
			// 綠界 block 結帳選店整合（enqueue + cart extensions + 下單寫 meta）
			BlocksLogisticsIntegration::register_hooks();
			// 綠界 classic 結帳選店按鈕（cart 級選店）
			\add_action( 'wp_enqueue_scripts', [ WC_EcpayLogisticsShipping::class, 'enqueue_classic_assets' ], 20 );
		}
		if (ProviderUtils::is_enabled( PayuniLogisticsProvider::ID )) {
			PayuniLogisticsCallback::register_hooks();
		}
		if (ProviderUtils::is_enabled( PaynowLogisticsProvider::ID )) {
			PaynowLogisticsCallback::register_hooks();
		}
		// 嘉里大榮專屬 admin REST（修改託運資料 / 託運明細 PDF）
		if (ProviderUtils::is_enabled( KerrytjLogisticsProvider::ID )) {
			KerrytjAdminApiService::register_hooks();
		}
	}

	/**
	 * 註冊服務的鉤子（放入 ProviderUtils 容器）
	 *
	 * @param string $id         服務 id
	 * @param string $class_name 服務類別
	 * @return void
	 */
	private static function register_provider_hooks( string $id, string $class_name ): void {
		if (!\class_exists( $class_name )) {
			return;
		}
		/** @var callable $callable */
		$callable = [ $class_name, 'instance' ];
		/** @var \J7\PowerCheckout\Shared\Abstracts\BaseService $instance */
		$instance                        = \call_user_func( $callable );
		ProviderUtils::$container[ $id ] = $instance;
	}

	/**
	 * 加入各物流 WC_Shipping_Method（classic 結帳運送方式）
	 *
	 * @param array<int|string, string> $methods 運送方式
	 * @return array<int|string, string>
	 */
	public static function add_shipping_method( array $methods ): array {
		$methods[ WC_EcpayLogisticsShipping::METHOD_ID ]   = WC_EcpayLogisticsShipping::class;
		$methods[ WC_PayuniLogisticsShipping::METHOD_ID ]  = WC_PayuniLogisticsShipping::class;
		$methods[ WC_PaynowLogisticsShipping::METHOD_ID ]  = WC_PaynowLogisticsShipping::class;
		$methods[ WC_TcatLogisticsShipping::METHOD_ID ]    = WC_TcatLogisticsShipping::class;
		$methods[ WC_HctLogisticsShipping::METHOD_ID ]     = WC_HctLogisticsShipping::class;
		$methods[ WC_SfExpressShipping::METHOD_ID ]        = WC_SfExpressShipping::class;
		$methods[ WC_KerrytjLogisticsShipping::METHOD_ID ] = WC_KerrytjLogisticsShipping::class;
		return $methods;
	}

	/**
	 * Classic 結帳：寫入物流子類型 / 付款情境 meta（委派各運送方式）
	 *
	 * 兩運送方式各自以 is_chosen() 把關，只有被選用的那個會寫入 meta。
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $data  結帳送出資料
	 * @return void
	 */
	public static function save_checkout_meta( \WC_Order $order, array $data = [] ): void {
		WC_EcpayLogisticsShipping::save_checkout_meta( $order, $data );
		WC_PayuniLogisticsShipping::save_checkout_meta( $order, $data );
		WC_PaynowLogisticsShipping::save_checkout_meta( $order, $data );
		WC_TcatLogisticsShipping::save_checkout_meta( $order, $data );
		WC_HctLogisticsShipping::save_checkout_meta( $order, $data );
		WC_SfExpressShipping::save_checkout_meta( $order, $data );
		WC_KerrytjLogisticsShipping::save_checkout_meta( $order, $data );
	}

	/**
	 * 取得已註冊 provider 的類別（不論是否啟用；ping 等 admin 工具用）
	 *
	 * @param string $id provider id
	 * @return string|null 未註冊回 null
	 */
	public static function get_provider_class( string $id ): ?string {
		$class_name = self::$logistics_providers[ $id ] ?? null;
		return null !== $class_name && \class_exists( $class_name ) ? $class_name : null;
	}

	/**
	 * 取得已啟用的物流 provider 實例（容器優先；容器沒有時以類別單例補上，例如請求中途才啟用）
	 *
	 * @param string $id provider id
	 * @return \J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider|null 未啟用 / 不存在回 null
	 */
	public static function resolve_enabled_provider( string $id ): ?\J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider {
		if (!ProviderUtils::is_enabled( $id )) {
			return null;
		}
		$provider = ProviderUtils::get_provider( $id );
		if ($provider instanceof \J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider) {
			return $provider;
		}
		$class_name = self::get_provider_class( $id );
		if (null === $class_name || !\method_exists( $class_name, 'instance' )) {
			return null;
		}
		$instance = $class_name::instance();
		return $instance instanceof \J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider ? $instance : null;
	}

	/** @return array<int, string> 已註冊的物流 provider id（依註冊順序） */
	public static function get_provider_ids(): array {
		return \array_keys( self::$logistics_providers );
	}

	/** @return BaseSettingsDTO[] 取得 provider 設定 dtos（給 SettingApiService GET /settings） */
	public static function get_registered_provider_dtos(): array {
		return \array_map(
			static fn( string $class_name ): BaseSettingsDTO => BaseSettingsDTO::create( $class_name ),
			self::$logistics_providers
		);
	}
}
