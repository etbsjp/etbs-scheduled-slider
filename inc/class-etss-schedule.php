<?php
/**
 * 表示期間の判定と、日時の入力値の変換。
 *
 * 時刻は UTC の Unix 秒で持つ。現在時刻・タイムゾーンはすべて引数で受け取り、
 * time() や $_POST を直接は読まない（PHPUnit で境界を確かめるため）。
 * WordPress に依存しない純粋な処理だけを置く。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 表示期間の判定と日時の変換をまとめたクラス。
 */
class ETSS_Schedule {

	/**
	 * 入力を受け付ける最小の年。これより前は形式エラーにする。
	 */
	const MIN_YEAR = 2000;

	/**
	 * datetime-local の値の書式（秒なし）。
	 */
	const INPUT_FORMAT = 'Y-m-d\TH:i';

	/**
	 * キャッシュ保存期間の上限の既定値（秒）。WordPress の DAY_IN_SECONDS と同じ値。
	 */
	const DEFAULT_MAX_TTL = 86400;

	/**
	 * いま表示中かを判定する。`start <= now < end`（終了時刻ちょうどには出さない）。
	 *
	 * @param int $start 表示開始（UTC の Unix 秒）。0 以下は未設定として表示しない。
	 * @param int $end   表示終了（UTC の Unix 秒）。0 は終了なし。負の値は不正として表示しない。
	 * @param int $now   現在時刻（UTC の Unix 秒）。
	 * @return bool 表示中なら true。
	 */
	public static function is_active( $start, $end, $now ) {
		$start = (int) $start;
		$end   = (int) $end;
		$now   = (int) $now;

		// 開始が未設定・不正、または終了が不正なら出さない。
		if ( $start <= 0 || $end < 0 ) {
			return false;
		}

		// 開始前は出さない。
		if ( $now < $start ) {
			return false;
		}

		// 終了なしなら出し続ける。終了があれば、終了時刻ちょうどには出さない。
		return 0 === $end || $now < $end;
	}

	/**
	 * 次の切替時刻（いまより後の開始・終了のうち最も早いもの）を求める。
	 *
	 * 切替時刻ちょうどは「すでに切り替わった」ものとして数えない。
	 *
	 * @param array $periods 期間の配列。各要素は `start`・`end`（UTC の Unix 秒）を持つ配列。
	 * @param int   $now     現在時刻（UTC の Unix 秒）。
	 * @return int|null 次の切替時刻。無ければ null。
	 */
	public static function next_switch_time( array $periods, $now ) {
		$now  = (int) $now;
		$next = null;

		// 開始・終了のうち、いまより後のものだけを候補にして最小を取る。
		foreach ( $periods as $period ) {
			$start = isset( $period['start'] ) ? (int) $period['start'] : 0;
			$end   = isset( $period['end'] ) ? (int) $period['end'] : 0;

			foreach ( array( $start, $end ) as $time ) {
				// 0 以下は未設定（終了なしを含む）なので候補にしない。
				if ( $time > $now && ( null === $next || $time < $next ) ) {
					$next = $time;
				}
			}
		}

		return $next;
	}

	/**
	 * ページキャッシュの保存期間（秒）を決める。次の切替時刻までで、上限を超えない。
	 *
	 * 切替時刻ちょうどに切れるようにするため、+1 秒はしない。
	 *
	 * @param int|null $next_switch 次の切替時刻（UTC の Unix 秒）。無ければ null。
	 * @param int      $now         現在時刻（UTC の Unix 秒）。
	 * @param int      $max_ttl     上限（秒）。1 未満は既定値（1 日）として扱う。
	 * @return int|null 保存期間（秒）。切替が無い・すでに過ぎているときは null（保存期間を指定しない）。
	 */
	public static function cache_ttl( $next_switch, $now, $max_ttl ) {
		if ( null === $next_switch || (int) $next_switch <= (int) $now ) {
			return null;
		}

		// 上限が不正な値（0 や負）で渡されても、無制限にはしない。
		$max_ttl = (int) $max_ttl;
		if ( $max_ttl < 1 ) {
			$max_ttl = self::DEFAULT_MAX_TTL;
		}

		return min( (int) $next_switch - (int) $now, $max_ttl );
	}

	/**
	 * datetime-local の値（例 `2026-11-06T12:00`）を UTC の Unix 秒にする。
	 *
	 * 形式・暦（2月30日など）・時刻の範囲・年（2000年より前）を確かめる。
	 * 夏時間の切り替えで存在しない時刻は、ずれて解釈されるので弾く。
	 * 空文字は「未入力」であり、ここでは形式エラー（null）になる。未入力かどうかは呼び出し側で先に見る。
	 *
	 * @param mixed        $value    入力値。秒つき（`2026-11-06T12:00:30`）も受け付ける。
	 * @param DateTimeZone $timezone 入力を解釈するタイムゾーン（サイトのタイムゾーン）。
	 * @return int|null UTC の Unix 秒。形式エラーなら null。
	 */
	public static function parse_datetime_local( $value, DateTimeZone $timezone ) {
		// 形式（年-月-日T時:分[:秒]）を確かめる。
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?\z/', $value, $m ) ) {
			return null;
		}

		$year   = (int) $m[1];
		$month  = (int) $m[2];
		$day    = (int) $m[3];
		$hour   = (int) $m[4];
		$minute = (int) $m[5];
		$second = isset( $m[6] ) ? (int) $m[6] : 0;

		// 年・暦・時刻の範囲を確かめる（2月30日のような存在しない日付を弾く）。
		if ( $year < self::MIN_YEAR || ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
			return null;
		}

		$normalized = sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second );
		$date       = new DateTimeImmutable( $normalized, $timezone );

		// 夏時間で存在しない時刻は別の時刻にずれるので、書き戻して一致するか確かめる。
		if ( $date->format( 'Y-m-d H:i:s' ) !== $normalized ) {
			return null;
		}

		return $date->getTimestamp();
	}

	/**
	 * UTC の Unix 秒を datetime-local の値（例 `2026-11-06T12:00`）にする。秒は切り捨てる。
	 *
	 * @param int          $timestamp UTC の Unix 秒。
	 * @param DateTimeZone $timezone  表示するタイムゾーン（サイトのタイムゾーン）。
	 * @return string datetime-local の値。未設定（0 以下）や、入力で受け付けない年（2000年より前）は空文字。
	 */
	public static function format_datetime_local( $timestamp, DateTimeZone $timezone ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return '';
		}

		$date = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );
		if ( (int) $date->format( 'Y' ) < self::MIN_YEAR ) {
			return '';
		}

		return $date->format( self::INPUT_FORMAT );
	}
}
