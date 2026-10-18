<?php
/**
 * ページキャッシュ（LiteSpeed Cache）との連携（docs/spec.md §6）。
 *
 * 表示時に、次の切替時刻までを保存期間（TTL）にして目印を付ける。スライドやスライダーを変えたときは、目印でキャッシュを捨てる。
 * LiteSpeed Cache が無効でも `do_action` は何もしないだけなので、表示は正しい（保存されないので毎回作り直す）。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ページキャッシュとの連携をまとめたクラス。
 */
class ETSS_Cache {

	/**
	 * このプラグインが付ける目印（全ページ共通）。
	 */
	const TAG = 'etss';

	/**
	 * このリクエストで、すでに伝えた保存期間（秒）のうち最も短いもの。
	 * LiteSpeed Cache は、保存期間を後から伝えた値で上書きするので、1ページに複数のスライダーがあっても短い方を残す。
	 *
	 * @var int|null
	 */
	private static $requested_ttl = null;

	/**
	 * 保存・ゴミ箱・復元・削除の前に控えた、スライドの所属スライダー（スライドの投稿 ID => スライダーの投稿 ID）。
	 *
	 * @var array
	 */
	private static $previous_slider_ids = array();

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// 保存。所属スライダーの付け替えに備えて、保存の前の値を先に控える（優先度 1。ETSS_Slide_Admin::save は 10）。
		add_action( 'save_post_' . ETSS_Post_Types::SLIDE, array( __CLASS__, 'remember_slider_id' ), 1 );
		add_action( 'save_post_' . ETSS_Post_Types::SLIDE, array( __CLASS__, 'purge_after_save_slide' ), 20, 2 );
		add_action( 'save_post_' . ETSS_Post_Types::SLIDER, array( __CLASS__, 'purge_after_save_slider' ), 20, 2 );

		// ゴミ箱・復元・削除は、スライドもスライダーも同じ処理にする。
		add_action( 'trashed_post', array( __CLASS__, 'purge_after_change' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'purge_after_change' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'purge_after_change' ) );
	}

	/**
	 * 目印が付いたページキャッシュをすべて捨てる。プラグインの停止時に呼ぶ。
	 *
	 * @return void
	 */
	public static function purge_all() {
		do_action( 'litespeed_purge', self::TAG );
	}

	/**
	 * スライダー1つの目印（`etss_<ID>`）。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @return string 目印。
	 */
	public static function slider_tag( $slider_id ) {
		return self::TAG . '_' . (int) $slider_id;
	}

	/**
	 * スライダーを出すページに、目印（`etss` と `etss_<ID>`）を付ける。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @return void
	 */
	public static function tag( $slider_id ) {
		do_action( 'litespeed_tag_add', self::TAG );
		do_action( 'litespeed_tag_add', self::slider_tag( $slider_id ) );
	}

	/**
	 * ページキャッシュの保存期間を、次の切替時刻までにする。
	 *
	 * 1ページに複数のスライダーがあるときは、最も短い保存期間を残す。
	 *
	 * @param int|null $ttl 保存期間（秒。ETSS_Schedule::cache_ttl() の結果）。切替が無ければ null で、何もしない。
	 * @return void
	 */
	public static function set_ttl( $ttl ) {
		if ( null === $ttl || ( null !== self::$requested_ttl && (int) $ttl >= self::$requested_ttl ) ) {
			return;
		}

		self::$requested_ttl = (int) $ttl;
		do_action( 'litespeed_control_set_ttl', (int) $ttl );
	}

	/**
	 * このページをキャッシュさせない。壊れた状態（VK Blocks が無効のとき）を保存させないために使う。
	 *
	 * @param string $reason 理由（LiteSpeed Cache のログに残る）。
	 * @return void
	 */
	public static function nocache( $reason ) {
		do_action( 'litespeed_control_set_nocache', $reason );
	}

	/**
	 * スライダー1つの目印のページキャッシュを捨てる。
	 *
	 * @param int $slider_id スライダーの投稿 ID。0 以下は何もしない。
	 * @return void
	 */
	public static function purge_slider( $slider_id ) {
		if ( (int) $slider_id > 0 ) {
			do_action( 'litespeed_purge', self::slider_tag( $slider_id ) );
		}
	}

	/**
	 * リクエストごとの状態を空にする。テスト用。
	 *
	 * @return void
	 */
	public static function reset() {
		self::$requested_ttl       = null;
		self::$previous_slider_ids = array();
	}

	/**
	 * 保存の前の、スライドの所属スライダーを控える。
	 *
	 * @param int $post_id スライドの投稿 ID。
	 * @return void
	 */
	public static function remember_slider_id( $post_id ) {
		self::$previous_slider_ids[ (int) $post_id ] = (int) get_post_meta( $post_id, ETSS_Meta::SLIDER_ID, true );
	}

	/**
	 * スライドを保存したとき、所属するスライダー（付け替えたときは元のスライダーも）のキャッシュを捨てる。
	 *
	 * 自動保存・リビジョン・自動下書きでは何もしない（編集中の自動保存のたびに、公開中のページのキャッシュを捨てないため）。
	 *
	 * @param int     $post_id スライドの投稿 ID。
	 * @param WP_Post $post    投稿。
	 * @return void
	 */
	public static function purge_after_save_slide( $post_id, $post ) {
		$previous = isset( self::$previous_slider_ids[ (int) $post_id ] ) ? self::$previous_slider_ids[ (int) $post_id ] : 0;
		unset( self::$previous_slider_ids[ (int) $post_id ] );

		if ( self::is_transient_save( $post_id, $post ) ) {
			return;
		}

		$current = (int) get_post_meta( $post_id, ETSS_Meta::SLIDER_ID, true );
		if ( $previous !== $current ) {
			self::purge_slider( $previous );
		}
		self::purge_slider( $current );
	}

	/**
	 * スライダーを保存したとき、そのスライダーのキャッシュを捨てる。動きの設定やひな形が変わるため。
	 *
	 * @param int     $post_id スライダーの投稿 ID。
	 * @param WP_Post $post    投稿。
	 * @return void
	 */
	public static function purge_after_save_slider( $post_id, $post ) {
		if ( self::is_transient_save( $post_id, $post ) ) {
			return;
		}

		self::purge_slider( $post_id );
	}

	/**
	 * ゴミ箱へ移動・復元・完全削除のとき、そのスライドが属するスライダー（スライダー自身のときはそのスライダー）のキャッシュを捨てる。
	 *
	 * `deleted_post_<投稿タイプ>` は WordPress 6.6 からで、しかも発火の時点でメタが消えている。
	 * そのため、メタが残っている `before_delete_post` で拾う。
	 *
	 * @param int $post_id 投稿 ID。
	 * @return void
	 */
	public static function purge_after_change( $post_id ) {
		$post_type = get_post_type( $post_id );

		if ( ETSS_Post_Types::SLIDE === $post_type ) {
			self::purge_slider( (int) get_post_meta( $post_id, ETSS_Meta::SLIDER_ID, true ) );
		} elseif ( ETSS_Post_Types::SLIDER === $post_type ) {
			self::purge_slider( $post_id );
		}
	}

	/**
	 * 自動保存・リビジョン・自動下書きの保存かを判定する。これらでは捨てない。
	 *
	 * @param int     $post_id 投稿 ID。
	 * @param WP_Post $post    投稿。
	 * @return bool 該当すれば true。
	 */
	private static function is_transient_save( $post_id, $post ) {
		return wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ( is_object( $post ) && 'auto-draft' === $post->post_status );
	}
}
