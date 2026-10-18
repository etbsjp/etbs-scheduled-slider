<?php
/**
 * inc/class-etss-slide-admin.php の保存フック（D7 を守る部分）のテスト。
 *
 * WordPress の関数は tests/phpunit/bootstrap.php のスタブで置き換えている。
 * 実際の WordPress の中での動き（管理画面の画面・リダイレクト）は、検証サイトで確かめる。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Slide_Admin の保存フックのテスト。
 */
class SlideAdminTest extends TestCase {

	const SLIDER_ID = 5;
	const SLIDE_ID  = 100;
	const IMAGE_ID  = 10;

	// 保存済みにしておく表示開始・表示終了（UTC の Unix 秒）。表示開始は過去、表示終了は 2100 年。
	const SAVED_START = 1700000000;
	const SAVED_END   = 4102444800;

	/**
	 * スタブの状態を空にし、スライダー（ひな形 vk-image）と画像を用意する。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		etss_test_reset();
		ETSS_Templates::reset();
		$this->reset_world();
	}

	/**
	 * 投稿・メタ・送信値のスタブを空にし、スライダー（ひな形 vk-image）と画像を用意し直す。繰り返しのテストの各回の頭でも使う。
	 *
	 * @return void
	 */
	private function reset_world() {
		etss_test_reset_wp();
		ETSS_Slide_Admin::reset_entries();

		$GLOBALS['etss_test_posts'][ self::SLIDER_ID ] = (object) array(
			'post_type'   => 'etss_slider',
			'post_status' => 'publish',
		);
		$GLOBALS['etss_test_attach'][ self::IMAGE_ID ] = array(
			'width'  => 400,
			'height' => 200,
		);
	}

	/**
	 * 保存済みの、表示中のスライドを用意する。
	 *
	 * @return object 投稿。
	 */
	private function active_slide() {
		$post = (object) array(
			'post_type'   => 'etss_slide',
			'post_status' => 'publish',
			'post_title'  => '保存済みのタイトル',
			'post_name'   => 'saved',
			'menu_order'  => 3,
		);

		$GLOBALS['etss_test_posts'][ self::SLIDE_ID ] = $post;
		update_post_meta( self::SLIDE_ID, ETSS_Meta::SLIDER_ID, self::SLIDER_ID );
		update_post_meta( self::SLIDE_ID, ETSS_Meta::IMAGES, array( 'image' => self::IMAGE_ID ) );
		update_post_meta( self::SLIDE_ID, ETSS_Meta::URL, '/saved/' );
		update_post_meta( self::SLIDE_ID, ETSS_Meta::START, self::SAVED_START );
		update_post_meta( self::SLIDE_ID, ETSS_Meta::END, 0 );

		return $post;
	}

	/**
	 * 編集画面から送信されたことにする（nonce が正しい）。
	 *
	 * @param array $fields 上書きする欄。
	 * @return void
	 */
	private function post_from_form( array $fields = array() ) {
		$_POST = array(
			ETSS_Slide_Admin::NONCE_NAME => 'good',
			ETSS_Slide_Admin::FIELD      => array_merge(
				array(
					'slider_id' => (string) self::SLIDER_ID,
					'images'    => array( 'image' => (string) self::IMAGE_ID ),
					'url'       => '/new/',
					'start'     => gmdate( 'Y-m-d\TH:i', time() - 3600 ),
					'end'       => '',
				),
				$fields
			),
		);
	}

	/**
	 * 保存しようとしている投稿データを作る。
	 *
	 * @param array $overrides 上書きする項目。
	 * @return array wp_insert_post_data の `$data`。
	 */
	private function insert_data( array $overrides = array() ) {
		return array_merge(
			array(
				'post_type'     => 'etss_slide',
				'post_status'   => 'publish',
				'post_title'    => '新しいタイトル',
				'post_name'     => 'new',
				'menu_order'    => 9,
				'post_password' => '',
			),
			$overrides
		);
	}

	/**
	 * 表示中のスライドの更新が検査に通らなければ、状態・タイトル・表示順が保存済みのものに戻ること。
	 *
	 * @return void
	 */
	public function test_reject_restores_status_and_title() {
		$saved = $this->active_slide();
		$this->post_from_form( array( 'url' => '' ) );

		$data = ETSS_Slide_Admin::filter_insert_post_data( $this->insert_data(), array( 'ID' => self::SLIDE_ID ) );

		$this->assertSame( $saved->post_title, $data['post_title'], 'タイトルが戻る' );
		$this->assertSame( 'publish', $data['post_status'], '状態が保たれる' );
		$this->assertSame( 3, $data['menu_order'], '表示順が戻る' );
		$this->assertSame( 'saved', $data['post_name'], 'スラッグが戻る' );
	}

