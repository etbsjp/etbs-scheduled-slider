<?php
/**
 * inc/class-etss-validator.php（スライドの保存時の検査）のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Validator のテスト。
 */
class ValidatorTest extends TestCase {

	/**
	 * 横長・縦長の2枠（横長は必須）を持つひな形。
	 *
	 * @return array ひな形の定義。
	 */
	private function template() {
		return array(
			'slots' => array(
				'wide' => array(
					'id'       => 'wide',
					'label'    => '横長',
					'required' => true,
					'ratio'    => 2.0,
				),
				'tall' => array(
					'id'       => 'tall',
					'label'    => '縦長',
					'required' => false,
					'ratio'    => 0.5,
				),
			),
		);
	}

	/**
	 * 検査に渡す外の情報を作る。添付ファイル 10・11・12 は 2000x1000・1000x2000・1000x1000 の画像で、他は無い。
	 *
	 * @param array $overrides 上書きする項目。
	 * @return array `$context`。
	 */
	private function context( array $overrides = array() ) {
		$attachments = array(
			10 => array(
				'width'  => 2000,
				'height' => 1000,
			),
			11 => array(
				'width'  => 1000,
				'height' => 2000,
			),
			12 => array(
				'width'  => 1000,
				'height' => 1000,
			),
		);

		return array_merge(
			array(
				'slider_found'    => true,
				'template'        => $this->template(),
				'attachment_info' => function ( $id ) use ( $attachments ) {
					return isset( $attachments[ $id ] ) ? $attachments[ $id ] : null;
				},
				'timezone'        => new DateTimeZone( 'Asia/Tokyo' ),
			),
			$overrides
		);
	}

	/**
	 * 正しい送信値を作る。
	 *
	 * @param array $overrides 上書きする項目。
	 * @return array normalize_input() の結果の形。
	 */
	private function valid_input( array $overrides = array() ) {
		return array_merge(
			array(
				'slider_id' => '5',
				'images'    => array( 'wide' => 10 ),
				'url'       => '/news/',
				'start'     => '2026-11-06T12:00',
				'end'       => '2026-12-01T00:00',
			),
			$overrides
		);
	}

