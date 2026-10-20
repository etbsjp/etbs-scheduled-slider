<?php
/**
 * inc/class-etss-options.php（動きの設定の整形）と、スライダーの置き場所の判定のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Options・ETSS_Slider_Admin::content_uses_slider() のテスト。
 */
class OptionsTest extends TestCase {

	/**
	 * 真偽の項目・既定値・知らないキーのテスト。
	 *
	 * @return void
	 */
	public function test_sanitize_checkboxes_and_defaults() {
		$defaults = ETSS_Meta::default_options();

		$result = ETSS_Options::sanitize( array(), $defaults );
		$this->assertFalse( $result['autoPlay'], '送信されなかったチェックボックスは false' );
		$this->assertFalse( $result['loop'] );
		$this->assertSame( 2500, $result['autoPlayDelay'], '数値は既定値' );
		$this->assertSame( 'bullets', $result['pagination'], '選択式は既定値' );
		$this->assertSame( array_keys( $defaults ), array_keys( $result ), '既定値と同じキーをすべて持つ' );

		$result = ETSS_Options::sanitize(
			array(
				'autoPlay' => '1',
				'loop'     => '1',
				'unknown'  => 'x',
			),
			$defaults
		);
		$this->assertTrue( $result['autoPlay'] );
		$this->assertTrue( $result['loop'] );
		$this->assertArrayNotHasKey( 'unknown', $result, '知らないキーは捨てる' );
	}

	/**
	 * 数値と選択式の整形のテスト。
	 *
	 * @return void
	 */
	public function test_sanitize_numbers_and_choices() {
		$defaults = ETSS_Meta::default_options();

		$test_cases = array(
			array(
				'test_condition_name' => '範囲内の整数 => そのまま',
				'input'               => array( 'autoPlayDelay' => '4000' ),
				'key'                 => 'autoPlayDelay',
				'expected'            => 4000,
			),
			array(
				'test_condition_name' => '下限未満 => 下限',
				'input'               => array( 'autoPlayDelay' => '10' ),
				'key'                 => 'autoPlayDelay',
				'expected'            => 500,
			),
			array(
				'test_condition_name' => '上限超過 => 上限',
				'input'               => array( 'speed' => '99999999' ),
				'key'                 => 'speed',
				'expected'            => 10000,
			),
			array(
				'test_condition_name' => '数でない => 既定値',
				'input'               => array( 'autoPlayDelay' => 'abc' ),
				'key'                 => 'autoPlayDelay',
				'expected'            => 2500,
			),
			array(
				'test_condition_name' => 'INF => 既定値',
				'input'               => array( 'speed' => '1e999' ),
				'key'                 => 'speed',
				'expected'            => 300,
			),
			array(
				'test_condition_name' => '整数の項目に小数 => 丸める',
				'input'               => array( 'speed' => '450.6' ),
				'key'                 => 'speed',
				'expected'            => 451,
			),
			array(
				'test_condition_name' => '見せる枚数の小数 => 小数第1位',
				'input'               => array( 'slidesPerViewPC' => '1.34' ),
				'key'                 => 'slidesPerViewPC',
				'expected'            => 1.3,
			),
			array(
				'test_condition_name' => '見せる枚数が1未満 => 1',
				'input'               => array( 'slidesPerViewMobile' => '0.5' ),
				'key'                 => 'slidesPerViewMobile',
				'expected'            => 1.0,
			),
			array(
				'test_condition_name' => '見せる枚数が上限超過 => 20',
				'input'               => array( 'slidesPerViewTablet' => '500' ),
				'key'                 => 'slidesPerViewTablet',
				'expected'            => 20.0,
			),
			array(
				'test_condition_name' => '矢印の位置が hide => そのまま',
				'input'               => array( 'navigationPosition' => 'hide' ),
				'key'                 => 'navigationPosition',
				'expected'            => 'hide',
			),
			array(
				'test_condition_name' => '矢印の位置が center => そのまま',
				'input'               => array( 'navigationPosition' => 'center' ),
				'key'                 => 'navigationPosition',
				'expected'            => 'center',
			),
			array(
				'test_condition_name' => '矢印の位置が always-bottom => そのまま',
				'input'               => array( 'navigationPosition' => 'always-bottom' ),
				'key'                 => 'navigationPosition',
				'expected'            => 'always-bottom',
			),
			array(
				'test_condition_name' => '矢印の位置が選択肢にない => 既定の mobile-bottom',
				'input'               => array( 'navigationPosition' => 'top' ),
				'key'                 => 'navigationPosition',
				'expected'            => 'mobile-bottom',
			),
			array(
				'test_condition_name' => '矢印の位置が送信されない => 既定の mobile-bottom',
				'input'               => array(),
				'key'                 => 'navigationPosition',
				'expected'            => 'mobile-bottom',
			),
			array(
				'test_condition_name' => '矢印の位置が文字列でない => 既定の mobile-bottom',
				'input'               => array( 'navigationPosition' => array( 'hide' ) ),
				'key'                 => 'navigationPosition',
				'expected'            => 'mobile-bottom',
			),
			array(
				'test_condition_name' => '選択肢にある値 => そのまま',
				'input'               => array( 'effect' => 'fade' ),
				'key'                 => 'effect',
				'expected'            => 'fade',
			),
			array(
				'test_condition_name' => '選択肢にない値 => 既定値',
				'input'               => array( 'pagination' => 'evil' ),
				'key'                 => 'pagination',
				'expected'            => 'bullets',
			),
			array(
				'test_condition_name' => '空文字が選択肢にある項目（外枠の幅） => 空文字',
				'input'               => array( 'width' => '' ),
				'key'                 => 'width',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '配列が来た選択式 => 既定値',
				'input'               => array( 'slidesPerGroup' => array( 'slides-per-view' ) ),
				'key'                 => 'slidesPerGroup',
				'expected'            => 'one-by-one',
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ETSS_Options::sanitize( $case['input'], $defaults );
			$this->assertSame( $case['expected'], $result[ $case['key'] ], $case['test_condition_name'] );
		}
	}

	/**
	 * 外枠に足す class の整形のテスト。
	 *
	 * @return void
	 */
	public function test_sanitize_extra_class() {
		$test_cases = array(
			array( 'top-main-slider', 'top-main-slider' ),
			array( '  a   b_c  ', 'a b_c' ),
			array( 'a a b', 'a b' ),
			array( 'ok "><script>', 'ok' ),
			array( '', '' ),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case[1], ETSS_Options::sanitize_extra_class( $case[0] ), $case[0] );
		}
		$this->assertSame( '', ETSS_Options::sanitize_extra_class( array( 'a' ) ), '文字列でなければ空' );
	}