	/**
	 * reject のとき、値のメタは書かれず、meta_input で書かれた分も保存済みの値に戻ること。
	 *
	 * @return void
	 */
	public function test_reject_does_not_write_meta_and_undoes_meta_input() {
		$post = $this->active_slide();
		$this->post_from_form( array( 'url' => '' ) );

		ETSS_Slide_Admin::filter_insert_post_data( $this->insert_data(), array( 'ID' => self::SLIDE_ID ) );

		// wp_insert_post() は、フィルターのあと save_post の前に meta_input を書く。
		update_post_meta( self::SLIDE_ID, ETSS_Meta::URL, '/evil/' );
		delete_post_meta( self::SLIDE_ID, ETSS_Meta::END );

		ETSS_Slide_Admin::save( self::SLIDE_ID, $post );

		$this->assertSame( '/saved/', get_post_meta( self::SLIDE_ID, ETSS_Meta::URL, true ), 'meta_input の書き換えが戻る' );
		$this->assertSame( array( 0 ), get_post_meta( self::SLIDE_ID, ETSS_Meta::END, false ), '消されたメタも戻る' );
		$last = get_post_meta( self::SLIDE_ID, ETSS_Meta::LAST_ERRORS, true );
		$this->assertTrue( $last['protected'], '保護されたことを残す' );
		$this->assertSame( '新しいタイトル', $last['input']['title'], '入力されたタイトルを画面に戻すため残す' );
	}

	/**
	 * 表示中でないスライドが検査に通らなければ、下書きに落ちること。
	 *
	 * @return void
	 */
	public function test_invalid_publish_of_inactive_slide_drops_to_draft() {
		$this->post_from_form( array( 'url' => '' ) );
		$GLOBALS['etss_test_posts'][ self::SLIDE_ID ] = (object) array(
			'post_type'   => 'etss_slide',
			'post_status' => 'draft',
		);

		$data = ETSS_Slide_Admin::filter_insert_post_data( $this->insert_data(), array( 'ID' => self::SLIDE_ID ) );

		$this->assertSame( 'draft', $data['post_status'] );
		$this->assertSame( '新しいタイトル', $data['post_title'], '下書きに落とすときは、入力は保存する' );
	}

	/**
	 * 編集画面を通っていない保存（nonce なし）は、値のメタを書かないこと。
	 *
	 * @return void
	 */
	public function test_save_without_nonce_writes_nothing() {
		$post  = $this->active_slide();
		$_POST = array(
			ETSS_Slide_Admin::FIELD => array(
				'url'   => '/evil/',
				'start' => '2030-01-01T00:00',
			),
		);

		$before = $GLOBALS['etss_test_meta'][ self::SLIDE_ID ];
		ETSS_Slide_Admin::save( self::SLIDE_ID, $post );

		$this->assertSame( $before, $GLOBALS['etss_test_meta'][ self::SLIDE_ID ], '何も書かれない' );
	}

	/**
	 * nonce が無く、保存済みの値がそろっていない publish は、下書きに落ちること。
	 *
	 * @return void
	 */
	public function test_publish_without_nonce_and_without_saved_values_drops_to_draft() {
		$GLOBALS['etss_test_posts'][ self::SLIDE_ID ] = (object) array(
			'post_type'   => 'etss_slide',
			'post_status' => 'auto-draft',
		);

		$data = ETSS_Slide_Admin::filter_insert_post_data( $this->insert_data(), array( 'ID' => self::SLIDE_ID ) );

		$this->assertSame( 'draft', $data['post_status'] );
	}

	/**
	 * nonce が無くても、meta_input で必要な値が同時に渡されていれば通ること。
	 *
	 * @return void
	 */
	public function test_publish_with_meta_input_passes_validation() {
		$GLOBALS['etss_test_posts'][ self::SLIDE_ID ] = (object) array(
			'post_type'   => 'etss_slide',
			'post_status' => 'auto-draft',
		);

		$data = ETSS_Slide_Admin::filter_insert_post_data(
			$this->insert_data(),
			array(
				'ID'         => self::SLIDE_ID,
				'meta_input' => array(
					ETSS_Meta::SLIDER_ID => self::SLIDER_ID,
					ETSS_Meta::IMAGES    => array( 'image' => self::IMAGE_ID ),
					ETSS_Meta::URL       => '/a/',
					ETSS_Meta::START     => time() - 100,
					ETSS_Meta::END       => 0,
				),
			)
		);

		$this->assertSame( 'publish', $data['post_status'] );
	}

