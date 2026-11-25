<?php
/**
 * PAYUNi 統一金流物流服務提供者
 *
 * 統一抽象 ILogisticsProvider 的 PAYUNi 實作（與綠界 EcpayLogisticsProvider 並存可切換）。
 *
 * ⚠️ 與綠界三段暫存單（get_store_selection 暫存 → ClientReplyURL → CreateByTempTrade）不同，
 *    PAYUNi 為 **ship_map 單段選店**：
 *      get_store_selection → ship_map 門市地圖（無暫存單）
 *      parse_store_selection → 解 MapJson 門市資訊（無 TempLogisticsID）
 *      create_shipment → 商店一次組齊「門市 + 完整收件人」呼叫 trade 建單
 *    收件人於建單階段才由商店組裝，選店階段只取回門市。統一抽象把選店狀態存 order meta，
 *    故兩種流程（綠界三段 / PAYUNi 單段）皆落在同一介面簽章，不需改介面。
 *
 * 加密：AES-256-GCM + SHA256 HashInfo（{@see PayuniCrypto}，與綠界 AES-128-CBC 不同）。
 *
 * @see .claude/skills/payuni-logistics-v3/references/cvs-apis.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\Services;

use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\HomeTradeParams;
use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\ShipMapParams;
use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\TradeParams;
use J7\PowerCheckout\Domains\Logistics\Payuni\Http\PayuniLogisticsApiClient;
use J7\PowerCheckout\Domains\Logistics\Payuni\Http\PayuniLogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums\PayuniLgsType;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums\PayuniShipStatus;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums\PayuniSubType;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsPaymentScenario;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\LogisticsProviderMetaDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\ShipmentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\DeliveryMethod;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** PAYUNi 統一金流物流服務提供者 */
final class PayuniLogisticsProvider extends BaseService implements ILogisticsProvider {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const ID = 'payuni_logistics';

	/** @var int 一天秒數（黑貓出貨 / 配達 / 取件日期計算） */
	private const ONE_DAY = 86400;

	/**
	 * 記錄 log（info / error / warning 同步記錄到 order note）
	 *
	 * @param string               $message     訊息
	 * @param string               $level       等級
	 * @param array<string, mixed> $args        附加資訊
	 * @param int                  $trace_limit 追蹤堆疊層數
	 * @param \WC_Order|null       $order       是否紀錄在 order note
	 */
	public static function logger( string $message, string $level = 'debug', array $args = [], int $trace_limit = 0, \WC_Order|null $order = null ): void {
		\J7\WpUtils\Classes\WC::logger( $message, $level, $args, 'power_checkout_' . self::ID, $trace_limit );
		if (!$order) {
			return;
		}

		if ($args) {
			$message .= "<p style='margin-bottom: 0;'>&nbsp;</p>";
		}

		$order_note = \J7\WpUtils\Classes\WP::array_to_html( $args, [ 'title' => $message ] );
		$order->add_order_note( $order_note );
	}

