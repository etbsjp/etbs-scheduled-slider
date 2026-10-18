<?php
/**
 * inc/class-etss-templates.php（ひな形の登録と検査）のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Templates のテスト。
 */
class TemplatesTest extends TestCase {

	/**
	 * 各テストの前に、スタブと登録簿を空にする。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		etss_test_reset();
		ETSS_Templates::reset();
	}

	/**
	 * 有効な最小の定義を返す。
	 *
	 * @param array $override 上書きするキー。
	 * @return array 定義。
	 */
	private function valid_args( array $override = array() ) {
		return array_merge(
			array(
				'label' => 'テスト',
				'slots' => array(
					'wide' => array(
						'label'    => '横長',
						'required' => true,
					),
				),
			),
			$override
		);
	}

	/**
	 * ETSS_Templates::normalize() のテスト（定義の検査）。
	 *
	 * @return void
	 */
	public function test_normalize() {
		$test_cases = array(
			array(
				'test_condition_name' => '最小の有効な定義 => エラー無し',
				'input'               => array( 'neogate-top', $this->valid_args() ),
				'expected_errors'     => 0,
			),
			array(
				'test_condition_name' => 'ID が大文字 => エラー',
				'input'               => array( 'Neogate', $this->valid_args() ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'ID が空 => エラー',
				'input'               => array( '', $this->valid_args() ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'ID が文字列でない => エラー',
				'input'               => array( 5, $this->valid_args() ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => '定義が配列でない => エラー',
				'input'               => array( 'a', 'x' ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'label が無い => エラー',
				'input'               => array( 'a', array( 'slots' => $this->valid_args()['slots'] ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'label が空白だけ => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'label' => '  ' ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'slots が無い => エラー',
				'input'               => array( 'a', array( 'label' => 'x' ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'slots が空 => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'slots' => array() ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => '枠の label が無い => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'slots' => array( 'wide' => array( 'required' => true ) ) ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => '枠 ID の形が不正 => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'slots' => array( 'Wide!' => array( 'label' => 'x' ) ) ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => '枠 ID の重複（並びの形） => エラー',
				'input'               => array(
					'a',
					$this->valid_args(
						array(
							'slots' => array(
								array(
									'id'    => 'wide',
									'label' => '横長',
								),
								array(
									'id'    => 'wide',
									'label' => '横長 2',
								),
							),
						)
					),
				),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => '並びの形で id が無い => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'slots' => array( array( 'label' => 'x' ) ) ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'キーと id の食い違い => エラー',
				'input'               => array(
					'a',
					$this->valid_args(
						array(
							'slots' => array(
								'wide' => array(
									'id'    => 'tall',
									'label' => 'x',
								),
							),
						)
					),
				),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'ratio が 0 以下 => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'slots' => array( 'wide' => array( 'label' => 'x', 'ratio' => 0 ) ) ) ) ), // phpcs:ignore
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'render_item が呼び出せない => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'render_item' => 'no_such_function_etss' ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'render_item が呼び出せる => エラー無し',
				'input'               => array( 'a', $this->valid_args( array( 'render_item' => 'strtoupper' ) ) ),
				'expected_errors'     => 0,
			),
			array(
				'test_condition_name' => 'import_item が呼び出せない => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'import_item' => 'no_such_function_etss' ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'default_options に未知のキー => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'default_options' => array( 'autoplay' => false ) ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'default_options が配列でない => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'default_options' => 'x' ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'item_class に使えない文字 => エラー',
				'input'               => array( 'a', $this->valid_args( array( 'item_class' => 'x" onclick="y' ) ) ),
				'expected_errors'     => 1,
			),
			array(
				'test_condition_name' => 'エラーが複数 => すべて返す',
				'input'               => array( 'A', array( 'label' => '', 'slots' => array() ) ), // phpcs:ignore
				'expected_errors'     => 3,
			),
		);

		foreach ( $test_cases as $case ) {
			$result = ETSS_Templates::normalize( $case['input'][0], $case['input'][1] );
			$this->assertCount( $case['expected_errors'], $result['errors'], $case['test_condition_name'] );
			$this->assertSame( 0 === $case['expected_errors'], null !== $result['template'], $case['test_condition_name'] . '（template の有無）' );
		}
	}

	/**
	 * ETSS_Templates::normalize() の整形結果のテスト。
	 *
	 * @return void
	 */
	public function test_normalize_fills_defaults() {
		$result = ETSS_Templates::normalize(
			'a',
			array(
				'label'      => ' 名前 ',
				'slots'      => array(
					'wide' => array(
						'label' => '横長',
					),
					'tall' => array(
						'label'       => '縦長',
						'description' => 'NEW なし',
						'required'    => true,
						'ratio'       => '1.5',
						'preview_dim' => true,
					),
				),
				'item_class' => ' vk_valign-center ',
			)
		);

		$template = $result['template'];
		$this->assertSame( '名前', $template['label'], 'label の前後の空白を落とす' );
		$this->assertSame( array( 'wide', 'tall' ), array_keys( $template['slots'] ), '枠の並びを保つ' );
		$this->assertFalse( $template['slots']['wide']['required'], 'required の既定は false' );
		$this->assertNull( $template['slots']['wide']['ratio'], 'ratio の既定は null' );
		$this->assertSame( '', $template['slots']['wide']['description'], 'description の既定は空' );
		$this->assertFalse( $template['slots']['wide']['preview_dim'], 'preview_dim の既定は false' );
		$this->assertSame( 1.5, $template['slots']['tall']['ratio'], 'ratio は数値にする' );
		$this->assertSame( 'tall', $template['slots']['tall']['id'], '枠に id を持たせる' );
		$this->assertSame( 'vk_valign-center', $template['item_class'], 'item_class の前後の空白を落とす' );
		$this->assertNull( $template['render_item'], 'render_item は省略できる' );
		$this->assertSame( array(), $template['default_options'], 'default_options の既定は空' );
	}

	/**
	 * ETSS_Templates::register() / get() / all() のテスト。
	 *
	 * @return void
	 */
	public function test_register_and_get() {
		// 本体のひな形 vk-image は、取り出すと必ず入っている。
		$vk_image = ETSS_Templates::get( 'vk-image' );
		$this->assertNotNull( $vk_image, 'vk-image が登録されている' );
		$this->assertSame( array( 'image' ), array_keys( $vk_image['slots'] ), 'vk-image の画像枠は1つ' );
		$this->assertTrue( $vk_image['slots']['image']['required'], 'vk-image の画像枠は必須' );
		$this->assertContains( 'etss_register_templates', $GLOBALS['etss_test_actions'], 'etss_register_templates アクションが呼ばれる' );

		// 有効な定義は登録できる。
		$this->assertTrue( ETSS_Templates::register( 'neogate-top', $this->valid_args() ), '有効な定義は登録できる' );
		$this->assertSame( 'テスト', ETSS_Templates::get( 'neogate-top' )['label'], '登録した定義を取り出せる' );
		$this->assertSame( array( 'vk-image', 'neogate-top' ), array_keys( ETSS_Templates::all() ), '登録順に取り出せる' );

		// 無いものは null。
		$this->assertNull( ETSS_Templates::get( 'no-such' ), '未登録 => null' );
		$this->assertNull( ETSS_Templates::get( array( 'vk-image' ) ), '文字列でない ID => null' );
		$this->assertCount( 0, $GLOBALS['etss_test_doing_it_wrong'], 'ここまで通知は出ない' );

		// 同じ ID の二重登録は、先のものを守って false。
		$this->assertFalse( ETSS_Templates::register( 'neogate-top', $this->valid_args( array( 'label' => '別' ) ) ), '二重登録 => false' );
		$this->assertSame( 'テスト', ETSS_Templates::get( 'neogate-top' )['label'], '先に登録したものが残る' );
		$this->assertCount( 1, $GLOBALS['etss_test_doing_it_wrong'], '二重登録は通知される' );

		// 誤った定義は登録されず、通知される。
		$this->assertFalse( ETSS_Templates::register( 'bad', array( 'label' => 'x' ) ), '誤った定義 => false' );
		$this->assertNull( ETSS_Templates::get( 'bad' ), '誤った定義は登録されない' );
		$this->assertCount( 2, $GLOBALS['etss_test_doing_it_wrong'], '誤った定義は通知される' );
	}

	/**
	 * ETSS_Templates::default_options() のテスト。
	 *
	 * @return void
	 */
	public function test_default_options() {
		ETSS_Templates::register(
			'with-options',
			$this->valid_args(
				array(
					'default_options' => array(
						'pagination' => 'hide',
						'speed'      => 800,
					),
				)
			)
		);

		$options = ETSS_Templates::default_options( 'with-options' );
		$this->assertSame( 'hide', $options['pagination'], 'ひな形の指定が本体の既定を上書きする' );
		$this->assertSame( 800, $options['speed'], 'ひな形の指定が本体の既定を上書きする（数値）' );
		$this->assertTrue( $options['loop'], '指定の無い項目は本体の既定' );

		$this->assertSame( ETSS_Meta::default_options(), ETSS_Templates::default_options( 'vk-image' ), 'vk-image は本体の既定のまま' );
		$this->assertSame( ETSS_Meta::default_options(), ETSS_Templates::default_options( 'no-such' ), '未登録 => 本体の既定' );
	}
}