	/**
	 * 編集画面から正常に送信されたときは、値が保存されること。
	 *
	 * @return void
	 */
	public function test_save_with_nonce_and_valid_input_writes_values() {
		$post = $this->active_slide();
		$this->post_from_form();

		ETSS_Slide_Admin::filter_insert_post_data( $this->insert_data(), array( 'ID' => self::SLIDE_ID ) );
		ETSS_Slide_Admin::save( self::SLIDE_ID, $post );

		$this->assertSame( '/new/', get_post_meta( self::SLIDE_ID, ETSS_Meta::URL, true ) );
		$this->assertSame( self::SLIDER_ID, get_post_meta( self::SLIDE_ID, ETSS_Meta::SLIDER_ID, true ) );
		$this->assertSame( array( 'image' => self::IMAGE_ID ), get_post_meta( self::SLIDE_ID, ETSS_Meta::IMAGES, true ) );
		$this->assertSame( array(), get_post_meta( self::SLIDE_ID, ETSS_Meta::LAST_ERRORS, false ), '成功したら前回のエラーを消す' );
	}

	/**
	 * 下書き保存は、検査に通らなくても値を保存し、エラーは残さないこと。
	 *
	 * @return void
	 */
	public function test_draft_save_writes_values_without_errors() {
		$post              = $this->active_slide();
		$post->post_status = 'draft';
		$this->post_from_form( array( 'url' => '' ) );

		ETSS_Slide_Admin::save( self::SLIDE_ID, $post );

		$this->assertSame( array(), get_post_meta( self::SLIDE_ID, ETSS_Meta::URL, false ), '空のリンク先はメタを残さない' );
		$this->assertSame( array(), get_post_meta( self::SLIDE_ID, ETSS_Meta::LAST_ERRORS, false ), 'エラーは残さない' );
	}

	/**
	 * future は publish に直り、投稿日時が今になること。private とパスワードは外れること。
	 *
	 * @return void
	 */
	public function test_future_private_and_password_are_normalized() {
		$this->active_slide();
		$this->post_from_form();

		$data = ETSS_Slide_Admin::filter_insert_post_data( $this->insert_data( array( 'post_status' => 'future' ) ), array( 'ID' => self::SLIDE_ID ) );
		$this->assertSame( 'publish', $data['post_status'] );
		$this->assertSame( current_time( 'mysql' ), $data['post_date'] );

		ETSS_Slide_Admin::reset_entries();
		$data = ETSS_Slide_Admin::filter_insert_post_data(
			$this->insert_data(
				array(
					'post_status'   => 'private',
					'post_password' => 'secret',
				)
			),
			array( 'ID' => self::SLIDE_ID )
		);
		$this->assertSame( 'publish', $data['post_status'], 'private は publish に直る' );
		$this->assertSame( '', $data['post_password'] );
	}

	/**
	 * スライド以外の投稿タイプには手を出さないこと。
	 *
	 * @return void
	 */
	public function test_other_post_types_are_untouched() {
		$data = array(
			'post_type'   => 'page',
			'post_status' => 'private',
		);

		$this->assertSame( $data, ETSS_Slide_Admin::filter_insert_post_data( $data, array( 'ID' => 1 ) ) );
	}

	/**
	 * reject のとき、バックスラッシュを含む保存済みタイトルは slash して戻す（フィルターのあとに unslash されるため）。
	 *
	 * @return void
	 */
	public function test_reject_slashes_saved_title() {
		$saved             = $this->active_slide();
		$saved->post_title = 'A\\B "quoted"';

		$data = ETSS_Slide_Admin::apply_decision( $this->insert_data(), 'publish', 'reject', $saved, '', '' );

		$this->assertSame( addslashes( $saved->post_title ), $data['post_title'] );
		$this->assertSame( $saved->post_title, stripslashes( $data['post_title'] ), 'unslash すると保存済みの値に戻る' );
	}

