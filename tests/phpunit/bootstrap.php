<?php
/**
 * PHPUnit のブートストラップ。
 *
 * このリポジトリには WordPress のテストスイート一式が無い（wp-env を使わない純 PHP のプラグイン）。
 * 代わりに、テスト対象が呼ぶ WordPress の関数だけを最小限のスタブとしてここで定義し、
 * 依存の薄いロジックだけをユニットテストする。実際の WordPress の中での動き
 * （管理画面・権限・キャッシュ）は検証サイトで手で確かめ、PR に記録する。
 *
 * 実行: vendor/bin/phpunit
 *
 * @package etbs-scheduled-slider
 */

// プラグインのファイルは `if ( ! defined( 'ABSPATH' ) ) { exit; }` で始まるため、通過させるダミー値を定義する。
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

/**
 * スタブの状態（フィルター・アクション・通知）を空にする。各テストの setUp() から呼ぶ。
 *
 * @return void
 */
function etss_test_reset() {
	$GLOBALS['etss_test_filters']        = array();
	$GLOBALS['etss_test_actions']        = array();
	$GLOBALS['etss_test_doing_it_wrong'] = array();
}

/**
 * apply_filters() スタブに、フィルターの戻り値を固定で設定する。
 *
 * @param string $hook  フィルター名。
 * @param mixed  $value 返す値。
 * @return void
 */
function etss_test_set_filter( $hook, $value ) {
	$GLOBALS['etss_test_filters'][ $hook ] = $value;
}

/**
 * apply_filters() の最小スタブ。etss_test_set_filter() で設定した値を返し、未設定なら渡された値をそのまま返す。
 *
 * @param string $hook  フィルター名。
 * @param mixed  $value 値。
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	return ( isset( $GLOBALS['etss_test_filters'] ) && array_key_exists( $hook, $GLOBALS['etss_test_filters'] ) )
		? $GLOBALS['etss_test_filters'][ $hook ]
		: $value;
}

/**
 * do_action() の最小スタブ。呼ばれたアクション名を記録する。
 *
 * @param string $hook アクション名。
 * @return void
 */
function do_action( $hook ) {
	$GLOBALS['etss_test_actions'][] = $hook;
}

/**
 * _doing_it_wrong() の最小スタブ。呼ばれた関数名とメッセージを記録する。
 *
 * @param string $function_name 関数名。
 * @param string $message       メッセージ。
 * @param string $version       版数。
 * @return void
 */
function _doing_it_wrong( $function_name, $message, $version ) { // phpcs:ignore
	unset( $version );
	$GLOBALS['etss_test_doing_it_wrong'][] = array( $function_name, $message );
}

/**
 * esc_html() のスタブ（WordPress と同じく、特殊文字を実体参照にする）。
 *
 * @param string $text 文字列。
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * 投稿・メタの最小スタブの状態を空にする。保存フックのテストの setUp() から呼ぶ。
 *
 * @return void
 */
function etss_test_reset_wp() {
	$GLOBALS['etss_test_meta']     = array();
	$GLOBALS['etss_test_posts']    = array();
	$GLOBALS['etss_test_attach']   = array();
	$GLOBALS['etss_test_can']      = true;
	$GLOBALS['etss_test_autosave'] = false;
	$_POST                         = array();
	$_GET                          = array();
}

/**
 * get_post_meta() の最小スタブ。
 *
 * @param int    $post_id 投稿 ID。
 * @param string $key     キー。
 * @param bool   $single  1件だけ返すか。
 * @return mixed
 */
function get_post_meta( $post_id, $key, $single = false ) {
	$values = isset( $GLOBALS['etss_test_meta'][ $post_id ][ $key ] ) ? $GLOBALS['etss_test_meta'][ $post_id ][ $key ] : array();
	if ( $single ) {
		return $values ? $values[0] : '';
	}
	return $values;
}

/**
 * update_post_meta() の最小スタブ。値の slash を外して保存する。
 *
 * @param int    $post_id 投稿 ID。
 * @param string $key     キー。
 * @param mixed  $value   値。
 * @return bool
 */
function update_post_meta( $post_id, $key, $value ) {
	// 本物の update_post_meta() と同じく、値の slash を外して保存する（呼び出し側が wp_slash() を通し忘れると、値が欠ける）。
	$GLOBALS['etss_test_meta'][ $post_id ][ $key ] = array( wp_unslash( $value ) );
	return true;
}

