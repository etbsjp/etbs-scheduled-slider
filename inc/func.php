<?php
/**
 * 読み込みと起動。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 時刻の判定・変換（WordPress に依存しない）。
require_once __DIR__ . '/class-etss-schedule.php';
// リンク先の検査（WordPress に依存しない）。
require_once __DIR__ . '/class-etss-link.php';
// ループに要る枚数と繰り返し（WordPress に依存しない）。
require_once __DIR__ . '/class-etss-loop.php';
// メタのキーと動きの設定の既定値。
require_once __DIR__ . '/class-etss-meta.php';
// 投稿タイプと権限。
require_once __DIR__ . '/class-etss-post-types.php';
// ひな形の登録と取り出し。
require_once __DIR__ . '/class-etss-templates.php';
// ページキャッシュとの連携。
require_once __DIR__ . '/class-etss-cache.php';
// 管理画面（プラグイン一覧の行）。
require_once __DIR__ . '/class-etss-admin.php';

/**
 * ひな形を登録する。`etss_register_templates` アクションの中で呼ぶ。
 *
 * @param string $id   ひな形の ID（英小文字・数字・ハイフン・アンダースコア）。
 * @param array  $args 定義。キーは docs/spec.md §3 を参照。
 * @return bool 登録できたら true。定義に誤りがあれば false（開発時は通知が出る）。
 */
function etss_register_template( $id, $args ) {
	return ETSS_Templates::register( $id, $args );
}

/**
 * 登録済みのひな形をすべて取り出す。
 *
 * @return array ひな形の ID => 定義。
 */
function etss_get_templates() {
	return ETSS_Templates::all();
}

/**
 * 登録済みのひな形を1つ取り出す。
 *
 * @param string $id ひな形の ID。
 * @return array|null 定義。無ければ null。
 */
function etss_get_template( $id ) {
	return ETSS_Templates::get( $id );
}

ETSS_Post_Types::init();
ETSS_Meta::init();
ETSS_Templates::init();
ETSS_Admin::init();

// 停止するときは、このプラグインが付けた目印のページキャッシュを捨てる。LiteSpeed Cache が無くても何も起きない。
register_deactivation_hook( ETSS_PLUGIN_FILE, array( 'ETSS_Admin', 'on_deactivate' ) );