	/**
	 * URL の引数を読む部分: etss_notice の有無と、etss_slider の整数化。
	 *
	 * @return void
	 */
	public function test_request_readers_for_get_arguments() {
		$this->assertFalse( ETSS_Slide_Admin::read_request_notice_flag() );
		$this->assertSame( 0, ETSS_Slide_Admin::read_request_slider_id() );

		$_GET = array(
			ETSS_Slide_Admin::NOTICE_ARG => '1',
			'etss_slider'                => ' 5 ',
		);
		$this->assertTrue( ETSS_Slide_Admin::read_request_notice_flag() );
		$this->assertSame( 5, ETSS_Slide_Admin::read_request_slider_id() );

		$_GET = array( 'etss_slider' => 'abc' );
		$this->assertSame( 0, ETSS_Slide_Admin::read_request_slider_id() );
	}

	/**
	 * 保存直後の画面でなければ、保存されたエラーは出さない。出すときは保存した値を読む。
	 *
	 * @return void
	 */
	public function test_read_last_errors_only_when_shown() {
		update_post_meta( self::SLIDE_ID, ETSS_Meta::LAST_ERRORS, array( 'errors' => array( 'url' => 'x' ) ) );

		$this->assertSame( array(), ETSS_Slide_Admin::read_last_errors( self::SLIDE_ID, false )['errors'] );
		$this->assertSame( array( 'url' => 'x' ), ETSS_Slide_Admin::read_last_errors( self::SLIDE_ID, true )['errors'] );
	}