	/**
	 * 取得設定
	 *
	 * @param bool $with_default 是否帶預設值（false = 只拿 DB 值）
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			$option = ProviderUtils::get_option( self::ID );
			/** @var array<string, mixed> $option */
			$option = \is_array( $option ) ? $option : [];
			return $option;
		}
		return PayuniLogisticsSettingsDTO::instance()->to_array();
	}

	/**
	 * 階段 A：取得門市選擇導轉資訊（ship_map 單段選店）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文（sub_type / payment_scenario）
	 *
	 * @return array<string, mixed> 含 redirect_target（ship_map auto-submit form HTML）
	 *
	 * @throws \Exception 前置驗證失敗
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array {
		$settings = PayuniLogisticsSettingsDTO::instance();
		$sub_type = (string) ( $ctx['sub_type'] ?? '' );

		// 前置驗證 1：provider 必須啟用
		if (!ProviderUtils::is_enabled( self::ID )) {
			throw new \Exception( \__( 'PAYUNi 統一金流物流未啟用', 'power_checkout' ) );
		}

		// 前置驗證 2：訂單必須存在
		$this->assert_order_exists( $order );

		// 前置驗證 3：MapReturnURL 不可為 localhost（須公開可訪問）
		$this->assert_public_reply_url( $settings->map_return_url );

		// 前置驗證 4：sub_type 必須為已啟用的 PAYUNi 物流子類型
		if (!\in_array( $sub_type, $this->get_supported_methods(), true )) {
			throw new \Exception(
				\__( '運送方式必須為已啟用的 PAYUNi 物流子類型', 'power_checkout' )
			);
		}

		// 前置驗證 5：宅配（HOME）無需 ship_map 選店
		if (PayuniSubType::SEVEN->value !== $sub_type) {
			throw new \Exception(
				\__( '僅 7-ELEVEN 超商取貨需要門市選擇', 'power_checkout' )
			);
		}

		$goods_type = $this->get_goods_type( $order );

		$data = [
			// MerKeyNo 帶訂單 ID，回呼時辨識（門市綁定另以 MapReturnURL query 的 pc_oid/pc_key 把關）
			'MerKeyNo'     => (string) $order->get_id(),
			'GoodsType'    => $goods_type,
			'LgsType'      => $settings->cvs_lgs_type,
			'ShipType'     => PayuniSubType::SEVEN->ship_type(),
			// GoodsType=2（冷凍）時 MapType 固定 2（含離島）
			'MapType'      => 2 === $goods_type ? 2 : 1,
			// 防 IDOR：MapReturnURL 編入 pc_oid + pc_key，回呼時 timing-safe 驗證
			'MapReturnURL' => PayuniLogisticsCallback::build_map_return_url( $settings->map_return_url, $order ),
			// Tag=2 回傳選取的門市資訊（不是超商代號）
			'Tag'          => 2,
			'MobileTag'    => 'N',
		];

		$params = ShipMapParams::parse( $data );
		$html   = ( new PayuniLogisticsApiClient( $order ) )->ship_map( $params );

		return [ 'redirect_target' => $html ];
	}

	/**
	 * 解析門市選店回呼（ship_map MapJson；無 TempLogisticsID）
	 *
	 * @param array<string, mixed> $raw PAYUNi Form POST 原始資料（4 欄位）
	 *
	 * @return array<string, mixed> 門市資訊（temp_id 為空，PAYUNi 無暫存單概念）
	 *
	 * @throws \Exception 驗簽失敗 / 解密失敗 / MapJson 解析失敗
	 */
	public function parse_store_selection( array $raw ): array {
		$encrypt_info  = (string) ( $raw['EncryptInfo'] ?? '' );
		$received_hash = (string) ( $raw['HashInfo'] ?? '' );

		if ('' === \trim( $encrypt_info )) {
			throw new \Exception( \__( '選店結果為空', 'power_checkout' ) );
		}

		$settings = PayuniLogisticsSettingsDTO::instance();
		$crypto   = new PayuniCrypto( $settings->hash_key, $settings->hash_iv );

		// HashInfo timing-safe 驗簽
		if (!$crypto->verify_hash( $encrypt_info, $received_hash )) {
			throw new \Exception( \__( 'PAYUNi 門市回呼 HashInfo 驗簽失敗', 'power_checkout' ) );
		}

		$decrypted = $crypto->decrypt( $encrypt_info );

		// MapJson 為 JSON 字串，需再 decode（payuni-logistics-v3 §Check 13）
		$map_json_raw = (string) ( $decrypted['MapJson'] ?? '' );
		$map          = \json_decode( $map_json_raw, true );
		if (!\is_array( $map )) {
			throw new \Exception( \__( 'PAYUNi 門市 MapJson 解析失敗', 'power_checkout' ) );
		}

		return [
			// PAYUNi 無暫存物流單，temp_id 恆為空（統一抽象相容欄位）
			'temp_id'    => '',
			'store_id'   => (string) ( $map['StoreID'] ?? '' ),
			'store_name' => (string) ( $map['StoreName'] ?? '' ),
			'store_addr' => (string) ( $map['Address'] ?? '' ),
			'sub_type'   => PayuniSubType::SEVEN->value,
		];
	}

	/**
	 * 階段 B：成立物流單（trade；商店組完整收件人，無需暫存單）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed> 含 logistics_id（= ShipTradeNo）
	 *
	 * @throws \Exception 前置驗證 / 傳輸層 / 業務層失敗
	 */
	public function create_shipment( \WC_Order $order ): array {
		$settings = PayuniLogisticsSettingsDTO::instance();
		$meta     = new LogisticsMetaKeys( $order );

		// 冪等：已成立物流單 → 直接回傳既有單號，不重複建單
		$existing_ref = $meta->get_ref();
		if ('' !== \trim( $existing_ref )) {
			return [ 'logistics_id' => $existing_ref ];
		}

		if (PayuniSubType::HOME->value === $meta->get_sub_type()) {
			return $this->create_home_shipment( $order, $meta, $settings );
		}

		$store_id = $meta->get_store_id();

		// 前置驗證：超商取貨須先完成選店（有 StoreID）
		if ('' === \trim( $store_id )) {
			throw new \Exception( \__( '尚未選店，無取件門市代碼', 'power_checkout' ) );
		}

		$scenario      = $meta->get_payment_scenario() ?: LogisticsPaymentScenario::ONLINE->value;
		$is_collection = LogisticsPaymentScenario::COD->value === $scenario;
		$total         = (int) \round( (float) $order->get_total() );

		$data = [
			'MerTradeNo'      => $this->build_mer_trade_no( $order ),
			'GoodsType'       => $this->get_goods_type( $order ),
			'LgsType'         => $settings->cvs_lgs_type,
			'ShipType'        => PayuniSubType::SEVEN->ship_type(),
			'TradeAmt'        => $total,
			// ServiceType：COD 取貨付款 → 1；線上付款 → 取貨不付款 3
			'ServiceType'     => $is_collection ? 1 : 3,
			'StoreID'         => $store_id,
			'Consignee'       => $this->get_consignee( $order ),
			'ConsigneeMobile' => $this->get_consignee_mobile( $order ),
		];

		// C2C 才帶退貨寄件人資訊
		if (PayuniLgsType::C2C->value === $settings->cvs_lgs_type) {
			$data['SenderName']   = $settings->sender_name ?: '寄件人';
			$data['SenderMobile'] = $settings->sender_mobile;
		}

		// 取貨付款才帶 NotifyURL（ServiceType=1 才觸發取件付款完成 Notify）
		if ($is_collection && '' !== $settings->notify_url) {
			$data['NotifyURL'] = $settings->notify_url;
		}

		$params    = TradeParams::parse( $data );
		$decrypted = ( new PayuniLogisticsApiClient( $order ) )->trade( $params );

		$ship_trade_no = (string) ( $decrypted['ShipTradeNo'] ?? '' );
		if ('' === $ship_trade_no) {
			throw new \Exception( \__( 'PAYUNi 未回傳 ShipTradeNo，成立物流單失敗', 'power_checkout' ) );
		}

		// 寫入統一物流單號（下游主鍵）
		$meta->update_ref( $ship_trade_no );
		$meta->update_provider_id( self::ID );

		self::logger(
			\sprintf( '✅ PAYUNi 成立物流單成功，ShipTradeNo：%s', $ship_trade_no ),
			'info',
			[ 'ship_trade_no' => $ship_trade_no ],
			0,
			$order
		);

		return [ 'logistics_id' => $ship_trade_no ];
	}

	/**
	 * 成立黑貓宅配單（home_delivery/trade Ver 1.2；不需選店，收件地址取訂單）
	 *
	 * @param \WC_Order                  $order    訂單
	 * @param LogisticsMetaKeys          $meta     meta 存取器
	 * @param PayuniLogisticsSettingsDTO $settings 設定
	 *
	 * @return array<string, mixed> 含 logistics_id（= ShipTradeNo）
	 *
	 * @throws \Exception 前置驗證 / 傳輸層 / 業務層失敗
	 */
	private function create_home_shipment( \WC_Order $order, LogisticsMetaKeys $meta, PayuniLogisticsSettingsDTO $settings ): array {
		$address = $this->get_home_address( $order );
		if ('' === $address) {
			throw new \Exception( \__( '訂單沒有收件地址，無法建立黑貓宅配單', 'power_checkout' ) );
		}

		$total = (int) \round( (float) $order->get_total() );
		if ($total < HomeTradeParams::MIN_AMOUNT || $total > HomeTradeParams::MAX_AMOUNT) {
			throw new \Exception(
				\sprintf(
					/* translators: 1: 下限, 2: 上限, 3: 訂單金額 */
					\__( 'PAYUNi 黑貓宅配的訂單金額須介於 %1$d ~ %2$d 元，這筆訂單是 %3$d 元', 'power_checkout' ),
					HomeTradeParams::MIN_AMOUNT,
					HomeTradeParams::MAX_AMOUNT,
					$total
				)
			);
		}

		$scenario      = $meta->get_payment_scenario() ?: LogisticsPaymentScenario::ONLINE->value;
		$is_collection = LogisticsPaymentScenario::COD->value === $scenario;

		$time_tag = \in_array( $settings->home_delivery_time_tag, HomeTradeParams::DELIVERY_TIME_TAGS, true )
		? $settings->home_delivery_time_tag
		: '04';

		$data = [
			'MerTradeNo'       => $this->build_mer_trade_no( $order ),
			'GoodsType'        => $this->get_goods_type( $order ),
			'TradeAmt'         => $total,
			// ServiceType：COD 取貨付款 → 1；線上付款 → 取貨不付款 3
			'ServiceType'      => $is_collection ? 1 : 3,
			'DeliveryTimeTag'  => $time_tag,
			'Consignee'        => HomeTradeParams::truncate_width( $this->get_consignee_full( $order ), 30 ),
			'ConsigneeMobile'  => $this->get_consignee_mobile( $order ),
			'ConsigneeAddress' => HomeTradeParams::truncate_width( $address, 120 ),
			'ProdDesc'         => HomeTradeParams::truncate_width( $this->get_prod_desc( $order ), 20 ),
		];

		// 取貨付款才帶 NotifyURL（ServiceType=1 才觸發取件付款完成 Notify）
		if ($is_collection && '' !== $settings->notify_url) {
			$data['NotifyURL'] = $settings->notify_url;
		}

		$decrypted = ( new PayuniLogisticsApiClient( $order ) )->home_trade( HomeTradeParams::parse( $data ) );

		$ship_trade_no = (string) ( $decrypted['ShipTradeNo'] ?? '' );
		if ('' === $ship_trade_no) {
			throw new \Exception( \__( 'PAYUNi 未回傳 ShipTradeNo，黑貓宅配建單失敗', 'power_checkout' ) );
		}

		$meta->update_ref( $ship_trade_no );
		$meta->update_provider_id( self::ID );

		self::logger(
			\sprintf( '✅ PAYUNi 黑貓宅配建單成功，ShipTradeNo：%s', $ship_trade_no ),
			'info',
			[ 'ship_trade_no' => $ship_trade_no ],
			0,
			$order
		);

		return [ 'logistics_id' => $ship_trade_no ];
	}

	/**
	 * 查詢物流單（query）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed> 含 logistics_id / status / store_info
	 *
	 * @throws \Exception 前置驗證 / 傳輸層 / 業務層失敗
	 */
	public function query_shipment( \WC_Order $order ): array {
		$meta = new LogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();

		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚未成立物流單', 'power_checkout' ) );
		}

		$lgs_type  = $this->resolve_lgs_type( $meta );
		$decrypted = ( new PayuniLogisticsApiClient( $order ) )->query( $ref, $lgs_type );

		// 剛建單尚無貨態時 PAYUNi 回 "-"
		$status = (string) ( $decrypted['ShipStatus'] ?? '' );
		$status = '-' === $status ? '' : $status;

		return [
			'logistics_id'  => (string) ( $decrypted['ShipTradeNo'] ?? $ref ),
			'status'        => $status,
			'status_desc'   => '-' === (string) ( $decrypted['ShipStatusDesc'] ?? '-' ) ? '' : (string) $decrypted['ShipStatusDesc'],
			'unified_state' => PayuniShipStatus::to_unified_state( $status, PayuniLgsType::HOME->value !== $lgs_type )->value,
			'store_info'    => [
				'store_id'   => (string) ( $decrypted['StoreID'] ?? '' ),
				'store_name' => (string) ( $decrypted['StoreName'] ?? '' ),
			],
		];
	}

	/**
	 * 列印物流單（print_label）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string 列印文件 HTML（auto-submit form）
	 *
	 * @throws \Exception 前置驗證失敗
	 */
	public function print_document( \WC_Order $order ): string {
		$meta = new LogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();

		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚未成立物流單', 'power_checkout' ) );
		}

		$settings = PayuniLogisticsSettingsDTO::instance();

		// 黑貓宅配：產宅配編號並下載託運單 PDF（出貨日須大於今日、配達日須大於出貨日，皆不可為週日）
		if (PayuniSubType::HOME->value === $meta->get_sub_type()) {
			$ship_ts     = self::next_working_day( self::taipei_now() );
			$delivery_ts = self::next_working_day( $ship_ts );
			$goods_type  = $this->get_goods_type( $order );
			$spec        = \max( 1, \min( 4, (int) $settings->home_spec ) );
			// 冷凍 / 冷藏不支援 150cm
			if (1 !== $goods_type && 4 === $spec) {
				$spec = 3;
			}

			return ( new PayuniLogisticsApiClient( $order ) )->home_print(
				[ $ref ],
				$goods_type,
				\gmdate( 'Ymd', $ship_ts ),
				\gmdate( 'Ymd', $delivery_ts ),
				$spec
			);
		}

		// B2C 出貨日期不得為當日，取台灣時間的明天（台灣 00:00~08:00 時 UTC 還是前一天，不能直接用 time()）
		$ship_date = \gmdate( 'Ymd', self::taipei_now() + self::ONE_DAY );

		return ( new PayuniLogisticsApiClient( $order ) )->print_label(
			[ $ref ],
			$settings->cvs_lgs_type,
			$this->get_goods_type( $order ),
			$ship_date
		);
	}

	/**
	 * 取消物流單
	 *
	 * ⚠️ PAYUNi 7-ELEVEN 物流**無直接取消 API**（官方只提供 C2B 退貨便 / C2C 待轉宅配等逆物流流程）。
	 * 故統一抽象的 cancel_shipment 在 PAYUNi 一律拋出明確訊息，引導改用 create_return（退貨便）。
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Exception PAYUNi 不支援直接取消
	 */
	public function cancel_shipment( \WC_Order $order ): array {
		throw new \Exception(
			\__( 'PAYUNi 物流不支援直接取消物流單，請改用退貨便（create_return）', 'power_checkout' )
		);
	}

	/**
	 * 建立退貨單（C2B 退貨便要號）
	 *
	 * 前置驗證：provider 啟用 → 訂單存在 → 已成立正向物流單（有 _pc_logistics_ref）→
	 * 呼叫 /api/logistics/refund 取得 12 碼退貨便編號（黑貓宅配改走 /api/home_delivery/refund）
	 * （RefundODNO 8 + ValidationNo 4），寫入 _pc_logistics_return_ref。
	 *
	 * ⚠️ C2B 退貨便僅 B2C 大宗寄倉常溫商店可用（GoodsType 固定 1）。
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文（保留擴充）
	 *
	 * @return array<string, mixed> 含 return_logistics_id（RefundODNO）
	 *
	 * @throws \Exception 前置驗證 / 傳輸層 / 業務層失敗
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array {
		// 前置驗證 1：provider 必須啟用
		if (!ProviderUtils::is_enabled( self::ID )) {
			throw new \Exception( \__( 'PAYUNi 統一金流物流未啟用', 'power_checkout' ) );
		}

		// 前置驗證 2：訂單必須存在
		$this->assert_order_exists( $order );

		// 前置驗證 3：須已成立正向物流單
		$meta = new LogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();
		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚未成立物流單，無法退貨', 'power_checkout' ) );
		}

		if (PayuniSubType::HOME->value === $meta->get_sub_type()) {
			return $this->create_home_return( $order, $meta, $ref );
		}

		$total = (int) \round( (float) $order->get_total() );

		// 退貨便：退貨不付款（ServiceType=5）
		$decrypted = ( new PayuniLogisticsApiClient( $order ) )->refund( $ref, $total, 5 );

		$return_logistics_id = (string) ( $decrypted['RefundODNO'] ?? '' );
		if ('' === $return_logistics_id) {
			throw new \Exception( \__( 'PAYUNi 未回傳退貨便編號，建立退貨單失敗', 'power_checkout' ) );
		}

		// 寫入逆物流單號（逆物流貨態反查用）
		$meta->update_return_ref( $return_logistics_id );

		self::logger(
			\sprintf( '↩️ PAYUNi 建立退貨便成功，RefundODNO：%s', $return_logistics_id ),
			'info',
			[
				'logistics_id'        => $ref,
				'return_logistics_id' => $return_logistics_id,
			],
			0,
			$order
		);

		return [ 'return_logistics_id' => $return_logistics_id ];
	}

	/**
	 * 處理貨態 callback（status-notify；PAYUNi 回純文字 "OK" 200，非綠界 AES-JSON）
	 *
	 * @param \WP_REST_Request $request PAYUNi Form POST 請求
	 *
	 * @return \WP_REST_Response 純文字 "OK"
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return PayuniLogisticsCallback::instance()
			->post_logistics_status_notify_callback( $request );
	}

	/**
	 * 取得結帳頁可選的物流子類型（enabled_methods 子集）
	 *
	 * @return array<int, string>
	 */
	public function get_supported_methods(): array {
		$settings = self::get_settings();
		$methods  = $settings['enabled_methods'] ?? [];
		if (!\is_array( $methods )) {
			return [];
		}
		$list = \array_map(
			static fn( mixed $method ): string => (string) $method,
			\array_values( $methods )
		);
		return $list;
	}

	// region 前置驗證 / 組裝 helpers

	/**
	 * 驗證訂單存在（ID > 0）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 * @throws \InvalidArgumentException 訂單不存在
	 */
	private function assert_order_exists( \WC_Order $order ): void {
		if ($order->get_id() <= 0) {
			throw new \InvalidArgumentException( \__( '找不到訂單', 'power_checkout' ) );
		}
	}

	/**
	 * 驗證 reply URL 為公開可訪問（非 localhost / 127.0.0.1）
	 *
	 * @param string $url reply URL
	 * @return void
	 * @throws \Exception URL 為 localhost
	 */
	private function assert_public_reply_url( string $url ): void {
		$host = (string) \wp_parse_url( $url, PHP_URL_HOST );
		$host = \strtolower( $host );

		$is_local = \in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true )
		|| \str_ends_with( $host, '.localhost' )
		|| \str_ends_with( $host, '.local' )
		|| \str_ends_with( $host, '.test' );

		if ($is_local) {
			throw new \Exception(
				\__( 'MapReturnURL / NotifyURL 必須為公開可訪問的 URL（不可為 localhost）', 'power_checkout' )
			);
		}
	}

	/**
	 * 組裝商店訂單編號 MerTradeNo（≤25；[A-Za-z0-9_-]；10 分鐘內不可重複）
	 *
	 * 以「PC + 訂單 ID + 後 6 碼時間戳」確保唯一且符合長度 / 字符限制。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function build_mer_trade_no( \WC_Order $order ): string {
		$suffix = \substr( (string) \time(), -6 );
		return \sprintf( 'PC%d_%s', $order->get_id(), $suffix );
	}

	/**
	 * 取得寄件型態 GoodsType（1=常溫 / 2=冷凍；由 order meta 讀取，預設常溫）
	 *
	 * @param \WC_Order $order 訂單
	 * @return int
	 */
	private function get_goods_type( \WC_Order $order ): int {
		$frozen = (string) ( $order->get_meta( '_pc_logistics_payuni_frozen' ) ?: '' );
		return 'yes' === $frozen ? 2 : 1;
	}

	/**
	 * 取得取件人姓名（billing name，截斷符合超商 ≤10 限制）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function get_consignee( \WC_Order $order ): string {
		$name = \trim( $order->get_billing_first_name() . $order->get_billing_last_name() );
		if ('' === $name) {
			$name = (string) \__( '取件人', 'power_checkout' );
		}
		// 超商核對身分用，截斷至 5 字（中文）
		return \mb_substr( $name, 0, 5 );
	}

	/**
	 * 取得取件人手機（billing phone）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function get_consignee_mobile( \WC_Order $order ): string {
		return (string) $order->get_billing_phone();
	}

	/**
	 * 由 order meta 子類型推導查詢 / 列印用的 LgsType
	 *
	 * @param LogisticsMetaKeys $meta meta 存取器
	 * @return string PayuniLgsType value（B2C / C2C / HOME）
	 */
	private function resolve_lgs_type( LogisticsMetaKeys $meta ): string {
		$sub_type = $meta->get_sub_type();
		if (PayuniSubType::HOME->value === $sub_type) {
			return PayuniLgsType::HOME->value;
		}
		// 超商取貨依商店設定的 B2C / C2C
		return PayuniLogisticsSettingsDTO::instance()->cvs_lgs_type;
	}

	/**
	 * 建立黑貓宅配退貨單（home_delivery/refund Ver 1.0）
	 *
	 * 退貨人 = 顧客（訂單收件人 / 地址），收件人 = 商店（不傳時 PAYUNi 自動帶入開通黑貓時設定的寄件人）。
	 * 取件日期 D+1 起（避開週日），寫入 _pc_logistics_return_ref（OBTNumber）。
	 *
	 * @param \WC_Order         $order 訂單
	 * @param LogisticsMetaKeys $meta  meta 存取器
	 * @param string            $ref   原宅配單 ShipTradeNo
	 *
	 * @return array<string, mixed> 含 return_logistics_id（OBTNumber）
	 *
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	private function create_home_return( \WC_Order $order, LogisticsMetaKeys $meta, string $ref ): array {
		$settings = PayuniLogisticsSettingsDTO::instance();
		$address  = $this->get_home_address( $order );
		if ('' === $address) {
			throw new \Exception( \__( '訂單沒有收件地址，無法建立黑貓退貨單', 'power_checkout' ) );
		}

		$goods_type = $this->get_goods_type( $order );
		$spec       = \max( 1, \min( 4, (int) $settings->home_spec ) );
		if (1 !== $goods_type && 4 === $spec) {
			$spec = 3;
		}

		$decrypted = ( new PayuniLogisticsApiClient( $order ) )->home_refund(
			[
				'ShipTradeNo'      => $ref,
				'GoodsType'        => $goods_type,
				'DeliveryTimeTag'  => \in_array( $settings->home_delivery_time_tag, HomeTradeParams::DELIVERY_TIME_TAGS, true ) ? $settings->home_delivery_time_tag : '04',
				'Consignor'        => HomeTradeParams::truncate_width( $this->get_consignee_full( $order ), 30 ),
				'ConsignorMobile'  => $this->get_consignee_mobile( $order ),
				'ConsignorAddress' => HomeTradeParams::truncate_width( $address, 120 ),
				'ProdDesc'         => HomeTradeParams::truncate_width( $this->get_prod_desc( $order ), 20 ),
				'Spec'             => $spec,
				'ShipDate'         => \gmdate( 'Ymd', self::home_return_pickup_day( self::taipei_now() ) ),
			]
		);

		$return_logistics_id = (string) ( $decrypted['OBTNumber'] ?? '' );
		if ('' === $return_logistics_id) {
			throw new \Exception( \__( 'PAYUNi 未回傳黑貓退貨編號，建立退貨單失敗', 'power_checkout' ) );
		}

		$meta->update_return_ref( $return_logistics_id );

		self::logger(
			\sprintf( '↩️ PAYUNi 黑貓宅配退貨單建立成功，退貨編號：%s', $return_logistics_id ),
			'info',
			[
				'logistics_id'        => $ref,
				'return_logistics_id' => $return_logistics_id,
			],
			0,
			$order
		);

		return [ 'return_logistics_id' => $return_logistics_id ];
	}

	/**
	 * 宅配收件地址（優先 shipping，沒有則用 billing；格式：縣市 + 鄉鎮市區 + 地址）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function get_home_address( \WC_Order $order ): string {
		$has_shipping = '' !== \trim( $order->get_shipping_address_1() );
		$parts        = $has_shipping
		? [ $order->get_shipping_state(), $order->get_shipping_city(), $order->get_shipping_address_1(), $order->get_shipping_address_2() ]
		: [ $order->get_billing_state(), $order->get_billing_city(), $order->get_billing_address_1(), $order->get_billing_address_2() ];

		$country = $has_shipping ? $order->get_shipping_country() : $order->get_billing_country();
		$states  = \WC()->countries->get_states( $country ?: 'TW' );
		// 州代碼（如 TPE）轉成縣市名稱
		if (\is_array( $states ) && isset( $states[ $parts[0] ] )) {
			$parts[0] = (string) $states[ $parts[0] ];
		}

		return \trim( \implode( '', \array_map( 'trim', $parts ) ) );
	}

	/**
	 * 宅配收件人全名（優先 shipping，沒有則用 billing；台灣習慣姓在前）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function get_consignee_full( \WC_Order $order ): string {
		$name = \trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		if ('' === $name) {
			$name = \trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		}
		return '' !== $name ? $name : (string) \__( '收件人', 'power_checkout' );
	}

	/**
	 * 黑貓託運單商品名稱（第一個商品名，多件加「等」）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function get_prod_desc( \WC_Order $order ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$names[] = \trim( \wp_strip_all_tags( $item->get_name() ) );
		}
		$names = \array_values( \array_filter( $names ) );
		if (!$names) {
			return (string) \__( '商品', 'power_checkout' );
		}
		return \count( $names ) > 1 ? $names[0] . '等' : $names[0];
	}

	/**
	 * 台灣時間的「現在」（以 gmdate 讀取即為台灣當地日期 / 時分）
	 *
	 * PAYUNi / 黑貓的日期與 16:25 截止時間都以台灣時間計算，不能用站台時區（很多站台設定為 UTC）。
	 *
	 * @return int 位移後的 timestamp（只拿來給 gmdate 格式化）
	 */
	private static function taipei_now(): int {
		return \time() + 8 * 3600;
	}

	/**
	 * 下一個非週日的日期（黑貓出貨日 / 配達日 / 取件日不可為週日）
	 *
	 * @param int $timestamp 基準時間（台灣時間位移後的 timestamp）
	 * @return int 至少 +1 天且非週日的 timestamp
	 */
	private static function next_working_day( int $timestamp ): int {
		$next = $timestamp + self::ONE_DAY;
		if ('0' === \gmdate( 'w', $next )) {
			$next += self::ONE_DAY;
		}
		return $next;
	}

	/**
	 * 黑貓退貨取件日：D+1 起；16:25 後申請最少 D+2（沙盒實測 HOME01141），且不可為週日
	 *
	 * @param int $now 現在時間（台灣時間位移後的 timestamp，見 taipei_now）
	 * @return int 取件日 timestamp
	 */
	public static function home_return_pickup_day( int $now ): int {
		$pickup = self::next_working_day( $now );
		if ( (int) \gmdate( 'Hi', $now ) >= 1625) {
			$pickup = self::next_working_day( $pickup );
		}
		return $pickup;
	}

	// endregion

	/**
	 * Provider meta（能力需與方法實作一致）
	 *
	 * PAYUNi 物流：7-ELEVEN 超商 + 黑貓宅配；退貨走 C2B 退貨便。
	 * cancel_shipment 一律拋「不支援直接取消」→ can_cancel=false。
	 *
	 * @return LogisticsProviderMetaDTO
	 */
	public function get_provider_meta(): LogisticsProviderMetaDTO {
		return new LogisticsProviderMetaDTO(
			id: self::ID,
			supported_delivery_methods: [ DeliveryMethod::HOME, DeliveryMethod::CVS ],
			capabilities: new ShipmentCapabilitiesDTO(
				can_create_shipment: true,
				can_query_shipment: true,
				has_cvs_map: true,
				can_print: true,
				can_return: true,
				can_cancel: false,
			),
		);
	}
}
