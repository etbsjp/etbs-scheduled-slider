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
	 * 要る枚数 ＝ ceil( 最大の slidesPerView ) ＋（centeredSlides で、その値が偶数なら 1）＋ slidesPerGroup。
	 * slidesPerGroup は one-by-one なら 1、それ以外（slides-per-view）は一度に見せる枚数と同じ。
	 * 例: 1.3 枚見せ・中央寄せ・one-by-one → 2 ＋ 1 ＋ 1 ＝ 4。
	 *
	 * 見せる枚数は MAX_PER_VIEW（20）で打ち切り、0 以下・NaN・INF・非数値は 1 として扱う。
	 *
	 * @param array $options 動きの設定（slidesPerViewMobile / Tablet / PC・centeredSlides・slidesPerGroup）。
	 * @return int 要る枚数（1 以上）。
	 */
	public static function required_count( array $options ) {
		// 3 つの画面幅のうち、最大の「見せる枚数」を取る。
		$max_per_view = 1.0;
		foreach ( array( 'slidesPerViewMobile', 'slidesPerViewTablet', 'slidesPerViewPC' ) as $key ) {
			if ( isset( $options[ $key ] ) && is_numeric( $options[ $key ] ) ) {
				$value = (float) $options[ $key ];

				// NaN・無限大・0 以下は 1 枚見せに戻し、大きすぎる値は上限で打ち切る。
				if ( is_nan( $value ) || is_infinite( $value ) || $value <= 0 ) {
					$value = 1.0;
				}
				$max_per_view = max( $max_per_view, min( $value, self::MAX_PER_VIEW ) );
			}
		}

		$visible = (int) ceil( $max_per_view );

		// 中央寄せで見せる枚数が偶数なら、左右を揃えるために 1 枚足す。
		$center = ( ! empty( $options['centeredSlides'] ) && 0 === $visible % 2 ) ? 1 : 0;

		// 一度に送る枚数。
		$group = ( isset( $options['slidesPerGroup'] ) && 'one-by-one' !== $options['slidesPerGroup'] ) ? $visible : 1;

		return $visible + $center + $group;
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
