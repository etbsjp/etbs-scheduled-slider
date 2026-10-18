<?php
/**
 * inc/class-etss-schedule.php（表示期間の判定と日時の変換）のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Schedule のテスト。
 */
class ScheduleTest extends TestCase {

	/**
	 * ETSS_Schedule::is_active() のテスト。
	 *
	 * @return void
	 */
	public function test_is_active() {
		$test_cases = array(
			array(
				'test_condition_name' => '開始前 => false',
				'input'               => array( 1000, 2000, 999 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '開始時刻ちょうど => true',
				'input'               => array( 1000, 2000, 1000 ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '終了の1秒前 => true',
				'input'               => array( 1000, 2000, 1999 ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '終了時刻ちょうど => false',
				'input'               => array( 1000, 2000, 2000 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了後 => false',
				'input'               => array( 1000, 2000, 2001 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了なし（0）は開始後ずっと => true',
				'input'               => array( 1000, 0, 9999999999 ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '終了なしでも開始前 => false',
				'input'               => array( 1000, 0, 999 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '開始が 0（未設定） => false',
				'input'               => array( 0, 0, 1000 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '開始が負の秒 => false',
				'input'               => array( -100, 0, 1000 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了が負の秒（不正） => false',
				'input'               => array( 1000, -1, 1500 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '終了が開始以前（逆転） => false',
				'input'               => array( 2000, 1000, 1500 ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '現在時刻が負の秒 => false',
				'input'               => array( 1000, 2000, -5 ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Schedule::is_active( $case['input'][0], $case['input'][1], $case['input'][2] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Schedule::next_switch_time() のテスト。
	 *
	 * @return void
	 */
	public function test_next_switch_time() {
		$test_cases = array(
			array(
				'test_condition_name' => '期間が無い => null',
				'input'               => array( array(), 1000 ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '開始が先 => 開始',
				'input'               => array( array( array( 'start' => 1500, 'end' => 3000 ) ), 1000 ), // phpcs:ignore
				'expected'            => 1500,
			),
			array(
				'test_condition_name' => '表示中なら終了 => 終了',
				'input'               => array( array( array( 'start' => 500, 'end' => 3000 ) ), 1000 ), // phpcs:ignore
				'expected'            => 3000,
			),
			array(
				'test_condition_name' => '切替時刻ちょうど（開始 = now）は数えない => 終了',
				'input'               => array( array( array( 'start' => 1000, 'end' => 3000 ) ), 1000 ), // phpcs:ignore
				'expected'            => 3000,
			),
			array(
				'test_condition_name' => '終了 = now も数えない => null',
				'input'               => array( array( array( 'start' => 500, 'end' => 1000 ) ), 1000 ), // phpcs:ignore
				'expected'            => null,
			),
			array(
				'test_condition_name' => '終了なし（0）は候補にしない => null',
				'input'               => array( array( array( 'start' => 500, 'end' => 0 ) ), 1000 ), // phpcs:ignore
				'expected'            => null,
			),
			array(
				'test_condition_name' => '終了済みは外れる => 他の開始',
				'input'               => array(
					array(
						array( 'start' => 100, 'end' => 900 ), // phpcs:ignore
						array( 'start' => 4000, 'end' => 0 ), // phpcs:ignore
					),
					1000,
				),
				'expected'            => 4000,
			),
			array(
				'test_condition_name' => '複数のうち最も早いもの',
				'input'               => array(
					array(
						array( 'start' => 5000, 'end' => 6000 ), // phpcs:ignore
						array( 'start' => 500, 'end' => 2500 ), // phpcs:ignore
						array( 'start' => 3000, 'end' => 0 ), // phpcs:ignore
					),
					1000,
				),
				'expected'            => 2500,
			),
			array(
				'test_condition_name' => '負の秒は未設定として無視 => 他の候補',
				'input'               => array(
					array(
						array( 'start' => -50, 'end' => -10 ), // phpcs:ignore
						array( 'start' => 1200, 'end' => 0 ), // phpcs:ignore
					),
					1000,
				),
				'expected'            => 1200,
			),
			array(
				'test_condition_name' => 'キーが無い要素は未設定として扱う => null',
				'input'               => array( array( array() ), 1000 ),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Schedule::next_switch_time( $case['input'][0], $case['input'][1] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Schedule::cache_ttl() のテスト。
	 *
	 * @return void
	 */
	public function test_cache_ttl() {
		$test_cases = array(
			array(
				'test_condition_name' => '切替が無い => null',
				'input'               => array( null, 1000, 86400 ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '上限より近い切替 => 差の秒数（+1 しない）',
				'input'               => array( 1060, 1000, 86400 ),
				'expected'            => 60,
			),
			array(
				'test_condition_name' => '1秒後の切替 => 1',
				'input'               => array( 1001, 1000, 86400 ),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => '上限ちょうど => 上限',
				'input'               => array( 87400, 1000, 86400 ),
				'expected'            => 86400,
			),
			array(
				'test_condition_name' => '30日後の切替 => 上限で切る',
				'input'               => array( 1000 + 30 * 86400, 1000, 86400 ),
				'expected'            => 86400,
			),
			array(
				'test_condition_name' => '切替時刻 = now（すでに切り替わった） => null',
				'input'               => array( 1000, 1000, 86400 ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '切替が過去 => null',
				'input'               => array( 900, 1000, 86400 ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '上限が 0 => 既定の 1 日で切る（無制限にしない）',
				'input'               => array( 1000 + 30 * 86400, 1000, 0 ),
				'expected'            => 86400,
			),
			array(
				'test_condition_name' => '上限が負 => 既定の 1 日で切る',
				'input'               => array( 1000 + 30 * 86400, 1000, -5 ),
				'expected'            => 86400,
			),
			array(
				'test_condition_name' => '上限を短くする => その上限',
				'input'               => array( 1000 + 3600, 1000, 600 ),
				'expected'            => 600,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Schedule::cache_ttl( $case['input'][0], $case['input'][1], $case['input'][2] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Schedule::parse_datetime_local() のテスト。
	 *
	 * @return void
	 */
	public function test_parse_datetime_local() {
		$tokyo    = new DateTimeZone( 'Asia/Tokyo' );
		$new_york = new DateTimeZone( 'America/New_York' );

		$test_cases = array(
			array(
				'test_condition_name' => '東京の 2026-11-06T12:00 => UTC 03:00',
				'input'               => array( '2026-11-06T12:00', $tokyo ),
				'expected'            => 1793934000,
			),
			array(
				'test_condition_name' => '秒つきも受け付ける',
				'input'               => array( '2026-11-06T12:00:30', $tokyo ),
				'expected'            => 1793934030,
			),
			array(
				'test_condition_name' => '2000年ちょうど（東京の 2000-01-01T00:00） => 通す',
				'input'               => array( '2000-01-01T00:00', $tokyo ),
				'expected'            => 946652400,
			),
			array(
				'test_condition_name' => '1999年 => 形式エラー',
				'input'               => array( '1999-12-31T23:59', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '1970年（0 秒付近） => 形式エラー',
				'input'               => array( '1970-01-01T09:00', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '2月30日 => 形式エラー',
				'input'               => array( '2026-02-30T10:00', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '平年の2月29日 => 形式エラー',
				'input'               => array( '2026-02-29T10:00', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'うるう年の2月29日 => 通す',
				'input'               => array( '2028-02-29T10:00', $tokyo ),
				'expected'            => gmmktime( 1, 0, 0, 2, 29, 2028 ),
			),
			array(
				'test_condition_name' => '13月 => 形式エラー',
				'input'               => array( '2026-13-01T10:00', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '24時 => 形式エラー',
				'input'               => array( '2026-11-06T24:00', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '60分 => 形式エラー',
				'input'               => array( '2026-11-06T12:60', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '空文字（未入力） => null',
				'input'               => array( '', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => 'T の代わりに空白 => 形式エラー',
				'input'               => array( '2026-11-06 12:00', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '日付だけ => 形式エラー',
				'input'               => array( '2026-11-06', $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '末尾に改行 => 形式エラー',
				'input'               => array( "2026-11-06T12:00\n", $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '文字列でない（配列） => 形式エラー',
				'input'               => array( array( '2026-11-06T12:00' ), $tokyo ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '夏時間で存在しない時刻（NY 2026-03-08T02:30） => 形式エラー',
				'input'               => array( '2026-03-08T02:30', $new_york ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '夏時間の直後（NY 2026-03-08T03:30） => 通す',
				'input'               => array( '2026-03-08T03:30', $new_york ),
				'expected'            => 1772955000,
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Schedule::parse_datetime_local( $case['input'][0], $case['input'][1] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Schedule::format_datetime_local() のテスト。
	 *
	 * @return void
	 */
	public function test_format_datetime_local() {
		$tokyo = new DateTimeZone( 'Asia/Tokyo' );

		$test_cases = array(
			array(
				'test_condition_name' => 'UTC 03:00 => 東京の 12:00',
				'input'               => array( 1793934000, $tokyo ),
				'expected'            => '2026-11-06T12:00',
			),
			array(
				'test_condition_name' => '秒は切り捨てる',
				'input'               => array( 1793934030, $tokyo ),
				'expected'            => '2026-11-06T12:00',
			),
			array(
				'test_condition_name' => '0（未設定・終了なし） => 空文字',
				'input'               => array( 0, $tokyo ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '負の秒 => 空文字',
				'input'               => array( -1, $tokyo ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '2000年より前（入力で受け付けない年） => 空文字',
				'input'               => array( 946598400, $tokyo ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '2000年ちょうど => 通す',
				'input'               => array( 946652400, $tokyo ),
				'expected'            => '2000-01-01T00:00',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Schedule::format_datetime_local( $case['input'][0], $case['input'][1] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}

		// 変換して戻すと元の値になる（分までの値）。
		$value = '2026-11-06T12:00';
		$this->assertSame(
			$value,
			ETSS_Schedule::format_datetime_local( ETSS_Schedule::parse_datetime_local( $value, $tokyo ), $tokyo ),
			'往復で同じ値になる'
		);
	}
}
