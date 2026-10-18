<?php
/**
 * inc/class-etss-list.php（一覧の状態の切り替え・件数・要約・0 枚になる時間帯）と、一覧用の日時の文言のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_List のテスト。
 */
class ListTest extends TestCase {

	const NOW = 1000000;

	/**
	 * 一覧の「行」を作る。
	 *
	 * @param int    $id          投稿 ID。
	 * @param string $post_status 投稿ステータス。
	 * @param int    $start       表示開始（UTC の Unix 秒）。
	 * @param int    $end         表示終了（UTC の Unix 秒。0 は終了なし）。
	 * @param array  $extra       上書きする項目。
	 * @return array 行。
	 */
	private function row( $id, $post_status, $start, $end, array $extra = array() ) {
		return array_merge(
			array(
				'id'          => $id,
				'title'       => 'スライド' . $id,
				'post_status' => $post_status,
				'slider_id'   => 1,
				'start'       => $start,
				'end'         => $end,
				'menu_order'  => 0,
			),
			$extra
		);
	}

	/**
	 * 状態が混ざった行を作る。表示中 2・表示予定 1・表示終了 1・下書き 2（うち 1 つは別のスライダー）。
	 *
	 * @return array 行の配列。
	 */
	private function mixed_rows() {
		return array(
			$this->row( 1, 'publish', self::NOW - 100, 0 ),
			$this->row( 2, 'publish', self::NOW - 100, self::NOW + 500 ),
			$this->row( 3, 'publish', self::NOW + 100, 0 ),
			$this->row( 4, 'publish', self::NOW - 500, self::NOW - 1 ),
			$this->row( 5, 'draft', self::NOW + 200, 0 ),
			$this->row( 6, 'draft', 0, 0, array( 'slider_id' => 2 ) ),
		);
	}

