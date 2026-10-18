<?php
/**
 * ページキャッシュ（LiteSpeed Cache）との連携のうち、土台の分。
 *
 * 表示時の TTL・目印の付与と、保存時の purge は後の issue で作る。ここでは停止時の purge だけを持つ。
 * LiteSpeed Cache が無効でも `do_action` は何もしないだけなので、呼んで構わない。
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
	 * 目印が付いたページキャッシュをすべて捨てる。プラグインの停止時に呼ぶ。
	 *
	 * @return void
	 */
	public static function purge_all() {
		do_action( 'litespeed_purge', self::TAG );
	}
}
