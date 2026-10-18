<?php
/**
 * リンク先の検査。
 *
 * 通すのは、`/` で始まるサイト内のパスと、`http://`・`https://` で始まる URL だけ。
 * `//` で始まるもの（プロトコル相対 URL）やその他の書き方は弾く。
 * WordPress に依存しない純粋な処理だけを置く。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * リンク先の検査をまとめたクラス。
 */
class ETSS_Link {

	/**
	 * リンク先として使える文字列かを判定する。
	 *
	 * @param mixed $url リンク先。前後の空白は呼び出し側で落としておく。
	 * @return bool 使えるなら true。
	 */
	public static function is_valid( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		// 空白・制御文字を含むものは弾く（ブラウザが除去して別の URL として解釈するのを防ぐ）。
		if ( preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return false;
		}

		// サイト内のパス。`//example.com` と、ブラウザが `//` と同じに扱う `/\example.com` は弾く。
		if ( '/' === $url[0] ) {
			return ! isset( $url[1] ) || ( '/' !== $url[1] && '\\' !== $url[1] );
		}

		// 絶対 URL。スキームは http・https のみ（大文字小文字は区別しない）で、ホストが必要。
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		// バックスラッシュはブラウザが `/` として解釈するので弾く。
		if ( false !== strpos( $url, '\\' ) ) {
			return false;
		}

		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress に依存させないため。
		return is_array( $parts ) && isset( $parts['host'] ) && '' !== $parts['host'];
	}
}
