<?php
/**
 * inc/class-etss-render.php（スライダーの HTML の組み立てと、出すスライドの判定）と、
 * ひな形 vk-image の render_item、1枚目の lazy 抑止のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Render・ETSS_Templates::render_vk_image()・ETSS_Block::keep_no_lazy() のテスト。
 */
class RenderTest extends TestCase {

	const NOW = 1700000000;

	/**
	 * スタブの状態を空にし、画像 10・11 を用意する。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		etss_test_reset();
		etss_test_reset_wp();
		$GLOBALS['etss_test_attach'][10] = array(
			'width'  => 800,
			'height' => 400,
		);
		$GLOBALS['etss_test_attach'][11] = array(
			'width'  => 800,
			'height' => 400,
		);
	}

	/**
	 * 出してよい最小のスライドを返す。
	 *
	 * @param array $override 上書きするキー。
	 * @return array スライド。
	 */
	private function slide( array $override = array() ) {
		return array_merge(
			array(
				'id'          => 1,
				'title'       => '告知',
				'post_status' => 'publish',
				'menu_order'  => 0,
				'slider_id'   => 5,
				'images'      => array( 'image' => 10 ),
				'url'         => '/news/',
				'start'       => self::NOW - 100,
				'end'         => 0,
			),
			$override
		);
	}

	/**
	 * 画像枠（vk-image と同じ、必須の1枠）。
	 *
	 * @return array 枠。
	 */
	private function slots() {
		return array(
			'image' => array(
				'label'    => '画像',
				'required' => true,
			),
		);
	}

	/**
	 * 画像として実在するかを返す関数。
	 *
	 * @param int $id 添付ファイルの ID。
	 * @return bool
	 */
	public static function is_image( $id ) {
		return wp_attachment_is_image( $id );
	}

	/**
	 * ETSS_Render::block_id() のテスト。
	 *
	 * @return void
	 */
	public function test_block_id() {
		$this->assertSame( 'etss-5', ETSS_Render::block_id( 5 ), '1回目は etss-<ID>' );
		$this->assertSame( 'etss-5', ETSS_Render::block_id( 5, 1 ), '1回目の明示' );
		$this->assertSame( 'etss-5-2', ETSS_Render::block_id( 5, 2 ), '同じスライダーの2回目は連番' );
	}