	/**
	 * ETSS_Validator::validate_slide() のエラーの欄のテスト。
	 *
	 * @return void
	 */
	public function test_validate_slide_errors() {
		$test_cases = array(
			array(
				'test_condition_name' => 'すべて正しい => エラーなし',
				'title'               => '新春キャンペーン',
				'input'               => $this->valid_input(),
				'context'             => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'タイトルが空白だけ => title',
				'title'               => '   ',
				'input'               => $this->valid_input(),
				'context'             => array(),
				'expected'            => array( 'title' ),
			),
			array(
				'test_condition_name' => 'スライダー未選択 => slider',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'slider_id' => '' ) ),
				'context'             => array(),
				'expected'            => array( 'slider' ),
			),
			array(
				'test_condition_name' => 'スライダーが存在しない => slider',
				'title'               => 'a',
				'input'               => $this->valid_input(),
				'context'             => array( 'slider_found' => false ),
				'expected'            => array( 'slider' ),
			),
			array(
				'test_condition_name' => 'ひな形が登録されていない => slider のみ（画像枠の検査は枠なしで進む）',
				'title'               => 'a',
				'input'               => $this->valid_input(),
				'context'             => array( 'template' => null ),
				'expected'            => array( 'slider' ),
			),
			array(
				'test_condition_name' => '必須の画像枠が空 => image-wide',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'images' => array() ) ),
				'context'             => array(),
				'expected'            => array( 'image-wide' ),
			),
			array(
				'test_condition_name' => '必須でない枠が空 => エラーなし',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'images' => array( 'wide' => 10 ) ) ),
				'context'             => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '添付ファイルが存在しない => image-wide',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'images' => array( 'wide' => 999 ) ) ),
				'context'             => array(),
				'expected'            => array( 'image-wide' ),
			),
			array(
				'test_condition_name' => '同じ添付ファイルを2つの枠に入れた => 後の枠 image-tall',
				'title'               => 'a',
				'input'               => $this->valid_input(
					array(
						'images' => array(
							'wide' => 10,
							'tall' => 10,
						),
					)
				),
				'context'             => array(),
				'expected'            => array( 'image-tall' ),
			),
			array(
				'test_condition_name' => 'リンク先が空 => url',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'url' => '' ) ),
				'context'             => array(),
				'expected'            => array( 'url' ),
			),
			array(
				'test_condition_name' => 'リンク先が // で始まる => url',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'url' => '//example.com/' ) ),
				'context'             => array(),
				'expected'            => array( 'url' ),
			),
			array(
				'test_condition_name' => 'リンク先が https => エラーなし',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'url' => 'https://example.com/a?b=1' ) ),
				'context'             => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '表示開始が空 => start',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'start' => '' ) ),
				'context'             => array(),
				'expected'            => array( 'start' ),
			),
			array(
				'test_condition_name' => '表示開始が存在しない日付（2月30日） => start',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'start' => '2026-02-30T12:00' ) ),
				'context'             => array(),
				'expected'            => array( 'start' ),
			),
			array(
				'test_condition_name' => '表示終了が形式エラー => end',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'end' => 'あした' ) ),
				'context'             => array(),
				'expected'            => array( 'end' ),
			),
			array(
				'test_condition_name' => '表示終了が開始と同じ => end',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'end' => '2026-11-06T12:00' ) ),
				'context'             => array(),
				'expected'            => array( 'end' ),
			),
			array(
				'test_condition_name' => '表示終了が開始の1分後 => エラーなし',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'end' => '2026-11-06T12:01' ) ),
				'context'             => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '表示終了が空（終了なし） => エラーなし',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'end' => '' ) ),
				'context'             => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '開始が空で終了だけある => start のみ（前後の比較はしない）',
				'title'               => 'a',
				'input'               => $this->valid_input( array( 'start' => '' ) ),
				'context'             => array(),
				'expected'            => array( 'start' ),
			),
			array(
				'test_condition_name' => '複数の誤り => 画面の上から下の順',
				'title'               => '',
				'input'               => array(
					'slider_id' => '',
					'images'    => array(),
					'url'       => '',
					'start'     => '',
					'end'       => '',
				),
				'context'             => array(),
				'expected'            => array( 'title', 'slider', 'image-wide', 'url', 'start' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ETSS_Validator::validate_slide( $case['title'], $case['input'], $this->context( $case['context'] ) );
			$this->assertSame( $case['expected'], array_keys( $result['errors'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * エラー文に、欄の名前が入っていること。
	 *
	 * @return void
	 */
	public function test_validate_slide_messages_name_the_field() {
		$result = ETSS_Validator::validate_slide(
			'',
			array(
				'slider_id' => '',
				'images'    => array(),
				'url'       => 'ftp://example.com',
				'start'     => '2026-02-30T12:00',
				'end'       => 'x',
			),
			$this->context()
		);

		$this->assertStringContainsString( '代替テキスト', $result['errors']['title'] );
		$this->assertStringContainsString( 'スライダー', $result['errors']['slider'] );
		$this->assertStringContainsString( '横長の画像', $result['errors']['image-wide'] );
		$this->assertStringContainsString( 'リンク先', $result['errors']['url'] );
		$this->assertSame( '表示開始日時の形式が正しくありません。', $result['errors']['start'] );
		$this->assertStringContainsString( '表示終了日時', $result['errors']['end'] );
	}

	/**
	 * 検査が返す「保存する値」のテスト（UTC 秒への変換・無効な値を保存しない）。
	 *
	 * @return void
	 */
	public function test_validate_slide_values() {
		$tokyo  = new DateTimeZone( 'Asia/Tokyo' );
		$result = ETSS_Validator::validate_slide( 'a', $this->valid_input(), $this->context() );

		$this->assertSame( 5, $result['values']['slider_id'], 'スライダー ID は整数' );
		$this->assertSame( array( 'wide' => 10 ), $result['values']['images'], '有効な画像だけ' );
		$this->assertSame( '/news/', $result['values']['url'] );
		$this->assertSame( ( new DateTimeImmutable( '2026-11-06 12:00:00', $tokyo ) )->getTimestamp(), $result['values']['start'], '日本時間の12:00が UTC 秒になる' );
		$this->assertSame( ( new DateTimeImmutable( '2026-12-01 00:00:00', $tokyo ) )->getTimestamp(), $result['values']['end'] );

		$invalid = ETSS_Validator::validate_slide(
			'a',
			$this->valid_input(
				array(
					'url'    => 'javascript:alert(1)',
					'end'    => '2026-11-06T11:00',
					'images' => array( 'wide' => 999 ),
				)
			),
			$this->context()
		);
		$this->assertSame( '', $invalid['values']['url'], '不正なリンク先は保存する値に入れない' );
		$this->assertSame( 0, $invalid['values']['end'], '開始より前の終了は保存する値に入れない' );
		$this->assertSame( array(), $invalid['values']['images'], '存在しない画像は保存する値に入れない' );
	}

	/**
	 * 夏時間のあるタイムゾーンでも、サイトの時計で受けて UTC 秒に直すこと。
	 *
	 * @return void
	 */
	public function test_validate_slide_uses_site_timezone() {
		$context = $this->context( array( 'timezone' => new DateTimeZone( 'America/New_York' ) ) );

		$ok = ETSS_Validator::validate_slide( 'a', $this->valid_input( array( 'start' => '2026-07-01T09:00' ) ), $context );
		$this->assertSame( gmmktime( 13, 0, 0, 7, 1, 2026 ), $ok['values']['start'], '夏時間（UTC-4）の9:00は13:00 UTC' );

		$gap = ETSS_Validator::validate_slide( 'a', $this->valid_input( array( 'start' => '2026-03-08T02:30' ) ), $context );
		$this->assertArrayHasKey( 'start', $gap['errors'], '夏時間で存在しない時刻は形式エラー' );
	}

	/**
	 * 縦横比の警告のテスト（警告だけで、エラーにはしない）。
	 *
	 * @return void
	 */
	public function test_validate_slide_ratio_warning() {
		$test_cases = array(
			array(
				'test_condition_name' => '想定どおり（2:1 の枠に 2000x1000） => 警告なし',
				'images'              => array( 'wide' => 10 ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '縦長の画像を横長の枠に入れた => 警告（エラーではない）',
				'images'              => array( 'wide' => 11 ),
				'expected'            => array( 'image-wide' ),
			),
			array(
				'test_condition_name' => '正方形の画像を縦長（0.5）の枠に入れた => 警告',
				'images'              => array(
					'wide' => 10,
					'tall' => 12,
				),
				'expected'            => array( 'image-tall' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ETSS_Validator::validate_slide( 'a', $this->valid_input( array( 'images' => $case['images'] ) ), $this->context() );
			$this->assertSame( array(), $result['errors'], $case['test_condition_name'] . '（エラーにはならない）' );
			$this->assertSame( $case['expected'], array_keys( $result['warnings'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Validator::ratio_warning() の境界のテスト。
	 *
	 * @return void
	 */
	public function test_ratio_warning_boundary() {
		$slot = array(
			'label' => '横長',
			'ratio' => 2.0,
		);

		$test_cases = array(
			array(
				'test_condition_name' => '15% 以内（1.7）は警告なし',
				'slot'                => $slot,
				'info'                => array(
					'width'  => 1700,
					'height' => 1000,
				),
				'warns'               => false,
			),
			array(
				'test_condition_name' => '20% ずれ（1.6）は警告',
				'slot'                => $slot,
				'info'                => array(
					'width'  => 1600,
					'height' => 1000,
				),
				'warns'               => true,
			),
			array(
				'test_condition_name' => '想定が無ければ警告なし',
				'slot'                => array( 'label' => 'x' ),
				'info'                => array(
					'width'  => 1,
					'height' => 5,
				),
				'warns'               => false,
			),
			array(
				'test_condition_name' => '寸法が不明なら警告なし',
				'slot'                => $slot,
				'info'                => array(
					'width'  => 0,
					'height' => 0,
				),
				'warns'               => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$warning = ETSS_Validator::ratio_warning( $case['slot'], $case['info'] );
			$this->assertSame( $case['warns'], null !== $warning, $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Validator::decide() のテスト（D7）。
	 *
	 * @return void
	 */
	public function test_decide() {
		$test_cases = array(
			array(
				'test_condition_name' => '下書き保存（エラーあり） => keep（何もしない）',
				'input'               => array( 'draft', true, false ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => '下書き保存（保存済みで表示中のスライドから） => keep',
				'input'               => array( 'draft', true, true ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => 'ゴミ箱 => keep',
				'input'               => array( 'trash', true, true ),
				'expected'            => 'keep',
			),
			array(
				'test_condition_name' => '公開でエラーなし => publish',
				'input'               => array( 'publish', false, false ),
				'expected'            => 'publish',
			),
			array(
				'test_condition_name' => 'future でエラーなし => publish（future を直す）',
				'input'               => array( 'future', false, false ),
				'expected'            => 'publish',
			),
			array(
				'test_condition_name' => '公開でエラーあり・表示中でない => draft（下書きに落とす）',
				'input'               => array( 'publish', true, false ),
				'expected'            => 'draft',
			),
			array(
				'test_condition_name' => 'future でエラーあり・表示中でない => draft',
				'input'               => array( 'future', true, false ),
				'expected'            => 'draft',
			),
			array(
				'test_condition_name' => '公開でエラーあり・保存済みで表示中 => reject（保存しない）',
				'input'               => array( 'publish', true, true ),
				'expected'            => 'reject',
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Validator::decide( $case['input'][0], $case['input'][1], $case['input'][2] );
			$this->assertSame( $case['expected'], $actual, $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Validator::normalize_input() のテスト。
	 *
	 * @return void
	 */
	public function test_normalize_input() {
		$input = ETSS_Validator::normalize_input(
			array(
				'slider_id' => ' 5 ',
				'images'    => array(
					'wide' => '10',
					'tall' => 'abc',
					'x'    => array( 1 ),
					0      => '3',
				),
				'url'       => array( 'array' ),
				'start'     => " 2026-11-06T12:00\n",
			)
		);

		$this->assertSame( '5', $input['slider_id'], '前後の空白を落とす' );
		$this->assertSame(
			array(
				'wide' => 10,
				'tall' => 0,
			),
			$input['images'],
			'数字でないものは 0・配列と整数キーは捨てる'
		);
		$this->assertSame( '', $input['url'], '文字列でなければ空' );
		$this->assertSame( '2026-11-06T12:00', $input['start'] );
		$this->assertSame( '', $input['end'], '無いものは空' );

		$not_array = ETSS_Validator::normalize_input( 'x' );
		$this->assertSame( '', $not_array['slider_id'], '配列でない送信値は全部空' );
	}

	/**
	 * ETSS_Validator::saved_to_input() のテスト（送信値が無い経路を、保存済みの値で検査する）。
	 *
	 * @return void
	 */
	public function test_saved_to_input() {
		$timezone = new DateTimeZone( 'Asia/Tokyo' );
		$start    = ( new DateTimeImmutable( '2026-11-06 12:00:00', $timezone ) )->getTimestamp();

		$input = ETSS_Validator::saved_to_input(
			array(
				'slider_id' => 5,
				'images'    => array( 'wide' => '10' ),
				'url'       => '/a/',
				'start'     => $start,
				'end'       => 0,
			),
			$timezone
		);

		$this->assertSame( '5', $input['slider_id'] );
		$this->assertSame( array( 'wide' => 10 ), $input['images'] );
		$this->assertSame( '2026-11-06T12:00', $input['start'], 'サイトの時計に戻す' );
		$this->assertSame( '', $input['end'], '終了なしは空' );

		// 何も保存されていないスライドは、必須の欄がすべてエラーになる（nonce の無い publish を下書きに落とす根拠）。
		$empty  = ETSS_Validator::saved_to_input( array(), $timezone );
		$result = ETSS_Validator::validate_slide( 'a', $empty, $this->context() );
		$this->assertSame( array( 'slider', 'image-wide', 'url', 'start' ), array_keys( $result['errors'] ) );

		// 保存済みの値がそろっていれば、そのまま通る（nonce の無い publish が通る例）。
		$full   = ETSS_Validator::saved_to_input(
			array(
				'slider_id' => 5,
				'images'    => array( 'wide' => 10 ),
				'url'       => '/a/',
				'start'     => $start,
				'end'       => 0,
			),
			$timezone
		);
		$result = ETSS_Validator::validate_slide( 'a', $full, $this->context() );
		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * validate_slide() の `unsaved`（下書き保存のときに保存済みの値のまま保つ欄）のテスト。
	 *
	 * @return void
	 */
	public function test_validate_slide_unsaved() {
		$test_cases = array(
			array(
				'test_condition_name' => 'すべて正しい => 保存しない欄なし',
				'input'               => array(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'リンク先が example.com => リンク先を保存しない',
				'input'               => array( 'url' => 'example.com' ),
				'expected'            => array( 'url' => 'リンク先は形式が正しくないため保存していません。' ),
			),
			array(
				'test_condition_name' => 'リンク先が空 => 空にしたいだけなので対象外（必須のエラーにはなる）',
				'input'               => array( 'url' => '' ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '表示開始日時が日時でない',
				'input'               => array( 'start' => '2026-02-30T10:00' ),
				'expected'            => array( 'start' => '表示開始日時は形式が正しくないため保存していません。' ),
			),
			array(
				'test_condition_name' => '表示終了日時が日時でない',
				'input'               => array( 'end' => 'あした' ),
				'expected'            => array( 'end' => '表示終了日時は形式が正しくないため保存していません。' ),
			),
			array(
				'test_condition_name' => '表示終了日時が表示開始日時以前',
				'input'               => array( 'end' => '2026-11-06T12:00' ),
				'expected'            => array( 'end' => '表示終了日時は表示開始日時より後ではないため保存していません。' ),
			),
			array(
				'test_condition_name' => '存在しない画像',
				'input'               => array( 'images' => array( 'wide' => 999 ) ),
				'expected'            => array( 'image-wide' => '横長の画像は見つからないため保存していません。' ),
			),
			array(
				'test_condition_name' => '同じ画像を 2 つの枠に入れた => 2 つ目の枠を保存しない',
				'input'               => array(
					'images' => array(
						'wide' => 10,
						'tall' => 10,
					),
				),
				'expected'            => array( 'image-tall' => '縦長の画像は、「横長」と同じ画像のため保存していません。' ),
			),
			array(
				'test_condition_name' => '必須の画像が空 => 外したいだけなので対象外',
				'input'               => array( 'images' => array( 'wide' => 0 ) ),
				'expected'            => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ETSS_Validator::validate_slide( 'a', $this->valid_input( $case['input'] ), $this->context() );

			$this->assertSame( $case['expected'], $result['unsaved'], $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Validator::keep_saved_values() のテスト。
	 *
	 * @return void
	 */
	public function test_keep_saved_values() {
		$values = array(
			'slider_id' => 5,
			'images'    => array( 'wide' => 11 ),
			'url'       => '',
			'start'     => 0,
			'end'       => 0,
		);
		$saved  = array(
			'slider_id' => 5,
			'images'    => array(
				'wide' => 10,
				'tall' => 12,
			),
			'url'       => '/saved/',
			'start'     => 100,
			'end'       => 200,
		);

		$test_cases = array(
			array(
				'test_condition_name' => '保存しない欄なし => 値はそのまま',
				'unsaved'             => array(),
				'expected'            => $values,
			),
			array(
				'test_condition_name' => 'リンク先・表示開始・表示終了を保存しない => 保存済みの値に戻る',
				'unsaved'             => array(
					'url'   => 'x',
					'start' => 'x',
					'end'   => 'x',
				),
				'expected'            => array_merge(
					$values,
					array(
						'url'   => '/saved/',
						'start' => 100,
						'end'   => 200,
					)
				),
			),
			array(
				'test_condition_name' => '画像枠 wide を保存しない => その枠だけ保存済みの画像に戻る',
				'unsaved'             => array( 'image-wide' => 'x' ),
				'expected'            => array_merge( $values, array( 'images' => array( 'wide' => 10 ) ) ),
			),
			array(
				'test_condition_name' => '保存済みに無い画像枠を保存しない => その枠は空のまま',
				'unsaved'             => array( 'image-extra' => 'x' ),
				'expected'            => $values,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Validator::keep_saved_values( $values, $case['unsaved'], $saved ), $case['test_condition_name'] );
		}

		// 保存済みの値が無い（新規）ときは、空・0 に戻る。
		$kept = ETSS_Validator::keep_saved_values( array_merge( $values, array( 'url' => '/x/' ) ), array( 'url' => 'x' ), array() );
		$this->assertSame( '', $kept['url'], '保存済みが無ければ空' );
	}
}
