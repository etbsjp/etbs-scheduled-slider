<?php
/**
 * inc/class-etss-display.php（状態・日時・ボタン名の文言）のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Display のテスト。
 */
class DisplayTest extends TestCase {

	/**
	 * 日本時間の日時を UTC 秒にする。
	 *
	 * @param string $datetime 例 `2026-11-06 12:00:00`。
	 * @return int UTC の Unix 秒。
	 */
	private function jst( $datetime ) {
		return ( new DateTimeImmutable( $datetime, new DateTimeZone( 'Asia/Tokyo' ) ) )->getTimestamp();
	}

	/**
	 * ETSS_Display::status() のテスト。
	 *
	 * @return void
	 */
	public function test_status() {
		$now = 1000;

		$test_cases = array(
			array(
				'test_condition_name' => '公開で期間内 => 表示中',
				'input'               => array( 'publish', 500, 2000 ),
				'expected'            => 'active',
			),
			array(
				'test_condition_name' => '公開で終了なし => 表示中',
				'input'               => array( 'publish', 500, 0 ),
				'expected'            => 'active',
			),
			array(
				'test_condition_name' => '開始時刻ちょうど => 表示中',
				'input'               => array( 'publish', 1000, 0 ),
				'expected'            => 'active',
			),
			array(
				'test_condition_name' => '公開で開始前 => 表示予定',
				'input'               => array( 'publish', 1500, 0 ),
				'expected'            => 'scheduled',
			),
			array(
				'test_condition_name' => '終了時刻ちょうど => 表示終了',
				'input'               => array( 'publish', 500, 1000 ),
				'expected'            => 'ended',
			),
			array(
				'test_condition_name' => '公開で終了後 => 表示終了',
				'input'               => array( 'publish', 100, 200 ),
				'expected'            => 'ended',
			),
			array(
				'test_condition_name' => '下書き（期間内でも） => 下書き',
				'input'               => array( 'draft', 500, 2000 ),
				'expected'            => 'draft',
			),
			array(
				'test_condition_name' => 'future（使わない状態） => 下書き',
				'input'               => array( 'future', 5000, 0 ),
				'expected'            => 'draft',
			),
			array(
				'test_condition_name' => '公開でも開始が未設定 => 下書き扱い（表示されないため）',
				'input'               => array( 'publish', 0, 0 ),
				'expected'            => 'draft',
			),
			array(
				'test_condition_name' => '公開でも終了が不正（負） => 下書き扱い',
				'input'               => array( 'publish', 500, -1 ),
				'expected'            => 'draft',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Display::status( $case['input'][0], $case['input'][1], $case['input'][2], $now );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 日時の書式（24時間制・曜日）のテスト。
	 *
	 * @return void
	 */
	public function test_format_datetime() {
		$tokyo = new DateTimeZone( 'Asia/Tokyo' );

		$this->assertSame( '11/06（金）12:00', ETSS_Display::format_short( $this->jst( '2026-11-06 12:00:00' ), $tokyo ) );
		$this->assertSame( '2026年11月6日（金）12:00', ETSS_Display::format_full( $this->jst( '2026-11-06 12:00:00' ), $tokyo ) );
		$this->assertSame( '2026年11月6日（金）15:30', ETSS_Display::format_full( $this->jst( '2026-11-06 15:30:00' ), $tokyo ), '午後も24時間制' );
		$this->assertSame( '2026年1月4日（日）00:05', ETSS_Display::format_full( $this->jst( '2026-01-04 00:05:00' ), $tokyo ), '日曜・0時台・月日は0埋めしない' );
		$this->assertSame( '2026年11月5日（木）19:00', ETSS_Display::format_full( $this->jst( '2026-11-06 12:00:00' ), new DateTimeZone( 'America/Los_Angeles' ) ), '指定したタイムゾーンで出す' );
	}

	/**
	 * 期間の文言のテスト。
	 *
	 * @return void
	 */
	public function test_format_duration() {
		$test_cases = array(
			array( 0, '1分未満' ),
			array( 59, '1分未満' ),
			array( 300, '5分' ),
			array( 9000, '2時間30分' ),
			array( 7200, '2時間' ),
			array( 3 * 86400 + 4 * 3600 + 59, '3日4時間' ),
			array( 3 * 86400 + 59, '3日' ),
			array( 86400, '1日' ),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case[1], ETSS_Display::format_duration( $case[0] ), $case[0] . ' 秒' );
		}
	}

	/**
	 * いまとの差の文言のテスト。
	 *
	 * @return void
	 */
	public function test_format_relative() {
		$test_cases = array(
			array( 3 * 86400 + 4 * 3600, 'あと3日4時間' ),
			array( -( 3 * 86400 + 4 * 3600 ), '3日4時間前' ),
			array( 30, 'まもなく' ),
			array( -30, 'たった今' ),
			array( 0, 'たった今' ),
			array( 60, 'あと5分', 300 ),
		);

		foreach ( $test_cases as $case ) {
			$diff = isset( $case[2] ) ? $case[2] : $case[0];
			$this->assertSame( $case[1], ETSS_Display::format_relative( $diff ), $diff . ' 秒差' );
		}
	}

	/**
	 * 書き戻しの文言のテスト（仕様の例）。
	 *
	 * @return void
	 */
	public function test_describe_start_and_end() {
		$tokyo = new DateTimeZone( 'Asia/Tokyo' );
		$now   = $this->jst( '2026-11-03 08:00:00' );
		$start = $this->jst( '2026-11-06 12:00:00' );

		$this->assertSame( '→ 2026年11月6日（金）12:00 に表示開始（あと3日4時間）', ETSS_Display::describe_start( $start, $now, $tokyo ) );
		$this->assertSame( '→ 2026年11月6日（金）12:00 に表示開始（3日4時間前）', ETSS_Display::describe_start( $start, $this->jst( '2026-11-09 16:00:00' ), $tokyo ), '過去の開始' );
		$this->assertSame( '', ETSS_Display::describe_start( 0, $now, $tokyo ), '未入力は空' );

		$this->assertSame( '→ 2026年11月6日（金）12:00 に表示終了（あと3日4時間）', ETSS_Display::describe_end( $start, $now, $tokyo ) );
		$this->assertSame( '→ 表示終了日時なし（自分で外すまで表示し続けます）', ETSS_Display::describe_end( 0, $now, $tokyo ) );
	}

	/**
	 * 公開ボックスの1行のテスト。
	 *
	 * @return void
	 */
	public function test_submitbox_line() {
		$tokyo = new DateTimeZone( 'Asia/Tokyo' );
		$now   = $this->jst( '2026-11-03 08:00:00' );
		$start = $this->jst( '2026-11-06 12:00:00' );

		$test_cases = array(
			array(
				'test_condition_name' => '表示予定 => 仕様の例の形',
				'input'               => array( 'publish', $start, 0 ),
				'expected'            => '表示予定（11/06（金）12:00 から）',
			),
			array(
				'test_condition_name' => '表示中で終了あり => 終了日時まで',
				'input'               => array( 'publish', $this->jst( '2026-11-01 00:00:00' ), $this->jst( '2026-12-01 00:00:00' ) ),
				'expected'            => '表示中（12/01（火）00:00 まで）',
			),
			array(
				'test_condition_name' => '表示中で終了なし',
				'input'               => array( 'publish', $this->jst( '2026-11-01 00:00:00' ), 0 ),
				'expected'            => '表示中（終了日時なし）',
			),
			array(
				'test_condition_name' => '表示終了',
				'input'               => array( 'publish', $this->jst( '2026-10-01 00:00:00' ), $this->jst( '2026-11-02 00:00:00' ) ),
				'expected'            => '表示終了（11/02（月）00:00 に終了）',
			),
			array(
				'test_condition_name' => '下書き',
				'input'               => array( 'draft', $start, 0 ),
				'expected'            => '下書き',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Display::submitbox_line( $case['input'][0], $case['input'][1], $case['input'][2], $now, $tokyo );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * 画像枠のボタン名のテスト。
	 *
	 * @return void
	 */
	public function test_button_name() {
		$test_cases = array(
			array( '横長', '選ぶ', '横長の画像を選ぶ' ),
			array( '縦長（スマートフォン）', '変更する', '縦長（スマートフォン）の画像を変更する' ),
			array( '画像', '選ぶ', '画像を選ぶ' ),
			array( 'メイン画像', '外す', 'メイン画像を外す' ),
			array( '横長', '選択', '横長の画像を選択' ),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case[2], ETSS_Display::button_name( $case[0], $case[1] ), $case[0] . ' ' . $case[1] );
		}
	}
}
