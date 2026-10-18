<?php
/**
 * 投稿タイプ `etss_slider`（スライダー）と `etss_slide`（スライド）の登録、および権限。
 *
 * 権限は D9。既定は `edit_pages`（編集者以上）で、`etss_capability` フィルターで変えられる。
 * 2つの投稿タイプのすべての権限を同じ1つの権限に結び付け、`map_meta_cap` でメタ権限（edit_post など）を
 * 投稿ごとの判定に落とす。投稿者（`edit_posts` だけを持つ）は一覧も編集画面も開けない。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 投稿タイプの登録をまとめたクラス。
 */
class ETSS_Post_Types {

	/**
	 * 投稿タイプ：スライダー。
	 */
	const SLIDER = 'etss_slider';

	/**
	 * 投稿タイプ：スライド。
	 */
	const SLIDE = 'etss_slide';

	/**
	 * 権限の既定値。
	 */
	const DEFAULT_CAPABILITY = 'edit_pages';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * 管理に必要な権限を返す。`etss_capability` フィルターで変えられる。
	 *
	 * フィルターは投稿タイプの登録（init の優先度 10）より前に付ける必要がある（mu-plugin か、プラグイン読み込み時）。
	 *
	 * 文字列でない・空の値が返されたときは、権限が空（誰でも通る）になる事故を避けて既定値に戻す。
	 *
	 * @return string 権限名。
	 */
	public static function get_capability() {
		$capability = apply_filters( 'etss_capability', self::DEFAULT_CAPABILITY );

		if ( ! is_string( $capability ) || '' === $capability ) {
			return self::DEFAULT_CAPABILITY;
		}

		return $capability;
	}

	/**
	 * 投稿タイプのすべての権限を、1つの権限に結び付けた配列を作る。
	 *
	 * @param string $capability 管理に必要な権限名。
	 * @return array register_post_type() の `capabilities` に渡す配列。
	 */
	public static function build_capabilities( $capability ) {
		$keys = array(
			'edit_posts',
			'edit_others_posts',
			'edit_private_posts',
			'edit_published_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_private_posts',
			'delete_published_posts',
			'create_posts',
		);

		return array_fill_keys( $keys, $capability );
	}

	/**
	 * 投稿タイプを登録する。
	 *
	 * @return void
	 */
	public static function register() {
		$capability = self::get_capability();

		// スライドを親のメニュー「スライダー」とし、その下にスライダーを置く。
		register_post_type(
			self::SLIDE,
			array_merge(
				self::common_args( 'etss_slide', 'etss_slides', $capability ),
				array(
					'labels'        => array(
						'name'                  => 'スライド',
						'singular_name'         => 'スライド',
						'menu_name'             => 'スライダー',
						'name_admin_bar'        => 'スライド',
						'all_items'             => 'スライド',
						'add_new'               => 'スライドを追加',
						'add_new_item'          => 'スライドを追加',
						'edit_item'             => 'スライドを編集',
						'new_item'              => '新しいスライド',
						'view_item'             => 'スライドを見る',
						'search_items'          => 'スライドを検索',
						'not_found'             => 'スライドはまだありません。先にスライダーを作成してから、「スライドを追加」で登録してください。',
						'not_found_in_trash'    => 'ゴミ箱にスライドはありません。',
						'attributes'            => 'スライドの属性',
						'items_list'            => 'スライド一覧',
						'items_list_navigation' => 'スライド一覧のナビゲーション',
						'filter_items_list'     => 'スライド一覧を絞り込む',
					),
					'supports'      => array( 'title', 'page-attributes' ),
					'menu_icon'     => 'dashicons-images-alt2',
					'menu_position' => 26,
				)
			)
		);

		register_post_type(
			self::SLIDER,
			array_merge(
				self::common_args( 'etss_slider', 'etss_sliders', $capability ),
				array(
					'labels'       => array(
						'name'                  => 'スライダー',
						'singular_name'         => 'スライダー',
						'menu_name'             => 'スライダー',
						'name_admin_bar'        => 'スライダー',
						'all_items'             => 'スライダー',
						'add_new'               => 'スライダーを追加',
						'add_new_item'          => 'スライダーを追加',
						'edit_item'             => 'スライダーを編集',
						'new_item'              => '新しいスライダー',
						'view_item'             => 'スライダーを見る',
						'search_items'          => 'スライダーを検索',
						'not_found'             => 'スライダーはまだありません。「スライダーを追加」から作成してください。',
						'not_found_in_trash'    => 'ゴミ箱にスライダーはありません。',
						'items_list'            => 'スライダー一覧',
						'items_list_navigation' => 'スライダー一覧のナビゲーション',
						'filter_items_list'     => 'スライダー一覧を絞り込む',
					),
					'supports'     => array( 'title' ),
					'show_in_menu' => 'edit.php?post_type=' . self::SLIDE,
				)
			)
		);
	}

	/**
	 * 2つの投稿タイプに共通の引数を作る。
	 *
	 * @param string $singular   権限名の単数形（capability_type）。
	 * @param string $plural     権限名の複数形（capability_type）。
	 * @param string $capability 管理に必要な権限名。
	 * @return array register_post_type() の引数の一部。
	 */
	public static function common_args( $singular, $plural, $capability ) {
		return array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'hierarchical'        => false,
			'capability_type'     => array( $singular, $plural ),
			'map_meta_cap'        => true,
			'capabilities'        => self::build_capabilities( $capability ),
		);
	}
}
