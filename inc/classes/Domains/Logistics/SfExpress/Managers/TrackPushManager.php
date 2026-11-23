<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Managers;

use J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs\SfExpressSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfCrypto;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfExpressMetaKeys;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfOpcodeMapper;
use J7\PowerCheckout\Plugin;

/**
 * 順豐運單軌跡推送處理：金鑰 → 驗 sign → 解析 → 依時間排序 → 逐筆套用 → 組 ack
 *
 * 回應格式（skill `tracking.md` §4；HTTP 一律 200）：
 *  - 全部失敗：`{"code":"1","msg":"原因","data":null}`
 *  - 部分或全部成功：`{"code":"0","msg":"成功","data":[{"code":"0|1","msg":…,"trackUniqueId":…,"sfWaybillNo":…}]}`（data 順序 = 推送順序）
 *
 * 安全：
 *  - 一律驗 sign；**未設推送金鑰就整批拒收**（不因金鑰空而放行）。驗簽前不解析、不信任 body 任何內容。
 *  - 驗 sign 必須用原始 body（不可 parse 後重組）。
 * 冪等：以 trackUniqueId（缺時退回 `{sfWaybillNo}:{opCode}:{localTm}`）去重，存訂單 meta（保留 50 筆）。
 * 缺運單號 / 認不得 opCode / 查無訂單 → 該筆回 0（重送改變不了結果，回 1 只會換來無止境重送）；
 * 只有我方暫時性錯誤（\Throwable）該筆回 1 讓順豐重送；全部回 1 → 整體 code 1。
 *
 * @see .claude/skills/sf-express/references/tracking.md §4
 * @see zenbu apps/api-gateway/src/commerce/shipping/sf-express/sf-track-push.ts
 */
final class TrackPushManager {

	/**
	 * 處理推送
	 *
	 * @param string $raw_body 原始 body
	 * @param string $sign     header sign
	 * @return array{code: string, msg: string, data: array<int, array<string, string>>|null}
	 */
	public static function process( string $raw_body, string $sign ): array {
		$key = SfExpressSettingsDTO::instance()->push_sign_key;
		if ('' === $key) {
			Plugin::logger( '順豐軌跡推送：尚未設定推送簽章金鑰，整批拒收', 'warning' );
			return self::reject( '未設定推送簽章金鑰' );
		}
		if (!SfCrypto::verify_push_sign( $raw_body, $key, $sign )) {
			Plugin::logger( '順豐軌跡推送：sign 驗證失敗，整批拒收', 'warning', [ 'has_sign' => '' !== $sign ] );
			return self::reject( '簽章驗證失敗' );
		}

		$parsed = \json_decode( $raw_body, true );
		if (!\is_array( $parsed ) || !\array_is_list( $parsed )) {
			return self::reject( '推送內容格式錯誤' );
		}

		$events = [];
		foreach ( $parsed as $index => $item ) {
			/** @var array<string, mixed> $event */
			$event    = \is_array( $item ) ? $item : [];
			$events[] = [
				'index' => $index,
				'event' => $event,
				'ts'    => SfOpcodeMapper::parse_time( SfOpcodeMapper::field( $event, 'localTm' ), SfOpcodeMapper::field( $event, 'gmt' ) ) ?? PHP_INT_MAX,
			];
		}
		// 同批無序：依事件時間套用，避免較早的「派送中」覆蓋較晚的「已簽收」
		\usort( $events, static fn( array $a, array $b ): int => $a['ts'] <=> $b['ts'] ?: $a['index'] <=> $b['index'] );

		$results = [];
		foreach ( $events as $entry ) {
			$results[ $entry['index'] ] = self::process_event( $entry['event'] );
		}
		\ksort( $results );
		$results = \array_values( $results );

		if ([] !== $results && [] === \array_filter( $results, static fn( array $r ): bool => '0' === $r['code'] )) {
			return self::reject( '全部事件處理失敗' );
		}
		return [
			'code' => '0',
			'msg'  => '成功',
			'data' => $results,
		];
	}

	/**
	 * 處理單筆事件
	 *
	 * @param array<string, mixed> $event 事件
	 * @return array<string, string> ack 項目
	 */
	private static function process_event( array $event ): array {
		$waybill = SfOpcodeMapper::field( $event, 'sfWaybillNo' );
		$uid     = SfOpcodeMapper::field( $event, 'trackUniqueId' );
		$ack     = \array_filter(
			[
				'trackUniqueId' => $uid,
				'sfWaybillNo'   => $waybill,
			],
			static fn( string $v ): bool => '' !== $v
		);

			try {
				$order = SfExpressMetaKeys::find_order_by_ref( $waybill );
				if (null === $order) {
					return \array_merge( [ 'code' => '0' ], $ack, [ 'msg' => '' === $waybill ? '缺少運單號' : '查無此單' ] );
				}

				$meta = new SfExpressMetaKeys( $order );
				$key  = '' !== $uid ? $uid : \implode( ':', [ $waybill, SfOpcodeMapper::field( $event, 'opCode' ), SfOpcodeMapper::field( $event, 'localTm' ) ] );
				if (!$meta->is_track_processed( $key )) {
					TrackStatusManager::apply( $order, $event, '推送' );
					$meta->mark_track_processed( $key );
				}
				return \array_merge( [ 'code' => '0' ], $ack, [ 'msg' => '成功' ] );
			} catch ( \Throwable $e ) {
				Plugin::logger(
				'順豐軌跡推送：事件處理失敗',
				'error',
				[
					'sfWaybillNo' => $waybill,
					'opCode'      => SfOpcodeMapper::field( $event, 'opCode' ),
					'error'       => $e->getMessage(),
				]
				);
				return \array_merge( [ 'code' => '1' ], $ack, [ 'msg' => '處理失敗' ] );
			}
	}

	/**
	 * 整批拒收
	 *
	 * @param string $message 原因
	 * @return array{code: string, msg: string, data: null}
	 */
	private static function reject( string $message ): array {
		return [
			'code' => '1',
			'msg'  => $message,
			'data' => null,
		];
	}
}