/**
 * delete_post_meta() の最小スタブ。
 *
 * @param int    $post_id 投稿 ID。
 * @param string $key     キー。
 * @return bool
 */
function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['etss_test_meta'][ $post_id ][ $key ] );
	return true;
}

/**
 * get_post() の最小スタブ（etss_test_posts に入れたオブジェクトを返す）。
 *
 * @param int $post_id 投稿 ID。
 * @return object|null
 */
function get_post( $post_id ) {
	return isset( $GLOBALS['etss_test_posts'][ $post_id ] ) ? $GLOBALS['etss_test_posts'][ $post_id ] : null;
}

/**
 * get_post_status() のスタブ。
 *
 * @param int $post_id 投稿 ID。
 * @return string|false
 */
function get_post_status( $post_id ) {
	$post = get_post( $post_id );
	return $post ? $post->post_status : false;
}

/**
 * get_post_type() のスタブ。
 *
 * @param int $post_id 投稿 ID。
 * @return string|false
 */
function get_post_type( $post_id ) {
	$post = get_post( $post_id );
	return $post ? $post->post_type : false;
}

/**
 * wp_timezone() のスタブ（UTC）。
 *
 * @return DateTimeZone
 */
function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}

/**
 * wp_verify_nonce() のスタブ。値が `good` のときだけ通す。
 *
 * @param string $nonce  nonce。
 * @return bool
 */
function wp_verify_nonce( $nonce ) {
	return 'good' === $nonce;
}

/**
 * sanitize_text_field() のスタブ。
 *
 * @param string $text 文字列。
 * @return string
 */
function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

/**
 * wp_unslash() のスタブ（文字列と配列の slash を外す）。
 *
 * @param mixed $value 値。
 * @return mixed
 */
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * wp_slash() のスタブ（文字列と配列に addslashes() する）。
 *
 * @param mixed $value 値。
 * @return mixed
 */
function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}

	return is_string( $value ) ? addslashes( $value ) : $value;
}

/**
 * wp_is_post_autosave() のスタブ。
 *
 * @return bool
 */
function wp_is_post_autosave() {
	return ! empty( $GLOBALS['etss_test_autosave'] );
}

/**
 * wp_is_post_revision() のスタブ。
 *
 * @return bool
 */
function wp_is_post_revision() {
	return false;
}

/**
 * add_query_arg() のスタブ（`add_query_arg( キー, 値, URL )` の形だけ）。
 *
 * @param string $key   引数名。
 * @param string $value 値。
 * @param string $url   URL。
 * @return string
 */
function add_query_arg( $key, $value, $url ) {
	$parts = explode( '?', $url, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
	}
	$query[ $key ] = $value;

	return $parts[0] . '?' . http_build_query( $query );
}

/**
 * remove_query_arg() のスタブ（`remove_query_arg( キー, URL )` の形だけ）。
 *
 * @param string $key 引数名。
 * @param string $url URL。
 * @return string
 */
function remove_query_arg( $key, $url ) {
	$parts = explode( '?', $url, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
	}
	unset( $query[ $key ] );

	return $parts[0] . ( $query ? '?' . http_build_query( $query ) : '' );
}

/**
 * current_user_can() のスタブ。
 *
 * @return bool
 */
function current_user_can() {
	return $GLOBALS['etss_test_can'];
}

/**
 * wp_attachment_is_image() のスタブ（etss_test_attach に入れた ID だけ画像）。
 *
 * @param int $id 添付ファイルの ID。
 * @return bool
 */
function wp_attachment_is_image( $id ) {
	return isset( $GLOBALS['etss_test_attach'][ $id ] );
}

/**
 * wp_get_attachment_metadata() のスタブ。
 *
 * @param int $id 添付ファイルの ID。
 * @return array|false
 */
function wp_get_attachment_metadata( $id ) {
	return isset( $GLOBALS['etss_test_attach'][ $id ] ) ? $GLOBALS['etss_test_attach'][ $id ] : false;
}

/**
 * current_time() のスタブ。
 *
 * @param string $type 種類。
 * @param bool   $gmt  GMT か。
 * @return string
 */
function current_time( $type, $gmt = false ) {
	unset( $type );
	return gmdate( 'Y-m-d H:i:s', 1000000 + ( $gmt ? 0 : 0 ) );
}

require_once dirname( __DIR__, 2 ) . '/inc/class-etss-schedule.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-link.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-loop.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-meta.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-post-types.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-templates.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-cache.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-validator.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-display.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-list.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-options.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-slider-admin.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-slide-admin.php';
