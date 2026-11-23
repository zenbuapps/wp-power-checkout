<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Exceptions\TcatLogisticsException;

/**
 * 黑貓日期規則（純函式，一律台北時間 yyyyMMdd）
 *
 * - 週日不出貨 / 不配送 / 不取件（國定假日無官方 API 可查，交給黑貓端驗證回 E032 / E063）
 * - 希望配達日：> 出貨日（E090）、≤ 出貨日 + 7（E091）→ 取出貨日隔天、遇週日順延
 * - 逆物流 TakeDate：限 D+1~D+7（E062）；隔日取件須在前一上班日 **16:30** 前呼叫
 *   （文件 TakeDate 說明 / E072 寫 16:30、E065/E066 寫 17:30，取嚴格值 16:30）
 *
 * @see .claude/skills/tcat-logistics/SKILL.md §重要前提
 */
final class TcatLogisticsDates {

	/** @var string 時區（黑貓營運時間以台灣為準） */
	public const TIMEZONE = 'Asia/Taipei';

	/** @var string 隔日取件呼叫截止時間（嚴格值） */
	public const TAKE_CUTOFF = '16:30';

	/** @var int 取件日最遠天數（D+7） */
	private const TAKE_MAX_DAYS = 7;

	/** @return \DateTimeImmutable 現在（台北時間） */
	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( self::TIMEZONE ) );
	}

	/**
	 * 出貨日（今天；週日順延週一）
	 *
	 * @param \DateTimeImmutable $now 現在
	 * @return string yyyyMMdd
	 */
	public static function shipment_date( \DateTimeImmutable $now ): string {
		return self::skip_sunday( self::to_taipei( $now ) )->format( 'Ymd' );
	}

	/**
	 * 希望配達日（出貨日隔天；週日順延）
	 *
	 * @param string $shipment_date 出貨日 yyyyMMdd
	 * @return string yyyyMMdd
	 */
	public static function delivery_date( string $shipment_date ): string {
		$shipment = self::parse( $shipment_date ) ?? self::now();
		return self::skip_sunday( $shipment->modify( '+1 day' ) )->format( 'Ymd' );
	}

	/**
	 * 逆物流取件日
	 *
	 * 未指定 → 最早可取件日（16:30 前 D+1，之後 D+2；遇週日順延）。
	 * 指定 → 驗證格式、D+1~D+7、非週日、隔日取件需在 16:30 前。
	 *
	 * @param \DateTimeImmutable $now       現在
	 * @param string|null        $requested 指定取件日 yyyyMMdd
	 * @return string yyyyMMdd
	 * @throws TcatLogisticsException 指定日期不合法（VALIDATION）
	 */
	public static function take_date( \DateTimeImmutable $now, ?string $requested = null ): string {
		$now      = self::to_taipei( $now );
		$today    = $now->setTime( 0, 0 );
		$tomorrow = $today->modify( '+1 day' );
		$past_cut = $now->format( 'H:i' ) >= self::TAKE_CUTOFF;

		if (null === $requested || '' === \trim( $requested )) {
			$earliest = $past_cut ? $today->modify( '+2 day' ) : $tomorrow;
			return self::skip_sunday( $earliest )->format( 'Ymd' );
		}

		$date = self::parse( \trim( $requested ) );
		if (null === $date) {
			throw TcatLogisticsException::validation( '取件日期格式須為 yyyyMMdd' );
		}

		$latest = $today->modify( '+' . self::TAKE_MAX_DAYS . ' day' );
		if ($date < $tomorrow || $date > $latest) {
			throw TcatLogisticsException::validation(
				\sprintf( '取件日期限 D+1～D+%1$d（%2$s～%3$s）', self::TAKE_MAX_DAYS, $tomorrow->format( 'Ymd' ), $latest->format( 'Ymd' ) )
			);
		}
		if ('7' === $date->format( 'N' )) {
			throw TcatLogisticsException::validation( '黑貓週日不提供取件服務，請改選其他日期' );
		}
		if ($past_cut && $date->format( 'Ymd' ) === $tomorrow->format( 'Ymd' )) {
			throw TcatLogisticsException::validation(
				\sprintf( '隔日取件需於今日 %s 前預約，請改選後天以後的日期', self::TAKE_CUTOFF )
			);
		}

		return $date->format( 'Ymd' );
	}

	/**
	 * 解析 yyyyMMdd（台北時間 00:00）
	 *
	 * @param string $ymd yyyyMMdd
	 * @return \DateTimeImmutable|null 格式錯誤回 null
	 */
	private static function parse( string $ymd ): ?\DateTimeImmutable {
		if (1 !== \preg_match( '/^\d{8}$/', $ymd )) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Ymd', $ymd, new \DateTimeZone( self::TIMEZONE ) );
		return ( $date instanceof \DateTimeImmutable && $date->format( 'Ymd' ) === $ymd ) ? $date : null;
	}

	/**
	 * 轉台北時間
	 *
	 * @param \DateTimeImmutable $date 日期
	 * @return \DateTimeImmutable
	 */
	private static function to_taipei( \DateTimeImmutable $date ): \DateTimeImmutable {
		return $date->setTimezone( new \DateTimeZone( self::TIMEZONE ) );
	}

	/**
	 * 週日順延到週一
	 *
	 * @param \DateTimeImmutable $date 日期
	 * @return \DateTimeImmutable
	 */
	private static function skip_sunday( \DateTimeImmutable $date ): \DateTimeImmutable {
		return '7' === $date->format( 'N' ) ? $date->modify( '+1 day' ) : $date;
	}
}
