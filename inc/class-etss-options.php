<?php
/**
 * スライダーの動きの設定（§2.1）の検査・整形と、選択肢の定義。
 *
 * WordPress に依存しない純粋な処理だけを置く。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 動きの設定の整形をまとめたクラス。
 */
class ETSS_Options {

	/**
	 * 選択式の項目の選択肢（値 => 表示名）。値は VK のスライダーのブロックの値と同じ。
	 *
	 * @return array 設定名 => （値 => 表示名）。
	 */
	public static function choices() {
		return array(
			'pagination'     => array(
				'bullets'     => 'ドット',
				'fraction'    => '番号（1 / 5）',
				'progressbar' => '進行バー',
				'hide'        => '表示しない',
			),
			'effect'         => array(
				'slide' => 'スライド',
				'fade'  => 'フェード',
			),
			'slidesPerGroup' => array(
				'one-by-one'      => '1枚ずつ',
				'slides-per-view' => '見えている枚数ずつ',
			),
			'width'          => array(
				''     => '標準',
				'wide' => '幅広',
				'full' => '全幅',
			),
		);
	}

	/**
	 * 数値項目の範囲（最小・最大・小数の有無）。
	 *
	 * @return array 設定名 => `min`・`max`・`step`。
	 */
	public static function number_ranges() {
		return array(
			'autoPlayDelay'       => array(
				'min'  => 500,
				'max'  => 60000,
				'step' => 100,
			),
			'speed'               => array(
				'min'  => 100,
				'max'  => 10000,
				'step' => 50,
			),
			'slidesPerViewMobile' => array(
				'min'  => 1,
				'max'  => 20,
				'step' => 0.1,
			),
			'slidesPerViewTablet' => array(
				'min'  => 1,
				'max'  => 20,
				'step' => 0.1,
			),
			'slidesPerViewPC'     => array(
				'min'  => 1,
				'max'  => 20,
				'step' => 0.1,
			),
		);
	}

	/**
	 * 送信された動きの設定を検査し、保存する形に整える。
	 *
	 * 真偽の項目は、送信されていなければ false。数値は範囲に収め（数でなければ既定値）、
	 * 選択式は選択肢にない値を既定値に戻す。知らないキーは捨てる。
	 *
	 * @param array $input    送信値（設定名 => 値）。
	 * @param array $defaults 既定値（ETSS_Meta::default_options() にひな形の既定を重ねたもの）。
	 * @return array 整えた動きの設定（既定値と同じキーをすべて持つ）。
	 */
	public static function sanitize( array $input, array $defaults ) {
		$result  = array();
		$ranges  = self::number_ranges();
		$choices = self::choices();

		foreach ( $defaults as $key => $default_value ) {
			$has_value = array_key_exists( $key, $input );
			$value     = $has_value ? $input[ $key ] : null;

			if ( is_bool( $default_value ) ) {
				// チェックボックスは、オンのときだけ送信される。
				$result[ $key ] = $has_value && in_array( $value, array( '1', 1, true, 'on' ), true );
			} elseif ( isset( $ranges[ $key ] ) ) {
				$result[ $key ] = self::clamp_number( $value, $default_value, $ranges[ $key ] );
			} elseif ( isset( $choices[ $key ] ) ) {
				$result[ $key ] = ( is_string( $value ) && array_key_exists( $value, $choices[ $key ] ) ) ? $value : $default_value;
			} else {
				$result[ $key ] = $default_value;
			}
		}

		return $result;
	}

	/**
	 * 数値を範囲に収める。数でなければ既定値。
	 *
	 * @param mixed $value   送信値。
	 * @param mixed $default_value 既定値。
	 * @param array $range   `min`・`max`・`step`。step が整数なら整数に丸める。
	 * @return int|float 整えた値。
	 */
	private static function clamp_number( $value, $default_value, array $range ) {
		if ( ! is_numeric( $value ) ) {
			return $default_value;
		}

		$number = (float) $value;
		if ( is_nan( $number ) || is_infinite( $number ) ) {
			return $default_value;
		}

		$number = max( (float) $range['min'], min( (float) $range['max'], $number ) );

		// 整数の項目は整数で、小数の項目は小数第1位で保存する。
		return is_int( $range['step'] ) ? (int) round( $number ) : round( $number, 1 );
	}

	/**
	 * 外枠に足す class を整える。class に使える文字だけのものを空白区切りで残す。
	 *
	 * @param mixed $value 送信値。
	 * @return string 整えた class。
	 */
	public static function sanitize_extra_class( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$classes = array();
		foreach ( preg_split( '/\s+/', trim( $value ) ) as $class ) {
			if ( '' !== $class && preg_match( '/^[A-Za-z0-9_-]+\z/', $class ) ) {
				$classes[] = $class;
			}
		}

		return implode( ' ', array_values( array_unique( $classes ) ) );
	}
}