	/**
	 * 本文のブロックから、スライダーを置いているかを判定するテスト。
	 *
	 * @return void
	 */
	public function test_content_uses_slider() {
		$test_cases = array(
			array(
				'test_condition_name' => '同じ ID を選んだブロック => true',
				'content'             => '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph --><!-- wp:etss/slider {"sliderId":12} /-->',
				'slider_id'           => 12,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '別のスライダーを選んだブロック => false',
				'content'             => '<!-- wp:etss/slider {"sliderId":13} /-->',
				'slider_id'           => 12,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '2つあるうちの2つ目が一致 => true',
				'content'             => '<!-- wp:etss/slider {"sliderId":1} /--><!-- wp:etss/slider {"sliderId":12} /-->',
				'slider_id'           => 12,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '選択前（属性なし）のブロック => false',
				'content'             => '<!-- wp:etss/slider /-->',
				'slider_id'           => 12,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '似た名前のブロック => false',
				'content'             => '<!-- wp:etss/slider-extra {"sliderId":12} /-->',
				'slider_id'           => 12,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ブロックなし（本文の文字だけ） => false',
				'content'             => '<p>etss/slider {"sliderId":12}</p>',
				'slider_id'           => 12,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '壊れた属性 => false',
				'content'             => '<!-- wp:etss/slider {"sliderId": /-->',
				'slider_id'           => 12,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Slider_Admin::content_uses_slider( $case['content'], $case['slider_id'] ), $case['test_condition_name'] );
		}
	}
}
