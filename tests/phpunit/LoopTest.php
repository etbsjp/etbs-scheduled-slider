<?php
/**
 * inc/class-etss-loop.php（ループに要る枚数と繰り返し）のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Loop のテスト。
 */
class LoopTest extends TestCase {

	/**
	 * ETSS_Loop::required_count() のテスト。
	 *
	 * @return void
	 */
	public function test_required_count() {
		$test_cases = array(
			array(
				'test_condition_name' => '既定（1枚見せ・one-by-one） => 1 + 1 = 2',
				'input'               => ETSS_Meta::default_options(),
				'expected'            => 2,
			),
			array(
				'test_condition_name' => '1.3枚見せ・中央寄せ・one-by-one => 2 + 1 + 1 = 4',
				'input'               => array(
					'slidesPerViewMobile' => 1.3,
					'slidesPerViewTablet' => 1.3,
					'slidesPerViewPC'     => 1.3,
					'centeredSlides'      => true,
					'slidesPerGroup'      => 'one-by-one',
				),
				'expected'            => 4,
			),
			array(
				'test_condition_name' => '1.3枚見せ・中央寄せでない => 2 + 0 + 1 = 3',
				'input'               => array(
					'slidesPerViewPC' => 1.3,
					'centeredSlides'  => false,
					'slidesPerGroup'  => 'one-by-one',
				),
				'expected'            => 3,
			),
			array(
				'test_condition_name' => '中央寄せで見せる枚数が奇数（3） => 3 + 0 + 1 = 4',
				'input'               => array(
					'slidesPerViewPC' => 3,
					'centeredSlides'  => true,
					'slidesPerGroup'  => 'one-by-one',
				),
				'expected'            => 4,
			),
			array(
				'test_condition_name' => '3つの画面幅のうち最大を使う（1 / 2 / 3） => 3 + 1 = 4',
				'input'               => array(
					'slidesPerViewMobile' => 1,
					'slidesPerViewTablet' => 2,
					'slidesPerViewPC'     => 3,
					'slidesPerGroup'      => 'one-by-one',
				),
				'expected'            => 4,
			),
			array(
				'test_condition_name' => 'slidesPerGroup が slides-per-view => 3 + 3 = 6',
				'input'               => array(
					'slidesPerViewPC' => 3,
					'slidesPerGroup'  => 'slides-per-view',
				),
				'expected'            => 6,
			),
			array(
				'test_condition_name' => '数値でない値は無視（1枚見せ扱い） => 2',
				'input'               => array(
					'slidesPerViewPC' => 'abc',
					'slidesPerGroup'  => 'one-by-one',
				),
				'expected'            => 2,
			),
			array(
				'test_condition_name' => '0 以下は 1 枚見せ扱い => 2',
				'input'               => array(
					'slidesPerViewPC' => 0,
					'slidesPerGroup'  => 'one-by-one',
				),
				'expected'            => 2,
			),
			array(
				'test_condition_name' => '設定が空 => 2',
				'input'               => array(),
				'expected'            => 2,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Loop::required_count( $case['input'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Loop::repeat_for_loop() のテスト。
	 *
	 * @return void
	 */
	public function test_repeat_for_loop() {
		$centered = array(
			'loop'                => true,
			'slidesPerViewMobile' => 1.3,
			'slidesPerViewTablet' => 1.3,
			'slidesPerViewPC'     => 1.3,
			'centeredSlides'      => true,
			'slidesPerGroup'      => 'one-by-one',
		);
		$default  = array_merge( ETSS_Meta::default_options(), array( 'loop' => true ) );

		$test_cases = array(
			array(
				'test_condition_name' => '0枚 => 空のまま',
				'input'               => array( array(), $default ),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => '1枚 => 繰り返さない（ループ自体を止める）',
				'input'               => array( array( 'a' ), $centered ),
				'expected'            => array( 'a' ),
			),
			array(
				'test_condition_name' => '2枚・要る枚数2 => そのまま',
				'input'               => array( array( 'a', 'b' ), $default ),
				'expected'            => array( 'a', 'b' ),
			),
			array(
				'test_condition_name' => '2枚・要る枚数4 => 並び全体を2回 = 4枚',
				'input'               => array( array( 'a', 'b' ), $centered ),
				'expected'            => array( 'a', 'b', 'a', 'b' ),
			),
			array(
				'test_condition_name' => '3枚・要る枚数4 => 並び全体を2回 = 6枚（超えてよい）',
				'input'               => array( array( 'a', 'b', 'c' ), $centered ),
				'expected'            => array( 'a', 'b', 'c', 'a', 'b', 'c' ),
			),
			array(
				'test_condition_name' => '4枚・要る枚数4（ちょうど） => そのまま',
				'input'               => array( array( 'a', 'b', 'c', 'd' ), $centered ),
				'expected'            => array( 'a', 'b', 'c', 'd' ),
			),
			array(
				'test_condition_name' => '2枚・ループ無効 => そのまま',
				'input'               => array( array( 'a', 'b' ), array_merge( $centered, array( 'loop' => false ) ) ),
				'expected'            => array( 'a', 'b' ),
			),
			array(
				'test_condition_name' => '添付の添字が飛んでいても振り直す',
				'input'               => array( array_slice( array( 'x', 'y', 'a', 'z', 'b' ), 2, 3, true ), $default ),
				'expected'            => array( 'a', 'z', 'b' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Loop::repeat_for_loop( $case['input'][0], $case['input'][1] ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Loop::adjust_options() のテスト。
	 *
	 * @return void
	 */
	public function test_adjust_options() {
		$options = ETSS_Meta::default_options();

		$one = ETSS_Loop::adjust_options( $options, 1 );
		$this->assertFalse( $one['loop'], '1枚 => loop を止める' );
		$this->assertFalse( $one['autoPlay'], '1枚 => 自動送りを止める' );
		$this->assertSame( 'hide', $one['pagination'], '1枚 => ドットを隠す' );
		$this->assertSame( 300, $one['speed'], '1枚 => 他の設定は変えない' );

		foreach ( array( 0, 2, 5 ) as $count ) {
			$this->assertSame( $options, ETSS_Loop::adjust_options( $options, $count ), $count . '枚 => 変えない' );
		}
	}
}
