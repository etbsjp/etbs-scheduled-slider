<?php
/**
 * スライドが少ないときの扱い（docs/spec.md §5.4・D8）。
 *
 * Swiper のループは、一度に見せる枚数より十分に多いスライドが無いと崩れる。
 * そのため「ループに要る枚数」を求め、足りなければ並びを繰り返して補う。
 * WordPress に依存しない純粋な処理だけを置く。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ループに要る枚数と繰り返しをまとめたクラス。
 */
class ETSS_Loop {

	/**
	 * 一度に見せる枚数として受け付ける上限。これを超える値は打ち切る。
	 * 巨大な値（1e9 など）で、繰り返しの枚数が膨らんでメモリを使い果たすのを防ぐ。
	 */
	const MAX_PER_VIEW = 20.0;

	/**
	 * ループに要る枚数を求める。
	 *
	 * VK Blocks のスライダーの JS（vk-slider.min.js）が、枚数が足りないときにループを止める条件に合わせる。
	 * 画面幅（Mobile・Tablet・PC）ごとに次を求め、最大のものを返す。
	 * - 見せる枚数 ＝ ceil( slidesPerView )。中央寄せで偶数なら 1 足す
	 * - 一度に送る枚数 ＝ slides-per-view なら floor( slidesPerView )（1 以上）、one-by-one なら 1
	 * - 要る枚数 ＝ 見せる枚数 ＋ 先読み。先読みは、中央寄せなら max( 一度に送る枚数, ceil( 見せる枚数 ÷ 2 ) )、そうでなければ一度に送る枚数。
	 *   先読みは一度に送る枚数の倍数に切り上げる
	 *
	 * 例: 1.3 枚見せ・中央寄せ・one-by-one → 見せる 3、先読み 2 → 5。
	 * 見せる枚数は MAX_PER_VIEW（20）で打ち切り、0 以下・NaN・INF・非数値は 1 として扱う。
	 * effect が fade のときは、VK は枚数を指定しない（1 枚見せ・1 枚ずつ）ので 2。
	 *
	 * @param array $options 動きの設定（slidesPerViewMobile / Tablet / PC・centeredSlides・slidesPerGroup・effect）。
	 * @return int 要る枚数（1 以上）。
	 */
	public static function required_count( array $options ) {
		$centered   = ! empty( $options['centeredSlides'] );
		$per_group  = isset( $options['slidesPerGroup'] ) ? $options['slidesPerGroup'] : 'one-by-one';
		$required   = 0;
		$is_fade    = isset( $options['effect'] ) && 'fade' === $options['effect'];
		$candidates = $is_fade ? array( 1.0 ) : array();

		// 画面幅ごとの「見せる枚数」を集める。
		foreach ( array( 'slidesPerViewMobile', 'slidesPerViewTablet', 'slidesPerViewPC' ) as $key ) {
			if ( ! $is_fade ) {
				$candidates[] = self::normalize_per_view( isset( $options[ $key ] ) ? $options[ $key ] : 1 );
			}
		}

		foreach ( $candidates as $per_view ) {
			$visible = (int) ceil( $per_view );

			// 中央寄せで見せる枚数が偶数なら、左右を揃えるために 1 枚足す。
			if ( $centered && 0 === $visible % 2 ) {
				++$visible;
			}

			// 一度に送る枚数。
			$group = ( 'slides-per-view' === $per_group ) ? max( 1, (int) floor( $per_view ) ) : 1;

			// 先読みの枚数。一度に送る枚数の倍数に切り上げる。
			$ahead = $centered ? max( $group, (int) ceil( $visible / 2 ) ) : $group;
			if ( 0 !== $ahead % $group ) {
				$ahead += $group - ( $ahead % $group );
			}

			$required = max( $required, $visible + $ahead );
		}

		return $required;
	}

	/**
	 * 「一度に見せる枚数」の設定値を、計算に使える数にする。
	 *
	 * 数値でない・0 以下・NaN・INF は 1、MAX_PER_VIEW より大きい値は MAX_PER_VIEW にする。1 未満の正の値は 1 にする。
	 *
	 * @param mixed $value 設定値。
	 * @return float 1 以上 MAX_PER_VIEW 以下の数。
	 */
	private static function normalize_per_view( $value ) {
		if ( ! is_numeric( $value ) ) {
			return 1.0;
		}

		$number = (float) $value;
		if ( is_nan( $number ) || is_infinite( $number ) || $number <= 0 ) {
			return 1.0;
		}

		return max( 1.0, min( $number, self::MAX_PER_VIEW ) );
	}

	/**
	 * ループのために並びを繰り返すかを判定する。
	 *
	 * ループが有効で、枚数が 2 以上かつ要る枚数未満のときだけ繰り返す。
	 * 1 枚のときはループ自体を止める（{@see ETSS_Loop::adjust_options()}）ので繰り返さない。
	 *
	 * @param int   $count   スライドの枚数。
	 * @param array $options 動きの設定。
	 * @return bool 繰り返すなら true。
	 */
	public static function needs_repeat( $count, array $options ) {
		$count = (int) $count;

		return ! empty( $options['loop'] ) && $count >= 2 && $count < self::required_count( $options );
	}

	/**
	 * 並びを繰り返して、ループに要る枚数以上にする。
	 *
	 * 並び全体をそのまま繰り返す（順番を保つ）ので、要る枚数ちょうどにはならず、超えることがある。
	 *
	 * @param array $items   スライドの配列（添字は振り直す）。
	 * @param array $options 動きの設定。
	 * @return array 繰り返したあとの配列。繰り返す必要が無ければ、添字を振り直しただけの同じ並び。
	 */
	public static function repeat_for_loop( array $items, array $options ) {
		$items = array_values( $items );
		$count = count( $items );

		if ( ! self::needs_repeat( $count, $options ) ) {
			return $items;
		}

		// 並び全体を何回並べれば要る枚数に届くかを数えて、繰り返す。
		$copies = (int) ceil( self::required_count( $options ) / $count );

		return array_merge( ...array_fill( 0, $copies, $items ) );
	}

	/**
	 * スライドの枚数に合わせて動きの設定を直す。1 枚のときはループ・自動送り・ドットを止める。
	 *
	 * @param array $options 動きの設定。
	 * @param int   $count   スライドの枚数。
	 * @return array 直した動きの設定。
	 */
	public static function adjust_options( array $options, $count ) {
		if ( 1 === (int) $count ) {
			$options['loop']       = false;
			$options['autoPlay']   = false;
			$options['pagination'] = 'hide';
		}

		return $options;
	}
}
