<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- @wordpress/scripts が作るファイル名（index.asset.php）に合わせる。
/**
 * 編集画面のスクリプト（index.js）が使う WordPress のスクリプトの一覧。
 *
 * ビルドをしない（JSX を使わず、そのまま読み込む）ので、@wordpress/scripts が作る形を手で書いている。
 * 版数はここに持たず、ヘッダの `Version:` を使う（ETSS_Admin::asset_version()）。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ),
);
