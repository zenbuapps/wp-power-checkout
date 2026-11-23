<?php
/**
 * 順豐速運（SF Express，順豐國際開放平台 IUOP）物流服務提供者 `sf_express`
 *
 * 台灣寄件 → 台灣收件宅配（無超商門市地圖）：
 *  - create_shipment = IUOP_CREATE_ORDER：ref = 順豐運單號 sfWaybillNo（軌跡推送以此找單）；已有 ref → 冪等回既有；
 *                      123052（客戶訂單號重複，可能是上次逾時其實已成功）→ IUOP_QUERY_ORDER 反查，收件手機相符才採用（fail-closed）
 *  - print_document  = IUOP_PRINT_ORDER 取面單網址 → 伺服器端下載 PDF 回傳（面單網址免認證且含個資，不落庫、不回傳前端）
 *  - query_shipment  = IUOP_QUERY_TRACK（customerCode + 運單號，不需手機後四碼）→ 最新認得的 opCode → ShippingState
 *  - cancel_shipment = IUOP_CANCEL_ORDER（順豐收件前）；本地已知收件（accepted 以後）→ CONFLICT 不打 API；
 *                      成功後清 ref（同一訂單可重建；順豐會把已取消單改名 `{原單號}_CANCEL_{時間戳}`）
 *  - handle_status_callback = 軌跡推送（驗 sign → 依時間套用 → 順豐規定格式 ack，HTTP 一律 200）
 *  - create_return / 選店 → UNSUPPORTED；test_connection = 取 token + 加解密 + 簽章 + IUOP_QUERY_TRACK 查不存在運單（唯讀）
 *
 * ⚠️ 台灣島內件（TW→TW）正式環境可否下單、該用哪個產品，需先向順豐台灣客戶經理確認（R12；設定頁警示）。
 * 失敗一律 throw SfExpressException（帶 ErrorCode + 順豐原始碼），建單 / 取消失敗另寫 order note。
 *
 * @see .claude/skills/sf-express/SKILL.md
 * @see zenbu apps/api-gateway/src/commerce/shipping/sf-express/sf-express-logistics.service.ts
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Services;

use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\LogisticsProviderMetaDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\ShipmentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\DeliveryMethod;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ISupportsConnectionTest;
use J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs\CreateOrderParams;
use J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs\SfExpressSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Http\SfApiClient;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Http\SfTrackPushCallback;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Managers\TrackPushManager;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Managers\TrackStatusManager;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions\SfExpressException;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfExpressMetaKeys;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfOpcodeMapper;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 順豐速運服務提供者 */
final class SfExpressProvider extends BaseService implements ILogisticsProvider, ISupportsConnectionTest {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Provider ID（對應 woocommerce_sf_express_settings option） */
	public const ID = 'sf_express';

	/** @var string ping 查詢用的運單號（不存在，唯讀） */
	private const PING_WAYBILL = 'SF0000000000000';

	/** @var int 面單下載逾時秒數 */
	private const DOWNLOAD_TIMEOUT = 30;

	/** Constructor */
	public function __construct() {
		$settings                 = SfExpressSettingsDTO::instance();
		$this->id                 = self::ID;
		$this->enabled            = $settings->enabled;
		$this->method_title       = \__( '順豐速運', 'power_checkout' );
		$this->method_description = \__( '順豐國際開放平台（IUOP）直串：宅配建單、列印面單、查詢軌跡、取消託運單與軌跡推送。', 'power_checkout' );
	}