	/**
	 * 保存の入口の判定（nonce・自動保存・権限）のテスト。通らなければ値のメタを書かないこと。
	 *
	 * @return void
	 */
	public function test_save_entry_guards() {
		$test_cases = array(
			array(
				'test_condition_name' => 'nonce が正しい・権限あり・通常の保存 => 値を保存する',
				'nonce'               => 'good',
				'autosave'            => false,
				'can'                 => true,
				'expected_url'        => '/new/',
			),
			array(
				'test_condition_name' => 'nonce が合わない => 何も書かない',
				'nonce'               => 'bad',
				'autosave'            => false,
				'can'                 => true,
				'expected_url'        => '/saved/',
			),
			array(
				'test_condition_name' => 'nonce が無い => 何も書かない',
				'nonce'               => null,
				'autosave'            => false,
				'can'                 => true,
				'expected_url'        => '/saved/',
			),
			array(
				'test_condition_name' => '自動保存 => 何も書かない',
				'nonce'               => 'good',
				'autosave'            => true,
				'can'                 => true,
				'expected_url'        => '/saved/',
			),
			array(
				'test_condition_name' => '権限が無い => 何も書かない',
				'nonce'               => 'good',
				'autosave'            => false,
				'can'                 => false,
				'expected_url'        => '/saved/',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->reset_world();
			$post = $this->active_slide();
			$this->post_from_form();

			if ( null === $case['nonce'] ) {
				unset( $_POST[ ETSS_Slide_Admin::NONCE_NAME ] );
			} else {
				$_POST[ ETSS_Slide_Admin::NONCE_NAME ] = $case['nonce'];
			}
			$GLOBALS['etss_test_autosave'] = $case['autosave'];
			$GLOBALS['etss_test_can']      = $case['can'];

			ETSS_Slide_Admin::save( self::SLIDE_ID, $post );

			$this->assertSame( $case['expected_url'], get_post_meta( self::SLIDE_ID, ETSS_Meta::URL, true ), $case['test_condition_name'] );
		}
	}

	/**
	 * 保存後の移動先のテスト。失敗・保存しなかった欄があるときは `message` を外し、`etss_notice` を付けること。
	 *
	 * @return void
	 */
	public function test_filter_redirect_location() {
		$base = 'http://example.org/wp-admin/post.php?post=100&action=edit&message=10';

		$test_cases = array(
			array(
				'test_condition_name' => 'エラーがある => message を外して etss_notice を付ける',
				'last'                => array( 'errors' => array( 'url' => 'x' ) ),
				'post_type'           => 'etss_slide',
				'expected_query'      => array(
					'post'        => '100',
					'action'      => 'edit',
					'etss_notice' => '1',
				),
			),
			array(
				'test_condition_name' => '保存しなかった欄がある（下書き保存） => message を外して etss_notice を付ける',
				'last'                => array( 'unsaved' => array( 'url' => 'x' ) ),
				'post_type'           => 'etss_slide',
				'expected_query'      => array(
					'post'        => '100',
					'action'      => 'edit',
					'etss_notice' => '1',
				),
			),
			array(
				'test_condition_name' => '警告だけ => message は残して etss_notice を付ける',
				'last'                => array( 'warnings' => array( 'image-wide' => 'x' ) ),
				'post_type'           => 'etss_slide',
				'expected_query'      => array(
					'post'        => '100',
					'action'      => 'edit',
					'message'     => '10',
					'etss_notice' => '1',
				),
			),
			array(
				'test_condition_name' => '何も残っていない => そのまま',
				'last'                => array(),
				'post_type'           => 'etss_slide',
				'expected_query'      => array(
					'post'    => '100',
					'action'  => 'edit',
					'message' => '10',
				),
			),
			array(
				'test_condition_name' => 'スライド以外の投稿タイプ => 手を出さない',
				'last'                => array( 'errors' => array( 'url' => 'x' ) ),
				'post_type'           => 'page',
				'expected_query'      => array(
					'post'    => '100',
					'action'  => 'edit',
					'message' => '10',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			etss_test_reset_wp();
			$GLOBALS['etss_test_posts'][ self::SLIDE_ID ] = (object) array(
				'post_type'   => $case['post_type'],
				'post_status' => 'publish',
			);
			if ( $case['last'] ) {
				update_post_meta( self::SLIDE_ID, ETSS_Meta::LAST_ERRORS, $case['last'] );
			}

			$location = ETSS_Slide_Admin::filter_redirect_location( $base, self::SLIDE_ID );
			$query    = array();
			$parts    = explode( '?', $location, 2 );
			parse_str( isset( $parts[1] ) ? $parts[1] : '', $query );

			$this->assertEquals( $case['expected_query'], $query, $case['test_condition_name'] );
		}
	}

	/**
	 * 下書き保存で、形式が正しくない欄は保存済みの値のまま保ち、打った値と通知を残すこと。
	 *
	 * @return void
	 */
	public function test_save_draft_keeps_saved_value_of_invalid_fields() {
		$test_cases = array(
			array(
				'test_condition_name' => 'リンク先が example.com（形式が正しくない） => 保存済みのまま・打った値を残す',
				'fields'              => array( 'url' => 'example.com' ),
				'meta_key'            => ETSS_Meta::URL,
				'expected_meta'       => '/saved/',
				'unsaved_key'         => 'url',
				'expected_input'      => 'example.com',
				'input_key'           => 'url',
			),
			array(
				'test_condition_name' => '表示開始日時が日時の形式でない => 保存済みのまま',
				'fields'              => array( 'start' => '2026-13-45T99:99' ),
				'meta_key'            => ETSS_Meta::START,
				'expected_meta'       => self::SAVED_START,
				'unsaved_key'         => 'start',
				'expected_input'      => '2026-13-45T99:99',
				'input_key'           => 'start',
			),
			array(
				'test_condition_name' => '表示終了日時が表示開始日時より前 => 保存済みのまま',
				'fields'              => array(
					'start' => '2030-01-10T00:00',
					'end'   => '2030-01-09T00:00',
				),
				'meta_key'            => ETSS_Meta::END,
				'expected_meta'       => self::SAVED_END,
				'unsaved_key'         => 'end',
				'expected_input'      => '2030-01-09T00:00',
				'input_key'           => 'end',
			),
			array(
				'test_condition_name' => '存在しない画像 => 保存済みの画像のまま',
				'fields'              => array( 'images' => array( 'image' => '999' ) ),
				'meta_key'            => ETSS_Meta::IMAGES,
				'expected_meta'       => array( 'image' => self::IMAGE_ID ),
				'unsaved_key'         => 'image-image',
				'expected_input'      => array( 'image' => 999 ),
				'input_key'           => 'images',
			),
			array(
				'test_condition_name' => 'リンク先を空にした => 空にする（形式不正ではないので通知しない）',
				'fields'              => array( 'url' => '' ),
				'meta_key'            => ETSS_Meta::URL,
				'expected_meta'       => null,
				'unsaved_key'         => null,
				'expected_input'      => null,
				'input_key'           => 'url',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->reset_world();
			$post              = $this->active_slide();
			$post->post_status = 'draft';
			update_post_meta( self::SLIDE_ID, ETSS_Meta::END, self::SAVED_END );
			$this->post_from_form( $case['fields'] );

			ETSS_Slide_Admin::save( self::SLIDE_ID, $post );

			$stored = get_post_meta( self::SLIDE_ID, ETSS_Meta::LAST_ERRORS, true );
			if ( null === $case['expected_meta'] ) {
				$this->assertSame( array(), get_post_meta( self::SLIDE_ID, $case['meta_key'], false ), $case['test_condition_name'] . '（値）' );
				$this->assertSame( '', $stored, $case['test_condition_name'] . '（通知を残さない）' );
				continue;
			}

			$this->assertSame( $case['expected_meta'], get_post_meta( self::SLIDE_ID, $case['meta_key'], true ), $case['test_condition_name'] . '（値）' );
			$this->assertArrayHasKey( $case['unsaved_key'], $stored['unsaved'], $case['test_condition_name'] . '（通知）' );
			$this->assertSame( array(), $stored['errors'], $case['test_condition_name'] . '（エラー（赤）にしない）' );
			$this->assertSame( $case['expected_input'], $stored['input'][ $case['input_key'] ], $case['test_condition_name'] . '（打った値）' );
		}
	}

	/**
	 * 下書き保存で形式が正しくない欄があっても、ほかの正しい欄は保存すること。
	 *
	 * @return void
	 */
	public function test_save_draft_saves_valid_fields_next_to_invalid_ones() {
		$post              = $this->active_slide();
		$post->post_status = 'draft';
		$this->post_from_form(
			array(
				'url'   => 'example.com',
				'start' => '2030-01-10T00:00',
			)
		);

		ETSS_Slide_Admin::save( self::SLIDE_ID, $post );

		$this->assertSame( '/saved/', get_post_meta( self::SLIDE_ID, ETSS_Meta::URL, true ), '形式が正しくないリンク先は保存済みのまま' );
		$this->assertSame( gmmktime( 0, 0, 0, 1, 10, 2030 ), get_post_meta( self::SLIDE_ID, ETSS_Meta::START, true ), '正しい表示開始日時は保存する' );
	}

	/**
	 * 保存後の画面に、打った値を戻すこと（保存しなかった欄の入力）。
	 *
	 * @return void
	 */
	public function test_form_values() {
		$post = (object) array( 'ID' => self::SLIDE_ID );
		$this->active_slide();

		$values = ETSS_Slide_Admin::form_values(
			$post,
			array(
				'input' => array(
					'slider_id' => (string) self::SLIDER_ID,
					'images'    => array( 'image' => 999 ),
					'url'       => 'example.com',
					'start'     => '2030-01-10T00:00',
					'end'       => '',
				),
			),
			0
		);

		$this->assertSame( 'example.com', $values['url'], '打ったリンク先が欄に戻る' );
		$this->assertSame( '2030-01-10T00:00', $values['start'] );
		$this->assertSame( array( 'image' => 999 ), $values['images'] );

		$saved = ETSS_Slide_Admin::form_values( $post, array(), 0 );
		$this->assertSame( '/saved/', $saved['url'], '入力が残っていなければ、保存済みの値を出す' );
	}

	/**
	 * reject で保存済みのメタを戻すとき、値の途中のバックスラッシュが欠けないこと（update_post_meta は slash を外すため）。
	 *
	 * @return void
	 */
	public function test_restore_meta_keeps_backslash() {
		$post = $this->active_slide();
		update_post_meta( self::SLIDE_ID, ETSS_Meta::URL, wp_slash( '/path\\with\\backslash/' ) );
		$this->assertSame( '/path\\with\\backslash/', get_post_meta( self::SLIDE_ID, ETSS_Meta::URL, true ), '前提: 保存済みの値にバックスラッシュがある' );
		$this->post_from_form( array( 'url' => '' ) );

		ETSS_Slide_Admin::filter_insert_post_data( $this->insert_data(), array( 'ID' => self::SLIDE_ID ) );
		update_post_meta( self::SLIDE_ID, ETSS_Meta::URL, '/evil/' );
		ETSS_Slide_Admin::save( self::SLIDE_ID, $post );

		$this->assertSame( '/path\\with\\backslash/', get_post_meta( self::SLIDE_ID, ETSS_Meta::URL, true ), 'バックスラッシュを含む値が、そのまま戻る' );
	}

	/**
	 * 通常の保存でも、バックスラッシュを含むリンク先が欠けずに保存されること。
	 *
	 * @return void
	 */
	public function test_save_keeps_backslash_in_url() {
		$post = $this->active_slide();
		// 本物の $_POST は slash 済みなので、slash した形で渡す。
		$this->post_from_form( array( 'url' => wp_slash( '/a\\b/' ) ) );

		ETSS_Slide_Admin::save( self::SLIDE_ID, $post );

		$this->assertSame( '/a\\b/', get_post_meta( self::SLIDE_ID, ETSS_Meta::URL, true ) );
	}
}