	/**
	 * ETSS_List::sanitize_state() のテスト。
	 *
	 * @return void
	 */
	public function test_sanitize_state() {
		$test_cases = array(
			array(
				'test_condition_name' => '表示中 => そのまま',
				'input'               => 'active',
				'expected'            => 'active',
			),
			array(
				'test_condition_name' => 'すべて => そのまま',
				'input'               => 'all',
				'expected'            => 'all',
			),
			array(
				'test_condition_name' => '指定なし（空文字） => 表示終了以外',
				'input'               => '',
				'expected'            => 'not-ended',
			),
			array(
				'test_condition_name' => '知らない値 => 表示終了以外',
				'input'               => 'publish',
				'expected'            => 'not-ended',
			),
			array(
				'test_condition_name' => '文字列でない値（配列） => 表示終了以外',
				'input'               => array( 'active' ),
				'expected'            => 'not-ended',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_List::sanitize_state( $case['input'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_List::count_by_state() のテスト。
	 *
	 * @return void
	 */
	public function test_count_by_state() {
		$test_cases = array(
			array(
				'test_condition_name' => 'すべてのスライダー => 状態ごとの件数と、表示終了以外・すべての件数',
				'slider_id'           => 0,
				'expected'            => array(
					'not-ended' => 5,
					'active'    => 2,
					'scheduled' => 1,
					'draft'     => 2,
					'ended'     => 1,
					'all'       => 6,
				),
			),
			array(
				'test_condition_name' => 'スライダー 1 で絞り込み => そのスライダーの分だけ',
				'slider_id'           => 1,
				'expected'            => array(
					'not-ended' => 4,
					'active'    => 2,
					'scheduled' => 1,
					'draft'     => 1,
					'ended'     => 1,
					'all'       => 5,
				),
			),
			array(
				'test_condition_name' => 'スライドが無いスライダー => すべて 0',
				'slider_id'           => 99,
				'expected'            => array(
					'not-ended' => 0,
					'active'    => 0,
					'scheduled' => 0,
					'draft'     => 0,
					'ended'     => 0,
					'all'       => 0,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_List::count_by_state( $this->mixed_rows(), self::NOW, $case['slider_id'] ), $case['test_condition_name'] );
		}

		// 公開でも、開始が未設定のものは下書きと同じ扱い（表示されないため）。
		$counts = ETSS_List::count_by_state( array( $this->row( 1, 'publish', 0, 0 ) ), self::NOW );
		$this->assertSame( 1, $counts['draft'], '公開で開始が未設定 => 下書きに数える' );
		$this->assertSame( 1, $counts['not-ended'] );
	}

	/**
	 * ETSS_List::ids_for_state() のテスト（切り替え・絞り込み・並び順）。
	 *
	 * @return void
	 */
	public function test_ids_for_state() {
		$test_cases = array(
			array(
				'test_condition_name' => '表示終了以外 => 表示終了を除く。表示順が同じなら表示開始の新しい順（開始も同じなら ID の新しい順）',
				'state'               => 'not-ended',
				'slider_id'           => 0,
				'expected'            => array( 5, 3, 2, 1, 6 ),
			),
			array(
				'test_condition_name' => '表示中だけ',
				'state'               => 'active',
				'slider_id'           => 0,
				'expected'            => array( 2, 1 ),
			),
			array(
				'test_condition_name' => '表示終了だけ',
				'state'               => 'ended',
				'slider_id'           => 0,
				'expected'            => array( 4 ),
			),
			array(
				'test_condition_name' => 'すべて + スライダー 2',
				'state'               => 'all',
				'slider_id'           => 2,
				'expected'            => array( 6 ),
			),
			array(
				'test_condition_name' => '当てはまるものが無い => 空配列',
				'state'               => 'scheduled',
				'slider_id'           => 2,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_List::ids_for_state( $this->mixed_rows(), self::NOW, $case['state'], $case['slider_id'] ), $case['test_condition_name'] );
		}

		// 表示順（小さい順）が、表示開始より優先される。
		$ordered = array(
			$this->row( 1, 'publish', self::NOW - 10, 0, array( 'menu_order' => 2 ) ),
			$this->row( 2, 'publish', self::NOW - 20, 0, array( 'menu_order' => 1 ) ),
			$this->row( 3, 'publish', self::NOW - 20, 0, array( 'menu_order' => 1 ) ),
		);
		$this->assertSame( array( 3, 2, 1 ), ETSS_List::ids_for_state( $ordered, self::NOW, 'all' ), '表示順 → 表示開始の新しい順 → ID の新しい順' );
	}

	/**
	 * ETSS_List::summarize_slider() のテスト（表示中の枚数と次の切り替え）。
	 *
	 * @return void
	 */
	public function test_summarize_slider() {
		$now = self::NOW;

		$test_cases = array(
			array(
				'test_condition_name' => '表示中 2 枚・次は表示予定の開始 => 開始が次の切り替え',
				'rows'                => $this->mixed_rows(),
				'expected_active'     => 2,
				'expected_next'       => array(
					'time'   => $now + 100,
					'events' => array(
						array(
							'kind'  => 'start',
							'title' => 'スライド3',
							'id'    => 3,
						),
					),
				),
			),
			array(
				'test_condition_name' => '表示予定が無く、表示中に終了がある => 終了が次の切り替え',
				'rows'                => array( $this->row( 1, 'publish', $now - 10, $now + 300 ) ),
				'expected_active'     => 1,
				'expected_next'       => array(
					'time'   => $now + 300,
					'events' => array(
						array(
							'kind'  => 'end',
							'title' => 'スライド1',
							'id'    => 1,
						),
					),
				),
			),
			array(
				'test_condition_name' => '同じ時刻に開始と終了 => 開始を先に並べる',
				'rows'                => array(
					$this->row( 1, 'publish', $now - 10, $now + 300 ),
					$this->row( 2, 'publish', $now + 300, 0 ),
				),
				'expected_active'     => 1,
				'expected_next'       => array(
					'time'   => $now + 300,
					'events' => array(
						array(
							'kind'  => 'start',
							'title' => 'スライド2',
							'id'    => 2,
						),
						array(
							'kind'  => 'end',
							'title' => 'スライド1',
							'id'    => 1,
						),
					),
				),
			),
			array(
				'test_condition_name' => '下書きの開始は切り替えに数えない => 次の切り替えなし',
				'rows'                => array(
					$this->row( 1, 'publish', $now - 10, 0 ),
					$this->row( 2, 'draft', $now + 100, 0 ),
				),
				'expected_active'     => 1,
				'expected_next'       => null,
			),
			array(
				'test_condition_name' => '切り替え時刻ちょうど（いま）は数えない',
				'rows'                => array( $this->row( 1, 'publish', $now - 10, $now + 0 ) ),
				'expected_active'     => 0,
				'expected_next'       => null,
			),
			array(
				'test_condition_name' => 'スライドが無い => 0 枚・次の切り替えなし',
				'rows'                => array(),
				'expected_active'     => 0,
				'expected_next'       => null,
			),
		);

		foreach ( $test_cases as $case ) {
			// 1 つのスライダーに属する行だけを渡す（絞り込みは呼び出し側の役目）。
			$rows   = array_values(
				array_filter(
					$case['rows'],
					function ( $row ) {
						return 1 === $row['slider_id'];
					}
				)
			);
			$result = ETSS_List::summarize_slider( $rows, $now );

			$this->assertSame( $case['expected_active'], $result['active'], $case['test_condition_name'] . '（表示中の枚数）' );
			$this->assertSame( $case['expected_next'], $result['next'], $case['test_condition_name'] . '（次の切り替え）' );
		}
	}

	/**
	 * ETSS_List::zero_windows() のテスト（これから 0 枚になる時間帯）。
	 *
	 * @return void
	 */
	public function test_zero_windows() {
		$now = self::NOW;

		$test_cases = array(
			array(
				'test_condition_name' => '終了のあと、次のスライドの開始まで間が空く => その間が 0 枚',
				'rows'                => array(
					$this->row( 1, 'publish', $now - 10, $now + 100 ),
					$this->row( 2, 'publish', $now + 300, 0 ),
				),
				'expected'            => array(
					array(
						'from'    => $now + 100,
						'to'      => $now + 300,
						'current' => false,
					),
				),
			),
			array(
				'test_condition_name' => '終了と開始が同じ時刻 => 切れ目なし',
				'rows'                => array(
					$this->row( 1, 'publish', $now - 10, $now + 100 ),
					$this->row( 2, 'publish', $now + 100, 0 ),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '最後のスライドに終了がある => 終了以降ずっと 0 枚',
				'rows'                => array( $this->row( 1, 'publish', $now - 10, $now + 100 ) ),
				'expected'            => array(
					array(
						'from'    => $now + 100,
						'to'      => null,
						'current' => false,
					),
				),
			),
			array(
				'test_condition_name' => '終了なしの表示中が 1 枚ある => 0 枚にならない',
				'rows'                => array(
					$this->row( 1, 'publish', $now - 10, 0 ),
					$this->row( 2, 'publish', $now - 10, $now + 100 ),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'いま表示中が無く、これから表示予定がある => いまから開始までが 0 枚（current）',
				'rows'                => array( $this->row( 1, 'publish', $now + 500, 0 ) ),
				'expected'            => array(
					array(
						'from'    => $now,
						'to'      => $now + 500,
						'current' => true,
					),
				),
			),
			array(
				'test_condition_name' => '下書きしか無い => いまから以降ずっと 0 枚（current）',
				'rows'                => array( $this->row( 1, 'draft', $now + 100, 0 ) ),
				'expected'            => array(
					array(
						'from'    => $now,
						'to'      => null,
						'current' => true,
					),
				),
			),
			array(
				'test_condition_name' => '間が 2 回空く => 時間帯が 2 つ（最後は以降ずっと）',
				'rows'                => array(
					$this->row( 1, 'publish', $now - 10, $now + 100 ),
					$this->row( 2, 'publish', $now + 200, $now + 300 ),
				),
				'expected'            => array(
					array(
						'from'    => $now + 100,
						'to'      => $now + 200,
						'current' => false,
					),
					array(
						'from'    => $now + 300,
						'to'      => null,
						'current' => false,
					),
				),
			),
			array(
				'test_condition_name' => 'スライドが 1 枚も無いスライダー => 警告しない',
				'rows'                => array(),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_List::zero_windows( $case['rows'], $now ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_List::drafts_with_start() のテスト。
	 *
	 * @return void
	 */
	public function test_drafts_with_start() {
		$now  = self::NOW;
		$rows = array(
			$this->row( 1, 'draft', $now + 500, 0 ),
			$this->row( 2, 'draft', 0, 0 ),
			$this->row( 3, 'publish', $now + 100, 0 ),
			$this->row( 4, 'draft', $now + 100, 0 ),
			$this->row( 5, 'draft', $now - 500, $now - 1 ),
			$this->row( 6, 'draft', $now - 500, $now + 50 ),
			$this->row( 7, 'pending', $now + 700, 0 ),
		);

		$ids = array_column( ETSS_List::drafts_with_start( $rows, $now ), 'id' );

		// 4（開始が早い）→ 6 → 1 → 7。2（開始なし）・3（公開）・5（終了済み）は含めない。
		$this->assertSame( array( 6, 4, 1, 7 ), $ids );
	}

	/**
	 * ETSS_List::select_sliders() のテスト（スライダーの絞り込みに、要約と警告も合わせる）。
	 *
	 * @return void
	 */
	public function test_select_sliders() {
		$sliders = array(
			10 => 'トップ',
			20 => '特集',
			30 => 'お知らせ',
		);

		$test_cases = array(
			array(
				'test_condition_name' => '絞り込みなし（0）は全スライダー',
				'slider_id'           => 0,
				'expected'            => $sliders,
			),
			array(
				'test_condition_name' => '絞り込み中は、そのスライダーだけ',
				'slider_id'           => 20,
				'expected'            => array( 20 => '特集' ),
			),
			array(
				'test_condition_name' => '文字列の ID でも同じ',
				'slider_id'           => '30',
				'expected'            => array( 30 => 'お知らせ' ),
			),
			array(
				'test_condition_name' => '存在しないスライダーなら空',
				'slider_id'           => 99,
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_List::select_sliders( $sliders, $case['slider_id'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_List::draft_reasons() と format_status() のテスト。
	 *
	 * @return void
	 */
	public function test_draft_reasons_and_format_status() {
		$test_cases = array(
			array(
				'test_condition_name' => '必須の画像が無い => 下書き（画像不足）',
				'errors'              => array( 'image-wide' => 'x' ),
				'input'               => array(
					'url'   => '/a/',
					'start' => '2026-11-06T12:00',
				),
				'expected'            => '下書き（画像不足）',
			),
			array(
				'test_condition_name' => '画像枠が複数足りない => 画像不足は 1 回だけ',
				'errors'              => array(
					'image-wide' => 'x',
					'image-tall' => 'y',
				),
				'input'               => array(),
				'expected'            => '下書き（画像不足）',
			),
			array(
				'test_condition_name' => 'リンク先が空と不正 => 言い分ける',
				'errors'              => array( 'url' => 'x' ),
				'input'               => array( 'url' => '' ),
				'expected'            => '下書き（リンク先なし）',
			),
			array(
				'test_condition_name' => 'リンク先が不正',
				'errors'              => array( 'url' => 'x' ),
				'input'               => array( 'url' => 'example.com' ),
				'expected'            => '下書き（リンク先が不正）',
			),
			array(
				'test_condition_name' => '理由が 2 つ => 「・」でつなぐ',
				'errors'              => array(
					'image-wide' => 'x',
					'start'      => 'y',
				),
				'input'               => array( 'start' => '' ),
				'expected'            => '下書き（画像不足・表示開始日時なし）',
			),
			array(
				'test_condition_name' => '理由が 3 つ => 2 つまで出して残りは件数',
				'errors'              => array(
					'title'      => 'x',
					'image-wide' => 'y',
					'url'        => 'z',
				),
				'input'               => array( 'url' => '' ),
				'expected'            => '下書き（代替テキストなし・画像不足…ほか1件）',
			),
			array(
				'test_condition_name' => '検査には通るが下書き => 表示に登録前',
				'errors'              => array(),
				'input'               => array(),
				'expected'            => '下書き（表示に登録前）',
			),
		);

		foreach ( $test_cases as $case ) {
			$reasons = ETSS_List::draft_reasons( $case['errors'], $case['input'] );
			$this->assertSame( $case['expected'], ETSS_List::format_status( 'draft', $reasons ), $case['test_condition_name'] );
		}

		$this->assertSame( '表示中', ETSS_List::format_status( 'active', array( '画像不足' ) ), '下書き以外には理由を付けない' );
		$this->assertSame( '表示予定', ETSS_List::format_status( 'scheduled', array() ) );
	}

	/**
	 * ETSS_List::describe_next() のテスト。
	 *
	 * @return void
	 */
	public function test_describe_next() {
		$tokyo = new DateTimeZone( 'Asia/Tokyo' );
		$now   = ( new DateTimeImmutable( '2026-11-03 08:00:00', $tokyo ) )->getTimestamp();
		$time  = ( new DateTimeImmutable( '2026-11-06 12:00:00', $tokyo ) )->getTimestamp();

		$test_cases = array(
			array(
				'test_condition_name' => '開始 => 日時（残り）『タイトル』が表示開始',
				'next'                => array(
					'time'   => $time,
					'events' => array(
						array(
							'kind'  => 'start',
							'title' => '春のキャンペーン',
							'id'    => 1,
						),
					),
				),
				'expected'            => '次の切り替え 11/06（金）12:00（あと3日）『春のキャンペーン』が表示開始',
			),
			array(
				'test_condition_name' => '終了が複数 => 先頭のタイトルとほか件数',
				'next'                => array(
					'time'   => $time,
					'events' => array(
						array(
							'kind'  => 'end',
							'title' => 'A',
							'id'    => 1,
						),
						array(
							'kind'  => 'end',
							'title' => 'B',
							'id'    => 2,
						),
					),
				),
				'expected'            => '次の切り替え 11/06（金）12:00（あと3日）『A』が表示終了（ほか1件）',
			),
			array(
				'test_condition_name' => 'タイトルが長い => 省略する',
				'next'                => array(
					'time'   => $time,
					'events' => array(
						array(
							'kind'  => 'start',
							'title' => str_repeat( 'あ', 30 ),
							'id'    => 1,
						),
					),
				),
				'expected'            => '次の切り替え 11/06（金）12:00（あと3日）『' . str_repeat( 'あ', 24 ) . '…』が表示開始',
			),
			array(
				'test_condition_name' => 'タイトルが空 => 代わりの文言',
				'next'                => array(
					'time'   => $time,
					'events' => array(
						array(
							'kind'  => 'start',
							'title' => '',
							'id'    => 1,
						),
					),
				),
				'expected'            => '次の切り替え 11/06（金）12:00（あと3日）『（代替テキストなし）』が表示開始',
			),
			array(
				'test_condition_name' => '次の切り替えなし',
				'next'                => null,
				'expected'            => '次の切り替えはありません',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_List::describe_next( $case['next'], $now, $tokyo ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_List::describe_zero_window() のテスト。
	 *
	 * @return void
	 */
	public function test_describe_zero_window() {
		$tokyo = new DateTimeZone( 'Asia/Tokyo' );
		$now   = ( new DateTimeImmutable( '2026-11-03 08:00:00', $tokyo ) )->getTimestamp();
		$from  = ( new DateTimeImmutable( '2026-11-06 12:00:00', $tokyo ) )->getTimestamp();
		$to    = ( new DateTimeImmutable( '2026-11-07 09:30:00', $tokyo ) )->getTimestamp();

		$test_cases = array(
			array(
				'test_condition_name' => 'これから先の時間帯',
				'window'              => array(
					'from'    => $from,
					'to'      => $to,
					'current' => false,
				),
				'expected'            => '11/06（金）12:00 から 11/07（土）09:30 まで、表示するスライドが 0 枚になります。',
			),
			array(
				'test_condition_name' => 'これから先で、以降ずっと',
				'window'              => array(
					'from'    => $from,
					'to'      => null,
					'current' => false,
				),
				'expected'            => '11/06（金）12:00 以降、表示するスライドが 0 枚になります。',
			),
			array(
				'test_condition_name' => 'いま 0 枚で、あとで表示される',
				'window'              => array(
					'from'    => $now,
					'to'      => $to,
					'current' => true,
				),
				'expected'            => 'いま 0 枚です。11/07（土）09:30 から表示されます。',
			),
			array(
				'test_condition_name' => 'いま 0 枚で、予定も無い',
				'window'              => array(
					'from'    => $now,
					'to'      => null,
					'current' => true,
				),
				'expected'            => 'いま 0 枚です。表示するスライドがありません。',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_List::describe_zero_window( $case['window'], $now, $tokyo ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Display::format_list_datetime() のテスト（年が違うときだけ年を付ける）。
	 *
	 * @return void
	 */
	public function test_format_list_datetime() {
		$tokyo = new DateTimeZone( 'Asia/Tokyo' );
		$now   = ( new DateTimeImmutable( '2026-12-31 20:00:00', $tokyo ) )->getTimestamp();

		$test_cases = array(
			array(
				'test_condition_name' => '同じ年 => 年なし',
				'time'                => '2026-11-06 12:00:00',
				'expected'            => '11/06（金）12:00',
			),
			array(
				'test_condition_name' => '来年 => 年を付ける',
				'time'                => '2027-01-01 00:00:00',
				'expected'            => '2027/01/01（金）00:00',
			),
			array(
				'test_condition_name' => 'UTC では前年でも、サイトの時計で同じ年なら年なし',
				'time'                => '2026-01-01 05:00:00',
				'expected'            => '01/01（木）05:00',
			),
		);

		foreach ( $test_cases as $case ) {
			$timestamp = ( new DateTimeImmutable( $case['time'], $tokyo ) )->getTimestamp();
			$this->assertSame( $case['expected'], ETSS_Display::format_list_datetime( $timestamp, $now, $tokyo ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Display::format_remaining_short() のテスト。
	 *
	 * @return void
	 */
	public function test_format_remaining_short() {
		$test_cases = array(
			array(
				'test_condition_name' => '3日4時間 => あと3日',
				'diff'                => 3 * 86400 + 4 * 3600,
				'expected'            => 'あと3日',
			),
			array(
				'test_condition_name' => 'ちょうど1日 => あと1日',
				'diff'                => 86400,
				'expected'            => 'あと1日',
			),
			array(
				'test_condition_name' => '1日未満 => 時間',
				'diff'                => 86399,
				'expected'            => 'あと23時間',
			),
			array(
				'test_condition_name' => '1時間未満 => 分',
				'diff'                => 3599,
				'expected'            => 'あと59分',
			),
			array(
				'test_condition_name' => '1分未満 => まもなく',
				'diff'                => 59,
				'expected'            => 'まもなく',
			),
			array(
				'test_condition_name' => '0 以下 => まもなく',
				'diff'                => 0,
				'expected'            => 'まもなく',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Display::format_remaining_short( $case['diff'] ), $case['test_condition_name'] );
		}
	}
}
