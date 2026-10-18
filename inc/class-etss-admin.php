<?php
/**
 * 管理画面のうち、プラグイン一覧の行の処理。
 *
 * メニュー「スライダー」と2つの一覧は、投稿タイプの登録（ETSS_Post_Types）で WordPress が作る。
 * 支援・依頼のリンクは、受託先のサイトの例外として、プラグイン一覧の行だけに置く。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面の処理をまとめたクラス。
 */
class ETSS_Admin {

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 4 );
	}

	/**
	 * プラグイン一覧の自分の行に、支援・依頼のリンクを足す。
	 *
	 * @param string[] $links       行のリンク。
	 * @param string   $file        その行のプラグイン（plugin_basename 形式）。
	 * @param array    $plugin_data プラグインのヘッダ情報。
	 * @param string   $status      プラグインの状態。
	 * @return string[] リンク。
	 */
	public static function plugin_row_meta( $links, $file, $plugin_data = array(), $status = '' ) {
		unset( $plugin_data, $status );

		// 自分の行だけに足す。
		if ( plugin_basename( ETSS_PLUGIN_FILE ) !== $file ) {
			return $links;
		}

		$utm     = '?utm_source=etbs-scheduled-slider&utm_medium=plugin';
		$links[] = '<a href="' . esc_url( 'https://etbs.jp/product/donate/' . $utm ) . '" target="_blank" rel="noopener noreferrer">開発を支援</a>';
		$links[] = '<a href="' . esc_url( 'https://etbs.jp/product-category/wordpress-tools/' . $utm ) . '" target="_blank" rel="noopener noreferrer">開発のご依頼</a>';

		return $links;
	}

	/**
	 * 停止したときの処理。目印の付いたページキャッシュを捨てる。
	 *
	 * @return void
	 */
	public static function on_deactivate() {
		ETSS_Cache::purge_all();
	}
}
