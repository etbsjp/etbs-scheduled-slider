<?php
/**
 * Plugin Name: ETBS Scheduled Slider
 * Version: 1.0.1
 * Description: VK Blocks のスライダーを、管理画面から1枚ずつ差し替えられるようにします。スライドごとに表示開始・表示終了の日時を設定でき、時刻どおりに出し入れします。
 * Author: ETBS (DAI)
 * Author URI: https://etbs.jp
 * Plugin URI: https://etbs.jp/product-category/wordpress-tools/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: etbs-scheduled-slider
 * Requires at least: 6.3
 * Requires PHP: 7.4
 *
 * @package etbs-scheduled-slider
 */

// 直接アクセスされた場合は終了する。下の define も実行されるコードなので、それより前に置く。
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 本体ファイルの絶対パス。plugin_row_meta で自分の行を見分ける・停止時のフック登録・版数の読み取りで使う。
 */
define( 'ETSS_PLUGIN_FILE', __FILE__ );

// 実装は inc/ に置く。このファイルは読み込むだけ。
require_once __DIR__ . '/inc/func.php';

/*
 * プラグインのアップデートチェック。
 * GitHub の dist ブランチを見て、版数が上がっていれば管理画面に更新を出す。
 */
require __DIR__ . '/inc/plugin-update-checker/plugin-update-checker.php';
$etss_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/etbsjp/etbs-scheduled-slider/',
	__FILE__,
	'etbs-scheduled-slider'
);
$etss_update_checker->setBranch( 'dist' );