	/**
	 * 取得設定
	 *
	 * @param bool $with_default 是否帶預設值（false = 只拿 DB 值）
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			$option = ProviderUtils::get_option( self::ID );
			/** @var array<string, mixed> $result */
			$result = \is_array( $option ) ? $option : [];
			return $result;
		}

		return \array_merge(
			SfExpressSettingsDTO::instance()->to_array(),
			[
				'id'                 => self::ID,
				'icon'               => Plugin::$url . '/inc/assets/images/icons/sf_express.png',
				'method_title'       => \__( '順豐速運', 'power_checkout' ),
				'method_description' => \__( '順豐國際開放平台直串，宅配到府、列印面單、查詢軌跡。', 'power_checkout' ),
				'track_push_url'     => SfTrackPushCallback::url(),
			]
		);
	}

	/** @return LogisticsProviderMetaDTO 宅配 only；選店 / 退貨不支援 */
	public function get_provider_meta(): LogisticsProviderMetaDTO {
		return new LogisticsProviderMetaDTO(
			id: self::ID,
			supported_delivery_methods: [ DeliveryMethod::HOME ],
			capabilities: new ShipmentCapabilitiesDTO(
				can_create_shipment: true,
				can_query_shipment: true,
				has_cvs_map: false,
				can_print: true,
				can_return: false,
				can_cancel: true,
			),
		);
	}

	/** @return array<int, string> 結帳可選子類型（只有宅配） */
	public function get_supported_methods(): array {
		return [ DeliveryMethod::HOME->value ];
	}

	// region 不支援的操作

	/**
	 * 選店（不支援：順豐宅配）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 * @return array<string, mixed>
	 * @throws SfExpressException UNSUPPORTED
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array {
		throw SfExpressException::unsupported( '超商選店' );
	}

	/**
	 * 解析選店回呼（不支援）
	 *
	 * @param array<string, mixed> $raw 原始資料
	 * @return array<string, mixed>
	 * @throws SfExpressException UNSUPPORTED
	 */
	public function parse_store_selection( array $raw ): array {
		throw SfExpressException::unsupported( '超商選店' );
	}

	/**
	 * 建立退貨單（不支援：IUOP 無逆物流接口，退貨請站主另行建單或洽順豐）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 * @return array<string, mixed>
	 * @throws SfExpressException UNSUPPORTED
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array {
		throw SfExpressException::unsupported( '建立退貨單' );
	}

	// endregion

	// region create_shipment

	/**
	 * 建立順豐運單（IUOP_CREATE_ORDER）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id（冪等時另含 idempotent=true；採用重複單時另含 adopted=true）
	 * @throws SfExpressException 驗證 / API 失敗
	 */
	public function create_shipment( \WC_Order $order ): array {
		$meta = new SfExpressMetaKeys( $order );
		if ('' !== $meta->get_ref()) {
			return [
				'logistics_id' => $meta->get_ref(),
				'idempotent'   => true,
			];
		}

		$settings = SfExpressSettingsDTO::instance();
		$params   = CreateOrderParams::from_order( $order, $settings );
		$client   = new SfApiClient( $settings );
		$adopted  = false;

		try {
			try {
				$result  = $client->dispatch( 'IUOP_CREATE_ORDER', $params->body );
				$waybill = self::read_string( $result['data'] ?? null, 'sfWaybillNo' );
			} catch ( SfExpressException $e ) {
				if (SfExpressException::DUPLICATE_ORDER_CODE !== $e->raw_code) {
					throw $e;
				}
				$waybill = $this->adopt_duplicate( $client, $settings, $params );
				$adopted = true;
			}

			if ('' === $waybill) {
				throw new SfExpressException( '順豐建單回應缺少運單號（sfWaybillNo），無法確認是否真的建單成功，請至順豐後台確認' );
			}
		} catch ( SfExpressException $e ) {
			$order->add_order_note( \sprintf( '❌ 順豐建單失敗：%s', $e->getMessage() ) );
			throw $e;
		}

		$customer_order_no = (string) ( $params->body['customerOrderNo'] ?? '' );
		$meta->save_shipment( $waybill, $customer_order_no, ShippingState::PENDING->value );
		$order->add_order_note(
			$adopted
				? \sprintf( '順豐回報客戶訂單號 %1$s 重複（123052），反查收件手機相符，採用既有運單：%2$s', $customer_order_no, $waybill )
				: \sprintf( '順豐運單已建立：%s', $waybill )
		);

		return \array_filter(
			[
				'logistics_id' => $waybill,
				'adopted'      => $adopted,
			]
		);
	}

	/**
	 * 123052：以客戶訂單號反查，收件手機（正規化後）全等才採用；其餘一律 CONFLICT（fail-closed）
	 *
	 * 123052 只證明「順豐端有一張同編號的單」，不證明那張是我們的（站主可能在別的系統用同一帳號、同一套編號）。
	 *
	 * @param SfApiClient          $client   client
	 * @param SfExpressSettingsDTO $settings 設定
	 * @param CreateOrderParams    $params   建單參數
	 * @return string 採用的運單號
	 * @throws SfExpressException CONFLICT 不採用 / 查詢失敗
	 */
	private function adopt_duplicate( SfApiClient $client, SfExpressSettingsDTO $settings, CreateOrderParams $params ): string {
		$customer_order_no = (string) ( $params->body['customerOrderNo'] ?? '' );
		$result            = $client->dispatch(
			'IUOP_QUERY_ORDER',
			[
				'customerCode'    => $settings->customer_code,
				'customerOrderNo' => $customer_order_no,
			]
		);
		$data              = \is_array( $result['data'] ?? null ) ? $result['data'] : [];
		$waybill           = self::read_string( $data, 'sfWaybillNo' );
		$queried_phone     = self::read_string( $data['receiverInfo'] ?? null, 'phoneNo' );
		$suffix            = '' !== $waybill ? "（運單 {$waybill}）" : '';

		$reason = match ( true ) {
			'' === $queried_phone => '查不到該訂單的收件資料',
			\str_contains( $queried_phone, '*' ) => '收件手機被遮罩無法比對',
			'' === $waybill => '查詢結果缺少運單號',
			CreateOrderParams::normalize_phone( $queried_phone ) !== $params->receiver_phone => '收件人與本訂單不符',
			default => '',
		};
		if ('' !== $reason) {
			throw SfExpressException::conflict( "順豐端已有客戶訂單號 {$customer_order_no} 的訂單{$suffix}，但{$reason}，未自動採用，請至順豐後台確認" );
		}

		Plugin::logger(
			'順豐 123052 重複單號，反查收件手機相符，採用既有運單',
			'warning',
			[
				'customer_order_no' => $customer_order_no,
				'sf_waybill_no'     => $waybill,
			]
		);
		return $waybill;
	}

	// endregion

	// region print_document

	/**
	 * 列印面單（IUOP_PRINT_ORDER → 下載 PDF；每次向順豐重取，網址不落庫）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string PDF 二進位（無法下載 PDF 時退回含連結的 HTML）
	 * @throws SfExpressException CONFLICT 尚未建單 / PROVIDER 網址異常 / API 失敗
	 */
	public function print_document( \WC_Order $order ): string {
		$ref = ( new SfExpressMetaKeys( $order ) )->get_ref();
		if ('' === $ref) {
			throw SfExpressException::conflict( '尚未成立順豐運單，無法列印面單' );
		}

		$settings = SfExpressSettingsDTO::instance();
		$result   = ( new SfApiClient( $settings ) )->dispatch(
			'IUOP_PRINT_ORDER',
			[
				'customerCode'          => $settings->customer_code,
				'requestId'             => \wp_generate_uuid4(),
				'printType'             => '1',
				'labelModeSize'         => 1,
				'fileFormat'            => 'PDF',
				'resolution'            => '203dpi',
				'printWaybillNoDtoList' => [
					[
						'sfWaybillNo'      => $ref,
						'isPrintSubParent' => 1,
					],
				],
			]
		);

		$url = self::read_string( $result['data'] ?? null, 'url' );
		if (1 !== \preg_match( '#^https?://#i', $url )) {
			throw new SfExpressException( '順豐面單回應格式異常：缺少可用的面單網址' );
		}

		$response = \wp_remote_get( $url, [ 'timeout' => self::DOWNLOAD_TIMEOUT ] );
		$body     = \is_wp_error( $response ) ? '' : (string) \wp_remote_retrieve_body( $response );
		if (\str_starts_with( $body, '%PDF' )) {
			return $body;
		}

		Plugin::logger( '順豐面單無法下載為 PDF，改回連結頁', 'warning', [ 'order_id' => $order->get_id() ] );
		$href  = \esc_url( $url );
		$title = \esc_html( "順豐面單 {$ref}" );
		return <<<HTML
<!doctype html>
<html lang="zh-Hant-TW">
<head><meta charset="utf-8"><title>{$title}</title></head>
<body><p><a href="{$href}" target="_blank" rel="noopener noreferrer">{$title}（開啟順豐面單）</a></p></body>
</html>
HTML;
	}

	// endregion

	// region query_shipment

	/**
	 * 查詢軌跡（IUOP_QUERY_TRACK）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id / status / status_name / unified_state / status_time / history / cached
	 * @throws SfExpressException CONFLICT 尚未建單 / API 失敗
	 */
	public function query_shipment( \WC_Order $order ): array {
		$meta = new SfExpressMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw SfExpressException::conflict( '尚未成立順豐運單，無法查詢軌跡' );
		}

		$settings = SfExpressSettingsDTO::instance();
		$result   = ( new SfApiClient( $settings ) )->dispatch(
			'IUOP_QUERY_TRACK',
			[
				'customerCode' => $settings->customer_code,
				'sfWaybillNo'  => $ref,
			]
		);

		$info    = self::find_track_info( $result['data'] ?? null, $ref );
		$history = \is_array( $info['trackDetailItems'] ?? null ) ? \array_values( \array_filter( $info['trackDetailItems'], 'is_array' ) ) : [];
		/** @var array<int, array<string, mixed>> $history */
		$latest = SfOpcodeMapper::pick_latest( $history );
		if (null !== $latest) {
			TrackStatusManager::apply( $order, $latest, '查詢' );
		}

		$status = $meta->get_status();
		if ('' === $status['unified_state']) {
			$status['unified_state'] = ShippingState::PENDING->value;
		}
		if (null === $latest && '' === $status['status']) {
			$summary               = self::read_string( $info, 'trackSummary' );
			$status['status_name'] = '' !== $summary ? "尚無軌跡（{$summary}）" : '尚無軌跡（順豐尚未收件）';
		}

		return \array_merge(
			[ 'logistics_id' => $ref ],
			$status,
			[
				'history' => $history,
				'cached'  => false,
			]
		);
	}

	/**
	 * 從查詢回應取本運單的 TrackInfo
	 *
	 * @param mixed  $data 回應 data
	 * @param string $ref  運單號
	 * @return array<string, mixed>
	 */
	private static function find_track_info( mixed $data, string $ref ): array {
		$list = \is_array( $data ) ? \array_values( \array_filter( $data, 'is_array' ) ) : [];
		foreach ( $list as $info ) {
			if (self::read_string( $info, 'sfWaybillNo' ) === $ref) {
				/** @var array<string, mixed> $info */
				return $info;
			}
		}
		/** @var array<string, mixed> $first */
		$first = $list[0] ?? [];
		return $first;
	}

	// endregion

	// region cancel_shipment

	/**
	 * 取消順豐運單（IUOP_CANCEL_ORDER；先打順豐、成功才動本地）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> logistics_id / cancelled
	 * @throws SfExpressException CONFLICT 尚未建單 / 已收件；API 失敗
	 */
	public function cancel_shipment( \WC_Order $order ): array {
		$meta = new SfExpressMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === $ref) {
			throw SfExpressException::conflict( '尚未成立順豐運單，無法取消' );
		}
		$state = ShippingState::tryFrom( $meta->get_unified_state() );
		if (null !== $state && ShippingState::PENDING !== $state) {
			throw SfExpressException::conflict( '順豐已收件，無法取消託運單' );
		}

		$settings = SfExpressSettingsDTO::instance();
		try {
			( new SfApiClient( $settings ) )->dispatch(
				'IUOP_CANCEL_ORDER',
				[
					'customerCode' => $settings->customer_code,
					'sfWaybillNo'  => $ref,
				]
			);
		} catch ( SfExpressException $e ) {
			$order->add_order_note( \sprintf( '❌ 順豐取消託運單失敗：%s', $e->getMessage() ) );
			throw $e;
		}

		$meta->save_cancelled( ShippingState::CANCELLED->value, \wp_date( 'Y-m-d H:i:s' ) ?: '' );
		$order->add_order_note( \sprintf( '順豐託運單已取消：%s（可重新建單）', $ref ) );

		return [
			'logistics_id' => $ref,
			'cancelled'    => true,
		];
	}

	// endregion

	// region status callback / test_connection

	/**
	 * 軌跡推送（驗 sign → 依時間套用 → 順豐規定格式 ack；HTTP 一律 200，含例外）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$sign = $request->get_header( 'sign' );
			$ack  = TrackPushManager::process( $request->get_body(), \is_string( $sign ) ? $sign : '' );
		} catch ( \Throwable $e ) {
			Plugin::logger( '順豐軌跡推送處理例外', 'error', [ 'error' => $e->getMessage() ] );
			$ack = [
				'code' => '1',
				'msg'  => '系統暫時無法處理',
				'data' => null,
			];
		}
		return new \WP_REST_Response( $ack, 200 );
	}

	/**
	 * 測試連線：取 token → 加密 → 簽章 → IUOP_QUERY_TRACK 查一張不存在的運單 → 解密（唯讀）
	 *
	 * 客戶編碼無法以唯讀接口驗證（2026-11-21 實測：錯的 customerCode 回相同結果）→ customer_code_verified=false。
	 *
	 * @return array<string, mixed> provider / mode / endpoint / customer_code_verified / push_key_configured / track_push_url
	 * @throws SfExpressException 憑證缺漏 / 驗證失敗 / API 失敗
	 */
	public function test_connection(): array {
		$settings = SfExpressSettingsDTO::instance();
		$missing  = $settings->missing_api_credentials();
		if ([] !== $missing) {
			throw SfExpressException::validation( '請先填寫並儲存：' . \implode( '、', $missing ) );
		}

		$result = ( new SfApiClient( $settings ) )->dispatch(
			'IUOP_QUERY_TRACK',
			[
				'customerCode' => $settings->customer_code,
				'sfWaybillNo'  => self::PING_WAYBILL,
			]
		);
		if (true !== ( $result['success'] ?? null )) {
			throw new SfExpressException( '順豐測試連線失敗：回應格式異常', ErrorCode::PROVIDER );
		}

		return [
			'provider'               => self::ID,
			'mode'                   => $settings->mode,
			'endpoint'               => $settings->base_url(),
			'customer_code_verified' => false,
			'push_key_configured'    => $settings->has_push_key(),
			'track_push_url'         => SfTrackPushCallback::url(),
		];
	}

	// endregion

	/**
	 * 從外部 JSON 讀字串欄（數字也收）
	 *
	 * @param mixed  $value 物件
	 * @param string $key   欄位
	 * @return string
	 */
	private static function read_string( mixed $value, string $key ): string {
		if (!\is_array( $value )) {
			return '';
		}
		$field = $value[ $key ] ?? null;
		return \is_string( $field ) || \is_int( $field ) || \is_float( $field ) ? \trim( (string) $field ) : '';
	}
}
