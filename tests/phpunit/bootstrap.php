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

require_once dirname( __DIR__, 2 ) . '/inc/class-etss-schedule.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-link.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-loop.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-meta.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-post-types.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-templates.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-cache.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-validator.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-display.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-options.php';
require_once dirname( __DIR__, 2 ) . '/inc/class-etss-slider-admin.php';
