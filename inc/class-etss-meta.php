<?php
/**
 * メタのキーの置き場と、動きの設定の既定値。
 *
 * メタのキーは他の場所に文字列で直接書かず、ここの定数を使う。
 * uninstall.php だけは、プラグインを読み込まずに動くので例外としてキーを文字列で持つ。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * メタのキーと既定値をまとめたクラス。
 */
class ETSS_Meta {

	/**
	 * スライダー：ひな形の ID。
	 */
	const TEMPLATE = '_etss_template';

	/**
	 * スライダー：動きの設定（配列）。
	 */
	const OPTIONS = '_etss_options';

	/**
	 * スライダー：外枠に足す class。
	 */
	const EXTRA_CLASS = '_etss_extra_class';

	/**
	 * スライド：属するスライダーの ID。
	 */
	const SLIDER_ID = '_etss_slider_id';

	/**
	 * スライド：ひな形の画像枠 ID => 添付ファイル ID の配列。
	 */
	const IMAGES = '_etss_images';

	/**
	 * スライド：リンク先。
	 */
	const URL = '_etss_url';

	/**
	 * スライド：表示開始（UTC の Unix 秒）。
	 */
	const START = '_etss_start';

	/**
	 * スライド：表示終了（UTC の Unix 秒。0 は終了なし）。
	 */
	const END = '_etss_end';

	/**
	 * スライド：前回の保存で出たエラー（表示用の一時状態）。
	 */
	const LAST_ERRORS = '_etss_last_errors';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
	}

	/**
	 * メタを登録する。REST には出さない。
	 *
	 * `_` で始まる保護されたメタなので、`auth_callback` は付けない（REST・カスタムフィールド欄からは編集できないまま）。
	 *
	 * @return void
	 */
	public static function register_meta() {
		// 投稿タイプごとに、持つメタとその型を並べる。
		$definitions = array(
			ETSS_Post_Types::SLIDER => array(
				self::TEMPLATE    => 'string',
				self::OPTIONS     => 'array',
				self::EXTRA_CLASS => 'string',
			),
			ETSS_Post_Types::SLIDE  => array(
				self::SLIDER_ID   => 'integer',
				self::IMAGES      => 'array',
				self::URL         => 'string',
				self::START       => 'integer',
				self::END         => 'integer',
				self::LAST_ERRORS => 'array',
			),
		);

		foreach ( $definitions as $post_type => $keys ) {
			foreach ( $keys as $key => $type ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'         => $type,
						'single'       => true,
						'show_in_rest' => false,
					)
				);
			}
		}
	}

	/**
	 * 動きの設定の既定値（VK のスライダーのブロックに合わせる）。
	 *
	 * @return array 設定名 => 既定値。
	 */
	public static function default_options() {
		return array(
			'autoPlay'            => true,
			'autoPlayStop'        => true,
			'autoPlayDelay'       => 2500,
			'pagination'          => 'bullets',
			'loop'                => true,
			'effect'              => 'slide',
			'speed'               => 300,
			'slidesPerViewMobile' => 1,
			'slidesPerViewTablet' => 1,
			'slidesPerViewPC'     => 1,
			'slidesPerGroup'      => 'one-by-one',
			'centeredSlides'      => false,
			'width'               => '',
		);
	}
}
