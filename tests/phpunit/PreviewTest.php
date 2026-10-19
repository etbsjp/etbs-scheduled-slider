<?php
/**
 * inc/class-etss-preview.php（日時プレビュー）のテスト。
 *
 * 画面（帯・リンク）の見た目と、LiteSpeed Cache のログは、検証サイトで確かめる。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Preview のテスト。
 */
class PreviewTest extends TestCase {

	/**
	 * スタブとリクエストごとの状態を空にする。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		etss_test_reset();
		etss_test_reset_wp();
		ETSS_Cache::reset();
		ETSS_Preview::reset();
	}

	/**
	 * 東京のタイムゾーンを返す。
	 *
	 * @return DateTimeZone
	 */
	private function tokyo() {
		return new DateTimeZone( 'Asia/Tokyo' );
	}

	/**
	 * 記録された do_action のうち、nocache の呼び出しだけを返す。
	 *
	 * @return array
	 */
	private function nocache_calls() {
		return array_values(
			array_filter(
				$GLOBALS['etss_test_action_calls'],
				static function ( $call ) {
					return 'litespeed_control_set_nocache' === $call[0];
				}
			)
		);
	}

	/**
	 * ETSS_Preview::parse_at() のテスト。サイトのタイムゾーンで解釈する。
	 *
	 * @return void
	 */
	public function test_parse_at() {
		$test_cases = array(
			array(
				'test_condition_name' => '分まで => 東京の 12:00 は UTC の 03:00',
				'value'               => '2026-11-06T12:00',
				'expected'            => gmmktime( 3, 0, 0, 11, 6, 2026 ),
			),
			array(
				'test_condition_name' => '秒つき',
				'value'               => '2026-11-06T12:00:30',
				'expected'            => gmmktime( 3, 0, 30, 11, 6, 2026 ),
			),
			array(
				'test_condition_name' => '前後の空白は落とす',
				'value'               => ' 2026-11-06T12:00 ',
				'expected'            => gmmktime( 3, 0, 0, 11, 6, 2026 ),
			),
			array(
				'test_condition_name' => '空文字 => 読めない',
				'value'               => '',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '形式が違う => 読めない',
				'value'               => '2026/11/06 12:00',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '存在しない日付 => 読めない',
				'value'               => '2026-02-30T12:00',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '配列 => 読めない',
				'value'               => array( '2026-11-06T12:00' ),
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Preview::parse_at( $case['value'], $this->tokyo() ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Preview::format_at_param() と build_url() のテスト。parse_at() で元の時刻に戻る。
	 *
	 * @return void
	 */
	public function test_url() {
		$ts = gmmktime( 3, 0, 0, 11, 6, 2026 );
		$this->assertSame( '2026-11-06T12:00', ETSS_Preview::format_at_param( $ts, $this->tokyo() ), '秒が 0 => 分まで' );
		$this->assertSame( '2026-11-06T12:00:30', ETSS_Preview::format_at_param( $ts + 30, $this->tokyo() ), '秒が 0 でない => 秒まで' );
		$this->assertSame( $ts + 30, ETSS_Preview::parse_at( ETSS_Preview::format_at_param( $ts + 30, $this->tokyo() ), $this->tokyo() ), '往復して同じ時刻' );

		$this->assertSame( 'https://example.com/top/?etss_at=2026-11-06T12%3A00', ETSS_Preview::build_url( 'https://example.com/top/', $ts, $this->tokyo() ), '引数が無い URL' );
		$this->assertSame( 'https://example.com/?p=3&etss_at=2026-11-06T12%3A00', ETSS_Preview::build_url( 'https://example.com/?p=3', $ts, $this->tokyo() ), '引数がある URL' );
		$this->assertSame( 'https://example.com/top/?etss_at=2026-11-06T12%3A00', ETSS_Preview::build_url( 'https://example.com/top/?etss_at=old', $ts, $this->tokyo() ), 'すでに付いている etss_at は置き換える' );
	}

	/**
	 * ETSS_Preview::banner_text() のテスト。
	 *
	 * @return void
	 */
	public function test_banner_text() {
		$now = gmmktime( 0, 0, 0, 10, 1, 2026 );
		$at  = gmmktime( 3, 0, 0, 11, 6, 2026 );

		$this->assertSame( 'プレビュー中: 11/06（金）12:00 の並び（下書きを含む）', ETSS_Preview::banner_text( $at, $now, $this->tokyo() ), '同じ年 => 年なし' );
		$this->assertSame( 'プレビュー中: 2027/11/06（土）12:00 の並び（下書きを含む）', ETSS_Preview::banner_text( gmmktime( 3, 0, 0, 11, 6, 2027 ), $now, $this->tokyo() ), '年が違う => 年つき' );
		$this->assertStringContainsString( 'プレビューできません', ETSS_Preview::banner_text( null, $now, $this->tokyo() ), '読めない => 理由を伝える' );
	}

	/**
	 * ETSS_Preview::banner_html() のテスト（エスケープと、固定の有無）。
	 *
	 * @return void
	 */
	public function test_banner_html() {
		$html = ETSS_Preview::banner_html( '<b>x</b>', '/top/?a=1&b=2' );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html, '文言はエスケープする' );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'プレビューを終了', $html, '終了のリンクがある' );
		$this->assertStringNotContainsString( 'position:fixed', $html, '本文の先頭の帯は固定しない' );
		$this->assertStringContainsString( 'position:fixed', ETSS_Preview::banner_html( 'x', '/', false ), '下に出す帯は固定する' );
	}

	/**
	 * ETSS_Preview::start() のテスト。権限のある人にだけ時刻が決まり、nocache になる。
	 *
	 * @return void
	 */
	public function test_start() {
		$expected = gmmktime( 12, 0, 0, 11, 6, 2026 );

		$test_cases = array(
			array(
				'test_condition_name' => '引数なし => 何もしない',
				'get'                 => array(),
				'logged_in'           => true,
				'can'                 => true,
				'expected_at'         => null,
				'expected_nocache'    => 0,
			),
			array(
				'test_condition_name' => '権限あり => その時刻でプレビューし、nocache にする',
				'get'                 => array( 'etss_at' => '2026-11-06T12:00' ),
				'logged_in'           => true,
				'can'                 => true,
				'expected_at'         => $expected,
				'expected_nocache'    => 1,
			),
			array(
				'test_condition_name' => '権限なし => 今の並びのまま・nocache にもしない',
				'get'                 => array( 'etss_at' => '2026-11-06T12:00' ),
				'logged_in'           => true,
				'can'                 => false,
				'expected_at'         => null,
				'expected_nocache'    => 0,
			),
			array(
				'test_condition_name' => 'ログアウト中 => 今の並びのまま',
				'get'                 => array( 'etss_at' => '2026-11-06T12:00' ),
				'logged_in'           => false,
				'can'                 => true,
				'expected_at'         => null,
				'expected_nocache'    => 0,
			),
			array(
				'test_condition_name' => '権限ありで形式が違う => 時刻は決めず、nocache にする',
				'get'                 => array( 'etss_at' => 'tomorrow' ),
				'logged_in'           => true,
				'can'                 => true,
				'expected_at'         => null,
				'expected_nocache'    => 1,
			),
			array(
				'test_condition_name' => '権限ありで配列 => 時刻は決めない',
				'get'                 => array( 'etss_at' => array( '2026-11-06T12:00' ) ),
				'logged_in'           => true,
				'can'                 => true,
				'expected_at'         => null,
				'expected_nocache'    => 1,
			),
		);

		foreach ( $test_cases as $case ) {
			etss_test_reset();
			etss_test_reset_wp();
			ETSS_Preview::reset();

			$_GET                           = $case['get'];
			$GLOBALS['etss_test_logged_in'] = $case['logged_in'];
			$GLOBALS['etss_test_can']       = $case['can'];

			ETSS_Preview::start();

			$this->assertSame( $case['expected_at'], ETSS_Preview::at(), $case['test_condition_name'] );
			$this->assertCount( $case['expected_nocache'], $this->nocache_calls(), $case['test_condition_name'] . '（nocache）' );
		}
	}

	/**
	 * ログイン中であることも、権限の判定に含まれることのテスト（権限そのものは D9 と同じ `ETSS_Post_Types::get_capability()`）。
	 *
	 * @return void
	 */
	public function test_current_user_can_preview() {
		$this->assertTrue( ETSS_Preview::current_user_can_preview(), 'ログイン中で権限あり' );

		$GLOBALS['etss_test_logged_in'] = false;
		$this->assertFalse( ETSS_Preview::current_user_can_preview(), 'ログアウト中' );
	}
}
