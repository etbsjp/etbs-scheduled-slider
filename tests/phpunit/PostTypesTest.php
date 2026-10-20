<?php
/**
 * inc/class-etss-post-types.php（投稿タイプの権限）と、メタ・キャッシュの定数のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Post_Types・ETSS_Meta・ETSS_Cache のテスト。
 */
class PostTypesTest extends TestCase {

	/**
	 * 各テストの前に、スタブを空にする。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		etss_test_reset();
	}

	/**
	 * ETSS_Post_Types::get_capability() のテスト。
	 *
	 * @return void
	 */
	public function test_get_capability() {
		$test_cases = array(
			array(
				'test_condition_name' => 'フィルター無し => edit_pages',
				'filter'              => null,
				'expected'            => 'edit_pages',
			),
			array(
				'test_condition_name' => 'フィルターで manage_options => manage_options',
				'filter'              => 'manage_options',
				'expected'            => 'manage_options',
			),
			array(
				'test_condition_name' => 'フィルターが空文字 => 既定に戻す（誰でも通る事故を避ける）',
				'filter'              => '',
				'expected'            => 'edit_pages',
			),
			array(
				'test_condition_name' => 'フィルターが false => 既定に戻す',
				'filter'              => false,
				'expected'            => 'edit_pages',
			),
			array(
				'test_condition_name' => 'フィルターが配列 => 既定に戻す',
				'filter'              => array( 'edit_pages' ),
				'expected'            => 'edit_pages',
			),
		);

		foreach ( $test_cases as $case ) {
			etss_test_reset();
			if ( null !== $case['filter'] ) {
				etss_test_set_filter( 'etss_capability', $case['filter'] );
			}
			$this->assertSame( $case['expected'], ETSS_Post_Types::get_capability(), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Post_Types::build_capabilities() のテスト。
	 *
	 * @return void
	 */
	public function test_build_capabilities() {
		$caps = ETSS_Post_Types::build_capabilities( 'edit_pages' );

		// edit_posts（投稿者が持つ権限）に結び付いたものが無いこと、すべてが同じ権限であること。
		$this->assertSame( array( 'edit_pages' ), array_values( array_unique( $caps ) ), 'すべて同じ権限に結び付く' );
		foreach ( array( 'edit_posts', 'edit_others_posts', 'publish_posts', 'delete_posts', 'read_private_posts', 'create_posts' ) as $key ) {
			$this->assertArrayHasKey( $key, $caps, $key . ' を上書きしている' );
		}
	}

	/**
	 * ETSS_Post_Types::common_args() のテスト。
	 *
	 * @return void
	 */
	public function test_common_args() {
		$args = ETSS_Post_Types::common_args( 'etss_slide', 'etss_slides', 'edit_pages' );

		$this->assertFalse( $args['public'], '公開しない' );
		$this->assertTrue( $args['show_ui'], '管理画面には出す' );
		$this->assertFalse( $args['show_in_rest'], 'REST に出さない（クラシックエディタ）' );
		$this->assertTrue( $args['map_meta_cap'], 'map_meta_cap が有効' );
		$this->assertSame( array( 'etss_slide', 'etss_slides' ), $args['capability_type'], 'capability_type は専用' );
		$this->assertSame( 'edit_pages', $args['capabilities']['edit_posts'], '権限が結び付いている' );
	}

	/**
	 * メタのキーの置き場（ETSS_Meta）と既定値のテスト。
	 *
	 * @return void
	 */
	public function test_meta_keys_and_defaults() {
		$this->assertSame( '_etss_last_errors', ETSS_Meta::LAST_ERRORS, 'uninstall.php が消すキーと同じ' );

		$keys = array(
			ETSS_Meta::TEMPLATE,
			ETSS_Meta::OPTIONS,
			ETSS_Meta::EXTRA_CLASS,
			ETSS_Meta::SLIDER_ID,
			ETSS_Meta::IMAGES,
			ETSS_Meta::URL,
			ETSS_Meta::START,
			ETSS_Meta::END,
			ETSS_Meta::LAST_ERRORS,
		);
		$this->assertCount( count( $keys ), array_unique( $keys ), 'キーに重複が無い' );
		foreach ( $keys as $key ) {
			$this->assertStringStartsWith( '_etss_', $key, $key . ' は _etss_ で始まる保護されたメタ' );
		}

		$defaults = ETSS_Meta::default_options();
		$this->assertCount( 14, $defaults, '動きの設定は14項目（spec §2.1）' );
		$this->assertSame( 'mobile-bottom', $defaults['navigationPosition'], 'navigationPosition の既定は VK と同じ' );
		$this->assertSame( 2500, $defaults['autoPlayDelay'], 'autoPlayDelay の既定' );
		$this->assertSame( 'bullets', $defaults['pagination'], 'pagination の既定' );
		$this->assertTrue( $defaults['loop'], 'loop の既定' );
	}

	/**
	 * ETSS_Cache::purge_all() のテスト。
	 *
	 * @return void
	 */
	public function test_purge_all() {
		ETSS_Cache::purge_all();

		$this->assertSame( array( 'litespeed_purge' ), $GLOBALS['etss_test_actions'], 'litespeed_purge アクションを呼ぶ' );
	}
}
