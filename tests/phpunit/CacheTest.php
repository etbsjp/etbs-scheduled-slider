<?php
/**
 * inc/class-etss-cache.php（ページキャッシュの TTL・目印・purge）のテスト。
 *
 * LiteSpeed Cache の本体は無いので、`do_action` スタブが記録した呼び出しで確かめる。
 * 実際のヘッダとログは、検証サイトの LSCWP のデバッグログで確かめる。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Cache のテスト。
 */
class CacheTest extends TestCase {

	/**
	 * スタブとリクエストごとの状態を空にする。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		etss_test_reset();
		etss_test_reset_wp();
		ETSS_Cache::reset();
	}

	/**
	 * 記録された do_action の呼び出しを返す。
	 *
	 * @return array 各要素は array( フック名, 引数... )。
	 */
	private function calls() {
		return $GLOBALS['etss_test_action_calls'];
	}

	/**
	 * スライドの投稿とメタを用意する。
	 *
	 * @param int    $id        スライドの投稿 ID。
	 * @param int    $slider_id 属するスライダーの投稿 ID。
	 * @param string $status    投稿ステータス。
	 * @return void
	 */
	private function add_slide( $id, $slider_id, $status = 'publish' ) {
		$GLOBALS['etss_test_posts'][ $id ]                        = (object) array(
			'post_type'   => 'etss_slide',
			'post_status' => $status,
		);
		$GLOBALS['etss_test_meta'][ $id ][ ETSS_Meta::SLIDER_ID ] = array( $slider_id );
	}

	/**
	 * 目印のテスト（etss と etss_<ID> の両方）。
	 *
	 * @return void
	 */
	public function test_tag() {
		ETSS_Cache::tag( 5 );

		$this->assertSame(
			array(
				array( 'litespeed_tag_add', 'etss' ),
				array( 'litespeed_tag_add', 'etss_5' ),
			),
			$this->calls(),
			'etss と etss_<ID> を付ける'
		);
	}

	/**
	 * 保存期間のテスト（短い方を残す・切替が無ければ呼ばない）。
	 *
	 * @return void
	 */
	public function test_set_ttl() {
		ETSS_Cache::set_ttl( null );
		$this->assertSame( array(), $this->calls(), '切替が無いとき（null）は何も呼ばない' );

		ETSS_Cache::set_ttl( 600 );
		ETSS_Cache::set_ttl( 3600 );
		ETSS_Cache::set_ttl( 600 );
		ETSS_Cache::set_ttl( 120 );

		$this->assertSame(
			array(
				array( 'litespeed_control_set_ttl', 600 ),
				array( 'litespeed_control_set_ttl', 120 ),
			),
			$this->calls(),
			'後から来た長い値で上書きしない。短い値だけ伝える'
		);
	}

	/**
	 * キャッシュさせない指定のテスト。
	 *
	 * @return void
	 */
	public function test_nocache() {
		ETSS_Cache::nocache( 'テスト' );

		$this->assertSame( array( array( 'litespeed_control_set_nocache', 'テスト' ) ), $this->calls(), 'nocache を伝える' );
	}

	/**
	 * スライダー単位の purge のテスト。
	 *
	 * @return void
	 */
	public function test_purge_slider() {
		ETSS_Cache::purge_slider( 0 );
		ETSS_Cache::purge_slider( -3 );
		$this->assertSame( array(), $this->calls(), 'ID が 0 以下なら何もしない' );

		ETSS_Cache::purge_slider( 7 );
		$this->assertSame( array( array( 'litespeed_purge', 'etss_7' ) ), $this->calls(), 'etss_<ID> を捨てる' );
	}

	/**
	 * スライドの保存のテスト（付け替え・自動保存・自動下書き）。
	 *
	 * @return void
	 */
	public function test_purge_after_save_slide() {
		// 公開で保存する。所属が 5 から 6 に付け替わったので、両方を捨てる。
		$this->add_slide( 100, 5 );
		ETSS_Cache::remember_slider_id( 100 );
		$GLOBALS['etss_test_meta'][100][ ETSS_Meta::SLIDER_ID ] = array( 6 );
		ETSS_Cache::purge_after_save_slide( 100, $GLOBALS['etss_test_posts'][100] );
		$this->assertSame(
			array(
				array( 'litespeed_purge', 'etss_5' ),
				array( 'litespeed_purge', 'etss_6' ),
			),
			$this->calls(),
			'付け替えたときは元と先の両方'
		);

		// 同じスライダーのままなら、1回だけ。
		etss_test_reset();
		$this->add_slide( 101, 5 );
		ETSS_Cache::remember_slider_id( 101 );
		ETSS_Cache::purge_after_save_slide( 101, $GLOBALS['etss_test_posts'][101] );
		$this->assertSame( array( array( 'litespeed_purge', 'etss_5' ) ), $this->calls(), '同じスライダーのままなら1回だけ' );

		// 自動下書きでは消さない。
		etss_test_reset();
		$this->add_slide( 102, 5, 'auto-draft' );
		ETSS_Cache::remember_slider_id( 102 );
		ETSS_Cache::purge_after_save_slide( 102, $GLOBALS['etss_test_posts'][102] );
		$this->assertSame( array(), $this->calls(), '自動下書きでは消さない' );

		// 自動保存でも消さない。
		etss_test_reset();
		$GLOBALS['etss_test_autosave'] = true;
		$this->add_slide( 103, 5 );
		ETSS_Cache::remember_slider_id( 103 );
		ETSS_Cache::purge_after_save_slide( 103, $GLOBALS['etss_test_posts'][103] );
		$this->assertSame( array(), $this->calls(), '自動保存では消さない' );
	}