	/**
	 * ETSS_Render::is_displayable() のテスト。
	 *
	 * @return void
	 */
	public function test_is_displayable() {
		$callback = array( __CLASS__, 'is_image' );

		$test_cases = array(
			array(
				'test_condition_name' => '必須項目がそろった公開中のスライド => 出す',
				'slide'               => $this->slide(),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '下書き => 出さない',
				'slide'               => $this->slide( array( 'post_status' => 'draft' ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '別のスライダーのスライド => 出さない',
				'slide'               => $this->slide( array( 'slider_id' => 6 ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '代替テキストが空白だけ => 出さない',
				'slide'               => $this->slide( array( 'title' => '  ' ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リンク先が無い => 出さない',
				'slide'               => $this->slide( array( 'url' => '' ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リンク先が // で始まる => 出さない',
				'slide'               => $this->slide( array( 'url' => '//example.com/' ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '必須の画像が無い => 出さない',
				'slide'               => $this->slide( array( 'images' => array() ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '画像が実在しない => 出さない',
				'slide'               => $this->slide( array( 'images' => array( 'image' => 99 ) ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '表示開始が未来 => 出さない',
				'slide'               => $this->slide( array( 'start' => self::NOW + 1 ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '表示開始ちょうど => 出す',
				'slide'               => $this->slide( array( 'start' => self::NOW ) ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '表示終了ちょうど => 出さない',
				'slide'               => $this->slide( array( 'end' => self::NOW ) ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '表示開始が未設定（0） => 出さない',
				'slide'               => $this->slide( array( 'start' => 0 ) ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Render::is_displayable( $case['slide'], 5, $this->slots(), self::NOW, $callback ), $case['test_condition_name'] );
		}
	}

	/**
	 * 画像枠が複数あるひな形の、必須でない枠・重複の扱い。
	 *
	 * @return void
	 */
	public function test_is_displayable_with_two_slots() {
		$slots = array(
			'wide' => array(
				'label'    => '横長',
				'required' => true,
			),
			'tall' => array(
				'label'    => '縦長',
				'required' => false,
			),
		);
		$cb    = array( __CLASS__, 'is_image' );

		$ok = $this->slide( array( 'images' => array( 'wide' => 10 ) ) );
		$this->assertTrue( ETSS_Render::is_displayable( $ok, 5, $slots, self::NOW, $cb ), '必須でない枠が空 => 出す' );

		$both = $this->slide(
			array(
				'images' => array(
					'wide' => 10,
					'tall' => 11,
				),
			)
		);
		$this->assertTrue( ETSS_Render::is_displayable( $both, 5, $slots, self::NOW, $cb ), '2枠とも別の画像 => 出す' );

		$same = $this->slide(
			array(
				'images' => array(
					'wide' => 10,
					'tall' => 10,
				),
			)
		);
		$this->assertFalse( ETSS_Render::is_displayable( $same, 5, $slots, self::NOW, $cb ), '同じ画像を2枠に => 出さない' );

		$broken = $this->slide(
			array(
				'images' => array(
					'wide' => 10,
					'tall' => 99,
				),
			)
		);
		$this->assertFalse( ETSS_Render::is_displayable( $broken, 5, $slots, self::NOW, $cb ), '必須でない枠の画像が実在しない => 出さない' );
	}

	/**
	 * ETSS_Render::select_slides() のテスト（出せるものだけを、表示順に並べる）。
	 *
	 * @return void
	 */
	public function test_select_slides() {
		$slides = array(
			$this->slide(
				array(
					'id'         => 1,
					'menu_order' => 2,
				)
			),
			$this->slide(
				array(
					'id'         => 2,
					'menu_order' => 1,
				)
			),
			$this->slide(
				array(
					'id'    => 3,
					'start' => self::NOW + 60,
				)
			),
			$this->slide(
				array(
					'id'         => 4,
					'menu_order' => 1,
					'start'      => self::NOW - 10,
				)
			),
		);

		$ids = array_column( ETSS_Render::select_slides( $slides, 5, $this->slots(), self::NOW, array( __CLASS__, 'is_image' ) ), 'id' );

		$this->assertSame( array( 4, 2, 1 ), $ids, '表示予定は外し、表示順 → 表示開始の新しい順に並べる' );
	}

	/**
	 * ETSS_Render::slider_html() のテスト。外枠の class と data-vkb-slider の blockId が揃う。
	 *
	 * @return void
	 */
	public function test_slider_html() {
		$options = ETSS_Meta::default_options();
		$slides  = array( ETSS_Render::slide_html( '<p>a</p>', 'vk_valign-center' ), ETSS_Render::slide_html( '<p>b</p>', '' ) );
		$html    = ETSS_Render::slider_html( 5, 'etss-5', $options, $slides, 'my-class' );

		$this->assertStringStartsWith( '<div class="wp-block-vk-blocks-slider swiper vk_slider vk_slider_etss-5 etss-slider etss-slider-5 my-class" data-vkb-slider="', $html, '外枠の class' );
		$this->assertStringContainsString( '&quot;blockId&quot;:&quot;etss-5&quot;', $html, 'data-vkb-slider の blockId が class と同じ' );
		$this->assertStringContainsString( '<div class="swiper-wrapper"><div class="wp-block-vk-blocks-slider-item vk_slider_item swiper-slide vk_valign-center">', $html, 'スライド項目は swiper-wrapper の直下' );
		$this->assertStringContainsString( '<div class="swiper-pagination swiper-pagination-bullets"></div>', $html, 'ドット' );
		$this->assertSame( 2, substr_count( $html, 'swiper-slide' ), 'スライドの数' );

		$hidden = ETSS_Render::slider_html( 5, 'etss-5', array_merge( $options, array( 'pagination' => 'hide' ) ), $slides, '' );
		$this->assertStringNotContainsString( 'swiper-pagination', $hidden, 'pagination が hide のときはドットを出さない' );
		$this->assertStringContainsString( 'etss-slider-5" data-vkb-slider', $hidden, '追加の class が空でも崩れない' );

		$full = ETSS_Render::slider_html( 5, 'etss-5', array_merge( $options, array( 'width' => 'full' ) ), $slides, '' );
		$this->assertStringContainsString( ' alignfull etss-slider', $full, '全幅は alignfull' );

		$wide = ETSS_Render::slider_html( 5, 'etss-5', array_merge( $options, array( 'width' => 'wide' ) ), $slides, '' );
		$this->assertStringContainsString( ' alignwide etss-slider', $wide, '幅広は alignwide' );
	}

	/**
	 * ETSS_Render::vk_settings() と parsed_block() のテスト。
	 *
	 * @return void
	 */
	public function test_vk_settings_and_parsed_block() {
		$options  = array_merge(
			ETSS_Meta::default_options(),
			array(
				'slidesPerViewPC' => 1.3,
				'centeredSlides'  => true,
			)
		);
		$settings = ETSS_Render::vk_settings( 'etss-5', $options );

		$this->assertSame( 'etss-5', $settings['blockId'], 'blockId' );
		$this->assertSame( 1.3, $settings['slidesPerViewPC'], '小数の見せる枚数' );
		$this->assertSame( 1, $settings['slidesPerViewMobile'], '整数はそのまま' );
		$this->assertTrue( $settings['centeredSlides'], '中央寄せ' );
		$this->assertFalse( $settings['pauseButton'], '一時停止ボタンは出さない' );

		$block = ETSS_Render::parsed_block( '<div></div>' );
		$this->assertSame( 'vk-blocks/slider', $block['blockName'], 'render_block に渡すブロック名' );
		$this->assertSame( array( '<div></div>' ), $block['innerContent'], '中身' );
	}

	/**
	 * ひな形 vk-image の render_item のテスト（1枚目の LCP の扱い）。
	 *
	 * @return void
	 */
	public function test_render_vk_image() {
		$slide = $this->slide( array( 'title' => 'A"B' ) );

		$first = ETSS_Templates::render_vk_image(
			$slide,
			array(
				'index'    => 0,
				'is_first' => true,
			)
		);
		$this->assertStringContainsString( 'data-no-lazy="1"', $first, '1枚目は data-no-lazy' );
		$this->assertStringNotContainsString( 'loading=', $first, '1枚目は loading を付けない' );
		$this->assertStringContainsString( 'fetchpriority="high"', $first, '1枚目は fetchpriority high' );
		$this->assertStringContainsString( 'alt="A&quot;B"', $first, '代替テキストはエスケープして入る' );
		$this->assertStringContainsString( '<figure class="wp-block-image size-full"><a href="/news/">', $first, 'リンク付きの画像ブロックの形' );

		$second = ETSS_Templates::render_vk_image(
			$slide,
			array(
				'index'    => 1,
				'is_first' => false,
			)
		);
		$this->assertStringContainsString( 'loading="lazy"', $second, '2枚目以降は lazy' );
		$this->assertStringNotContainsString( 'data-no-lazy', $second, '2枚目以降は data-no-lazy を付けない' );

		$xss = ETSS_Templates::render_vk_image(
			$this->slide( array( 'url' => 'https://example.com/?a="><script>' ) ),
			array(
				'index'    => 0,
				'is_first' => false,
			)
		);
		$this->assertStringNotContainsString( '"><script>', $xss, 'リンク先はエスケープする' );

		$missing = ETSS_Templates::render_vk_image( $this->slide( array( 'images' => array( 'image' => 99 ) ) ), array() );
		$this->assertSame( '', $missing, '画像が出せないときは空文字' );

		$template = ETSS_Templates::normalize( 'vk-image', ETSS_Templates::builtin_vk_image() );
		$this->assertSame( array(), $template['errors'], '本体のひな形は定義の検査を通る' );
		$this->assertTrue( is_callable( $template['template']['render_item'] ), 'render_item が呼び出せる' );
	}

	/**
	 * ETSS_Render::build_slide_htmls() と、空を除いたあとの枚数での調整のテスト。
	 *
	 * @return void
	 */
	public function test_build_slide_htmls_skips_empty() {
		// タイトルが「空」のスライドは空文字を返すひな形。
		$render = static function ( array $slide, array $context ) {
			return 'empty' === $slide['title'] ? '' : '<p data-i="' . $context['index'] . '" data-first="' . ( $context['is_first'] ? 1 : 0 ) . '">' . $slide['title'] . '</p>';
		};

		$slides = array(
			$this->slide( array( 'title' => 'empty' ) ),
			$this->slide( array( 'title' => 'A' ) ),
			$this->slide( array( 'title' => 'empty' ) ),
			$this->slide( array( 'title' => 'B' ) ),
		);
		$htmls  = ETSS_Render::build_slide_htmls( $slides, $render, 'x' );
		$this->assertCount( 2, $htmls, '空を返したスライドは除く' );
		$this->assertStringContainsString( 'data-i="0" data-first="1">A', $htmls[0], '先頭が空でも、実際の先頭に is_first が付く' );
		$this->assertStringContainsString( 'data-i="1" data-first="0">B', $htmls[1], 'index は除いたあとの並びで数える' );

		// 2枚のうち1枚が空 => 実際に出るのは1枚。ループ・自動送り・ドットを止める。
		$options = ETSS_Meta::default_options();
		$one     = ETSS_Render::build_slide_htmls( array_slice( $slides, 0, 2 ), $render, 'x' );
		$this->assertCount( 1, $one );
		$adjusted = ETSS_Loop::adjust_options( $options, count( $one ) );
		$this->assertFalse( $adjusted['loop'], '実際に出るのが1枚ならループを止める' );
		$this->assertFalse( $adjusted['autoPlay'], '自動送りも止める' );
		$this->assertSame( 'hide', $adjusted['pagination'], 'ドットも出さない' );
		$this->assertSame( $one, ETSS_Loop::repeat_for_loop( $one, $adjusted ), '1枚は繰り返さない' );

		// 3枚のうち2枚が空でない・要る枚数が 5 => 繰り返したあとも全て中身のある HTML で、要る枚数以上。
		$options  = array_merge(
			ETSS_Meta::default_options(),
			array(
				'loop'                => true,
				'slidesPerViewPC'     => 3,
				'slidesPerViewTablet' => 3,
				'slidesPerViewMobile' => 3,
				'centeredSlides'      => true,
				'slidesPerGroup'      => 'one-by-one',
			)
		);
		$two      = ETSS_Render::build_slide_htmls( array( $slides[0], $slides[1], $slides[3] ), $render, 'x' );
		$repeated = ETSS_Loop::repeat_for_loop( $two, ETSS_Loop::adjust_options( $options, count( $two ) ) );
		$this->assertGreaterThanOrEqual( ETSS_Loop::required_count( $options ), count( $repeated ), '繰り返したあとの枚数が、要る枚数を下回らない' );
		foreach ( $repeated as $html ) {
			$this->assertStringContainsString( 'vk_slider_item_container"><p', $html, '空のスライドが混ざらない' );
		}

		// 全て空 / 文字列以外 => 何も出さない。
		$null_render = static function () {
			return null;
		};
		$this->assertSame( array(), ETSS_Render::build_slide_htmls( array( $slides[0], $slides[2] ), $render, 'x' ), '全て空なら空の配列' );
		$this->assertSame( array(), ETSS_Render::build_slide_htmls( array( $slides[1] ), $null_render, 'x' ), '文字列以外も除く' );
	}

	/**
	 * ETSS_Block::keep_no_lazy() のテスト。
	 *
	 * @return void
	 */
	public function test_keep_no_lazy() {
		$this->assertFalse( ETSS_Block::keep_no_lazy( 'lazy', '<img src="a.jpg" data-no-lazy="1" />' ), '1枚目の画像には loading を付けない' );
		$this->assertSame( 'lazy', ETSS_Block::keep_no_lazy( 'lazy', '<img src="a.jpg" />' ), 'ほかの画像は WordPress の判断のまま' );
		$this->assertSame( 'lazy', ETSS_Block::keep_no_lazy( 'lazy', '' ), '空の HTML でも落ちない' );
	}
}