	/**
	 * スライダーの保存のテスト。
	 *
	 * @return void
	 */
	public function test_purge_after_save_slider() {
		$GLOBALS['etss_test_posts'][5] = (object) array(
			'post_type'   => 'etss_slider',
			'post_status' => 'publish',
		);
		ETSS_Cache::purge_after_save_slider( 5, $GLOBALS['etss_test_posts'][5] );
		$this->assertSame( array( array( 'litespeed_purge', 'etss_5' ) ), $this->calls(), 'スライダーを保存したらそのスライダーの目印を捨てる' );

		etss_test_reset();
		$GLOBALS['etss_test_posts'][5]->post_status = 'auto-draft';
		ETSS_Cache::purge_after_save_slider( 5, $GLOBALS['etss_test_posts'][5] );
		$this->assertSame( array(), $this->calls(), '自動下書きでは消さない' );
	}

	/**
	 * ゴミ箱・復元・削除のテスト。
	 *
	 * @return void
	 */
	public function test_purge_after_change() {
		$this->add_slide( 100, 5, 'trash' );
		ETSS_Cache::purge_after_change( 100 );
		$this->assertSame( array( array( 'litespeed_purge', 'etss_5' ) ), $this->calls(), 'スライドは、属するスライダーの目印' );

		etss_test_reset();
		$GLOBALS['etss_test_posts'][5] = (object) array(
			'post_type'   => 'etss_slider',
			'post_status' => 'trash',
		);
		ETSS_Cache::purge_after_change( 5 );
		$this->assertSame( array( array( 'litespeed_purge', 'etss_5' ) ), $this->calls(), 'スライダー自身は自分の目印' );

		etss_test_reset();
		$GLOBALS['etss_test_posts'][9] = (object) array(
			'post_type'   => 'page',
			'post_status' => 'trash',
		);
		ETSS_Cache::purge_after_change( 9 );
		ETSS_Cache::purge_after_change( 12345 );
		$this->assertSame( array(), $this->calls(), '関係のない投稿・存在しない投稿では何もしない' );
	}

	/**
	 * 添付ファイルの削除のテスト（その画像を使うスライドの所属スライダーを捨てる）。
	 *
	 * @return void
	 */
	public function test_purge_after_delete_attachment() {
		$this->add_slide( 100, 5 );
		$this->add_slide( 101, 5 );
		$this->add_slide( 102, 6 );
		$GLOBALS['etss_test_get_posts'] = array( 100, 101, 102 );

		ETSS_Cache::purge_after_delete_attachment( 429 );

		$this->assertSame(
			array(
				array( 'litespeed_purge', 'etss_5' ),
				array( 'litespeed_purge', 'etss_6' ),
			),
			$this->calls(),
			'使っているスライドの所属スライダーを、重複なしで捨てる'
		);

		$values = array_column( $GLOBALS['etss_test_get_posts_args']['meta_query'], 'value' );
		$this->assertContains( ';i:429;', $values, '整数の形で探す' );
		$this->assertContains( ';s:3:"429";', $values, '文字列の形でも探す' );

		etss_test_reset();
		$GLOBALS['etss_test_get_posts'] = array();
		ETSS_Cache::purge_after_delete_attachment( 429 );
		ETSS_Cache::purge_after_delete_attachment( 0 );
		$this->assertSame( array(), $this->calls(), '使っているスライドが無い・ID が不正なら何もしない' );
	}

	/**
	 * スライドの所属スライダーの求め方のテスト。
	 *
	 * @return void
	 */
	public function test_slider_ids_of_slides() {
		$this->add_slide( 100, 5 );
		$this->add_slide( 101, 5 );
		$GLOBALS['etss_test_posts'][102] = (object) array(
			'post_type'   => 'etss_slide',
			'post_status' => 'publish',
		);

		$this->assertSame( array( 5 ), ETSS_Cache::slider_ids_of_slides( array( 100, 101, 102 ) ), '重複を除き、所属が無いスライドは含めない' );
		$this->assertSame( array(), ETSS_Cache::slider_ids_of_slides( array() ), '空なら空' );
	}

	/**
	 * 停止時の purge のテスト。
	 *
	 * @return void
	 */
	public function test_purge_all() {
		ETSS_Cache::purge_all();

		$this->assertSame( array( array( 'litespeed_purge', 'etss' ) ), $this->calls(), '停止時は etss を捨てる' );
	}
}
