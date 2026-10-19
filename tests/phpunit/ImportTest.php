<?php
/**
 * inc/class-etss-import.php（移行コマンドの、取り出しと割り当て）のテスト。
 *
 * 入力データは、検証サイト ai-wp-demo に置いた VK Blocks のスライダー（5枚・リンクあり3枚／なし2枚）の本文を
 * `parse_blocks()` に通した結果（tests/phpunit/fixtures/vk-slider-blocks.json。元の HTML は vk-slider.html）。
 * 導入先のサイトの HTML・画像の URL・告知文は使わない。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Import のテスト。
 */
class ImportTest extends TestCase {

	/**
	 * VK のスライダーの本文（parse_blocks() の結果）を読む。
	 *
	 * @return array ブロックの配列。
	 */
	private function fixture_blocks() {
		$blocks = json_decode( file_get_contents( __DIR__ . '/fixtures/vk-slider-blocks.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 手元のファイルを読む。
		$this->assertIsArray( $blocks, '入力データが読める' );

		return $blocks;
	}

	/**
	 * 入力データの VK のスライダーを取り出す結果（parse_slider()）を返す。
	 *
	 * @return array parse_slider() の結果。
	 */
	private function fixture_parsed() {
		$sliders = ETSS_Import::find_sliders( $this->fixture_blocks() );

		return ETSS_Import::parse_slider( $sliders[0] );
	}

	/**
	 * 本体のひな形 `vk-image`（画像枠は1つ）の定義を返す。
	 *
	 * @return array ひな形の定義。
	 */
	private function template_single() {
		$result = ETSS_Templates::normalize( 'vk-image', ETSS_Templates::builtin_vk_image() );

		return $result['template'];
	}

	/**
	 * 画像枠が2つ（どちらも必須）のひな形を返す。`import_item` は、1枚目を pc、2枚目を sp に入れる。
	 *
	 * @param bool $with_import_item `import_item` を持たせるか。
	 * @return array ひな形の定義。
	 */
	private function template_double( $with_import_item = true ) {
		$args = array(
			'label' => '横長と縦長',
			'slots' => array(
				'pc' => array(
					'label'    => '横長',
					'required' => true,
				),
				'sp' => array(
					'label'    => '縦長',
					'required' => true,
				),
			),
		);
		if ( $with_import_item ) {
			$args['import_item'] = array( __CLASS__, 'import_item_double' );
		}
		$result = ETSS_Templates::normalize( 'double', $args );

		return $result['template'];
	}

	/**
	 * 2枠のひな形の `import_item`。1枚目を pc、2枚目を sp に割り当てる。
	 *
	 * @param array $parsed ETSS_Import::parse_item() の結果。
	 * @return array 枠 ID => 添付ファイル ID。
	 */
	public static function import_item_double( array $parsed ) {
		$assigned = array();
		if ( isset( $parsed['images'][0] ) ) {
			$assigned['pc'] = $parsed['images'][0]['id'];
		}
		if ( isset( $parsed['images'][1] ) ) {
			$assigned['sp'] = $parsed['images'][1]['id'];
		}

		return $assigned;
	}

	/**
	 * 添付ファイルが実在するかを返す関数を作る（指定した ID だけが実在する）。
	 *
	 * @param int[] $existing 実在する添付ファイルの ID。
	 * @return callable `callable( int ): bool`。
	 */
	private function exists_only( array $existing ) {
		return function ( $attachment_id ) use ( $existing ) {
			return in_array( $attachment_id, $existing, true );
		};
	}

	/**
	 * 画像と項目のブロックを作る（`wp-image-NNN` の class つきの画像1枚）。
	 *
	 * @param string $inner_html 項目の中の HTML。
	 * @param array  $attrs      項目の属性。
	 * @param array  $inner      項目の中のブロック。
	 * @return array VK のスライダー項目のブロック。
	 */
	private function item_block( $inner_html, array $attrs = array(), array $inner = array() ) {
		return array(
			'blockName'    => 'vk-blocks/slider-item',
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => '<div class="vk_slider_item">' . $inner_html . '</div>',
			'innerContent' => array( '<div class="vk_slider_item">' . $inner_html, null, '</div>' ),
		);
	}

	/**
	 * ETSS_Import::find_sliders() のテスト。
	 *
	 * @return void
	 */
	public function test_find_sliders() {
		$slider    = array(
			'blockName'    => 'vk-blocks/slider',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		$paragraph = array(
			'blockName'   => 'core/paragraph',
			'innerBlocks' => array(),
		);
		$group     = array(
			'blockName'   => 'core/group',
			'innerBlocks' => array(
				$paragraph,
				$slider,
			),
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'ai-wp-demo の本文 => VK のスライダーが1つ',
				'blocks'              => $this->fixture_blocks(),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => 'グループの中に置かれたスライダーも見つかる => 1つ',
				'blocks'              => array( $paragraph, $group ),
				'expected'            => 1,
			),
			array(
				'test_condition_name' => 'スライダーが2つ（直下とグループの中） => 2つ',
				'blocks'              => array( $slider, $group ),
				'expected'            => 2,
			),
			array(
				'test_condition_name' => 'VK のスライダーが無い => 0',
				'blocks'              => array( $paragraph ),
				'expected'            => 0,
			),
			array(
				'test_condition_name' => '空の本文 => 0',
				'blocks'              => array(),
				'expected'            => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertCount( $case['expected'], ETSS_Import::find_sliders( $case['blocks'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Import::block_html() のテスト。
	 *
	 * @return void
	 */
	public function test_block_html() {
		$inner = array(
			'innerHTML'    => '<b>中</b>',
			'innerContent' => array( '<b>中</b>' ),
		);

		$test_cases = array(
			array(
				'test_condition_name' => '中のブロックを、null の位置に差し込む',
				'block'               => array(
					'innerHTML'    => '<div></div>',
					'innerContent' => array( '<div>', null, '</div>' ),
					'innerBlocks'  => array( $inner ),
				),
				'expected'            => '<div><b>中</b></div>',
			),
			array(
				'test_condition_name' => '中のブロックが2つ => 出てくる順に差し込む',
				'block'               => array(
					'innerHTML'    => '',
					'innerContent' => array( '<p>', null, '|', null, '</p>' ),
					'innerBlocks'  => array( $inner, $inner ),
				),
				'expected'            => '<p><b>中</b>|<b>中</b></p>',
			),
			array(
				'test_condition_name' => 'innerContent が無い => innerHTML',
				'block'               => array( 'innerHTML' => '<i>x</i>' ),
				'expected'            => '<i>x</i>',
			),
			array(
				'test_condition_name' => '中のブロックが足りない（null の数より少ない） => 差し込まずに終わる',
				'block'               => array(
					'innerHTML'    => '',
					'innerContent' => array( '<p>', null, '</p>' ),
					'innerBlocks'  => array(),
				),
				'expected'            => '<p></p>',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Import::block_html( $case['block'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Import::parse_settings() のテスト。
	 *
	 * @return void
	 */
	public function test_parse_settings() {
		$sliders = ETSS_Import::find_sliders( $this->fixture_blocks() );
		$actual  = ETSS_Import::parse_settings( $sliders[0] );

		$this->assertSame( 4000, $actual['autoPlayDelay'], 'data-vkb-slider の autoPlayDelay を読む' );
		$this->assertSame( 800, $actual['speed'], 'data-vkb-slider の speed を読む' );
		$this->assertSame( 'bullets', $actual['pagination'], 'data-vkb-slider の pagination を読む' );
		$this->assertTrue( $actual['loop'], 'data-vkb-slider の loop を読む' );
		$this->assertSame( '9c5e2f10-1a2b-4c3d-8e9f-0a1b2c3d4e5f', $actual['blockId'], 'blockId を読む' );

		$test_cases = array(
			array(
				'test_condition_name' => 'data-vkb-slider が壊れた JSON => ブロックの属性で代える',
				'slider'              => array(
					'innerHTML' => '<div data-vkb-slider="{broken">',
					'attrs'     => array( 'speed' => 700 ),
				),
				'expected'            => array( 'speed' => 700 ),
			),
			array(
				'test_condition_name' => 'data-vkb-slider が無い => ブロックの属性で代える',
				'slider'              => array(
					'innerHTML' => '<div class="vk_slider">',
					'attrs'     => array( 'autoPlay' => false ),
				),
				'expected'            => array( 'autoPlay' => false ),
			),
			array(
				'test_condition_name' => '何も読めない => 空',
				'slider'              => array(
					'innerHTML' => '',
					'attrs'     => array(),
				),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'シングルクォートの属性も読める',
				'slider'              => array(
					'innerHTML' => "<div data-vkb-slider='{\"speed\":900}'>",
					'attrs'     => array(),
				),
				'expected'            => array( 'speed' => 900 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Import::parse_settings( $case['slider'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Import::parse_item() のテスト。
	 *
	 * @return void
	 */
	public function test_parse_item() {
		$parsed = $this->fixture_parsed();

		// ai-wp-demo の5項目（リンクあり3・なし2）。
		$fixture_cases = array(
			array(
				'test_condition_name' => '1枚目: サイト内のパスのリンクつき',
				'index'               => 0,
				'expected'            => array(
					'attachment_id' => 538,
					'url'           => '/campaign-a/',
					'alt'           => 'デモ 1 の告知文',
				),
			),
			array(
				'test_condition_name' => '2枚目: リンクなし => url は空',
				'index'               => 1,
				'expected'            => array(
					'attachment_id' => 539,
					'url'           => '',
					'alt'           => 'デモ 2 の告知文',
				),
			),
			array(
				'test_condition_name' => '3枚目: 実体参照（&amp;）を含むリンクと alt => 文字に戻す',
				'index'               => 2,
				'expected'            => array(
					'attachment_id' => 540,
					'url'           => 'https://example.com/news/?id=3&from=slider',
					'alt'           => 'デモ 3 の告知文（&を含む）',
				),
			),
			array(
				'test_condition_name' => '4枚目: target と rel つきのリンク => href だけを取る',
				'index'               => 3,
				'expected'            => array(
					'attachment_id' => 541,
					'url'           => 'https://example.com/event/',
					'alt'           => 'デモ 4 の告知文',
				),
			),
			array(
				'test_condition_name' => '5枚目: リンクなし',
				'index'               => 4,
				'expected'            => array(
					'attachment_id' => 542,
					'url'           => '',
					'alt'           => 'デモ 5 の告知文',
				),
			),
		);

		$this->assertCount( 5, $parsed['items'], 'スライダー項目は5つ' );
		foreach ( $fixture_cases as $case ) {
			$item = $parsed['items'][ $case['index'] ];
			$this->assertSame( $case['expected']['attachment_id'], $item['attachment_id'], $case['test_condition_name'] . '（添付ファイル ID）' );
			$this->assertSame( $case['expected']['url'], $item['url'], $case['test_condition_name'] . '（リンク先）' );
			$this->assertSame( $case['expected']['alt'], $item['alt'], $case['test_condition_name'] . '（alt）' );
			$this->assertCount( 1, $item['images'], $case['test_condition_name'] . '（画像は1枚）' );
		}

		// 組み立てた項目で、取り出しの境界を確かめる。
		$image_block = array(
			'blockName'    => 'core/image',
			'attrs'        => array( 'id' => 77 ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<figure><img src="a.png" alt="ブロック属性の画像"/></figure>',
			'innerContent' => array( '<figure><img src="a.png" alt="ブロック属性の画像"/></figure>' ),
		);

		$synthetic_cases = array(
			array(
				'test_condition_name' => '2枚の画像（最初の alt は空） => ID は出てくる順・alt は空でない最初のもの',
				'item'                => $this->item_block( '<img src="a.png" alt="" class="wp-image-10"/><img src="b.png" alt="二枚目" class="size-full wp-image-11"/>' ),
				'expected'            => array(
					'ids' => array( 10, 11 ),
					'url' => '',
					'alt' => '二枚目',
				),
			),
			array(
				'test_condition_name' => 'wp-image-NNN の class が無い => 同じ順の画像ブロックの id 属性で代える',
				'item'                => $this->item_block( '', array(), array( $image_block ) ),
				'expected'            => array(
					'ids' => array( 77 ),
					'url' => '',
					'alt' => 'ブロック属性の画像',
				),
			),
			array(
				'test_condition_name' => 'class にも属性にも ID が無い => ID は 0',
				'item'                => $this->item_block( '<img src="a.png" alt="x"/>' ),
				'expected'            => array(
					'ids' => array( 0 ),
					'url' => '',
					'alt' => 'x',
				),
			),
			array(
				'test_condition_name' => '画像が無く、linkUrl 属性だけある => リンク先は属性から',
				'item'                => $this->item_block( '<p>文字だけ</p>', array( 'linkUrl' => 'https://example.com/only-attr/' ) ),
				'expected'            => array(
					'ids' => array(),
					'url' => 'https://example.com/only-attr/',
					'alt' => '',
				),
			),
			array(
				'test_condition_name' => '複数のリンク => 最初のリンク先',
				'item'                => $this->item_block( '<a href="/first/"><img src="a.png" alt="a" class="wp-image-5"/></a><a href="/second/">続き</a>' ),
				'expected'            => array(
					'ids' => array( 5 ),
					'url' => '/first/',
					'alt' => 'a',
				),
			),
			array(
				'test_condition_name' => 'wp-image-10 に似た class（wp-image-10x や not-wp-image-10）は ID にしない',
				'item'                => $this->item_block( '<img src="a.png" alt="a" class="not-wp-image-10 wp-image-10x"/>' ),
				'expected'            => array(
					'ids' => array( 0 ),
					'url' => '',
					'alt' => 'a',
				),
			),
		);

		foreach ( $synthetic_cases as $case ) {
			$item = ETSS_Import::parse_item( $case['item'] );
			$this->assertSame( $case['expected']['ids'], array_column( $item['images'], 'id' ), $case['test_condition_name'] . '（ID）' );
			$this->assertSame( $case['expected']['url'], $item['url'], $case['test_condition_name'] . '（リンク先）' );
			$this->assertSame( $case['expected']['alt'], $item['alt'], $case['test_condition_name'] . '（alt）' );
		}

		$background = ETSS_Import::parse_item( $this->item_block( '', array( 'bgImage' => 'https://example.com/bg.png' ) ) );
		$this->assertTrue( $background['has_background_image'], '背景画像の指定を知らせる' );
		$this->assertFalse( $parsed['items'][0]['has_background_image'], '背景画像が無ければ false' );
	}

	/**
	 * ETSS_Import::parse_slider() のテスト。
	 *
	 * @return void
	 */
	public function test_parse_slider() {
		$parsed = $this->fixture_parsed();

		$this->assertCount( 5, $parsed['items'], '項目は元の並びで5つ' );
		$this->assertSame( array( 538, 539, 540, 541, 542 ), array_column( $parsed['items'], 'attachment_id' ), '元の並びのまま' );
		$this->assertSame( 4000, $parsed['settings']['autoPlayDelay'], '設定も読む' );

		$empty = ETSS_Import::parse_slider(
			array(
				'blockName'   => 'vk-blocks/slider',
				'innerHTML'   => '',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName' => 'core/paragraph',
					),
				),
			)
		);
		$this->assertSame( array(), $empty['items'], 'スライダー項目でないブロックは項目に数えない' );
	}

	/**
	 * ETSS_Import::assign_images() のテスト。
	 *
	 * @return void
	 */
	public function test_assign_images() {
		$two_images = array(
			'images'        => array(
				array(
					'id'  => 10,
					'alt' => 'a',
					'src' => '',
				),
				array(
					'id'  => 11,
					'alt' => 'b',
					'src' => '',
				),
			),
			'attachment_id' => 10,
		);
		$no_images  = array(
			'images'        => array(),
			'attachment_id' => 0,
		);

		$with_unknown = ETSS_Templates::normalize(
			'odd',
			array(
				'label'       => 'import_item が変な値を返す',
				'slots'       => array(
					'main' => array( 'label' => 'メイン' ),
				),
				'import_item' => function () {
					return array(
						'main'    => '12',
						'unknown' => 13,
					);
				},
			)
		);

		$test_cases = array(
			array(
				'test_condition_name' => 'import_item が無い => 1枚目の画像を最初の枠に入れる',
				'parsed'              => $two_images,
				'template'            => $this->template_single(),
				'expected'            => array( 'image' => 10 ),
			),
			array(
				'test_condition_name' => 'import_item が無い・画像が無い => 何も入れない',
				'parsed'              => $no_images,
				'template'            => $this->template_single(),
				'expected'            => array(),
			),
			array(
				'test_condition_name' => 'import_item が無い・枠が2つ => 1枚目だけ最初の枠に入れる',
				'parsed'              => $two_images,
				'template'            => $this->template_double( false ),
				'expected'            => array( 'pc' => 10 ),
			),
			array(
				'test_condition_name' => 'import_item がある => ひな形の割り当てに従う',
				'parsed'              => $two_images,
				'template'            => $this->template_double(),
				'expected'            => array(
					'pc' => 10,
					'sp' => 11,
				),
			),
			array(
				'test_condition_name' => 'import_item がある・画像が1枚 => 入れられる枠だけ入れる',
				'parsed'              => array(
					'images'        => array_slice( $two_images['images'], 0, 1 ),
					'attachment_id' => 10,
				),
				'template'            => $this->template_double(),
				'expected'            => array( 'pc' => 10 ),
			),
			array(
				'test_condition_name' => 'import_item がひな形に無い枠 ID を返した => 捨てる。数字の文字列は整数にする',
				'parsed'              => $two_images,
				'template'            => $with_unknown['template'],
				'expected'            => array( 'main' => 12 ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Import::assign_images( $case['parsed'], $case['template'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Import::plan_item() のテスト。
	 *
	 * @return void
	 */
	public function test_plan_item() {
		$parsed = $this->fixture_parsed();
		$exists = $this->exists_only( array( 538, 539, 540, 541, 542, 10, 11 ) );

		$base_item = function ( array $override = array() ) {
			return array_merge(
				array(
					'images'               => array(
						array(
							'id'  => 10,
							'alt' => 'a',
							'src' => '',
						),
					),
					'attachment_id'        => 10,
					'url'                  => '/a/',
					'alt'                  => '告知',
					'has_background_image' => false,
				),
				$override
			);
		};

		$test_cases = array(
			array(
				'test_condition_name' => 'リンクあり・画像あり => 表示',
				'parsed'              => $parsed['items'][0],
				'template'            => $this->template_single(),
				'expected'            => array(
					'status'  => 'publish',
					'reasons' => array(),
					'images'  => array( 'image' => 538 ),
					'url'     => '/campaign-a/',
					'title'   => 'デモ 1 の告知文',
				),
			),
			array(
				'test_condition_name' => 'リンクなし => 下書き（リンク先がありません）',
				'parsed'              => $parsed['items'][1],
				'template'            => $this->template_single(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array( 'リンク先がありません' ),
					'images'  => array( 'image' => 539 ),
					'url'     => '',
					'title'   => 'デモ 2 の告知文',
				),
			),
			array(
				'test_condition_name' => '存在しない添付ファイル => 下書き・その画像は入れない',
				'parsed'              => $base_item(
					array(
						'images'        => array(
							array(
								'id'  => 999,
								'alt' => 'a',
								'src' => '',
							),
						),
						'attachment_id' => 999,
					)
				),
				'template'            => $this->template_single(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array( '添付ファイル #999 が画像として見つかりません（枠「画像」）' ),
					'images'  => array(),
					'url'     => '/a/',
					'title'   => '告知',
				),
			),
			array(
				'test_condition_name' => '画像が見つからない（背景画像だけ） => 下書き・背景画像は移せないと伝える',
				'parsed'              => $base_item(
					array(
						'images'               => array(),
						'attachment_id'        => 0,
						'has_background_image' => true,
					)
				),
				'template'            => $this->template_single(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array( '画像が見つかりません（背景画像は移せません）' ),
					'images'  => array(),
					'url'     => '/a/',
					'title'   => '告知',
				),
			),
			array(
				'test_condition_name' => '画像が見つからない（背景画像の指定も無い） => 下書き',
				'parsed'              => $base_item(
					array(
						'images'        => array(),
						'attachment_id' => 0,
					)
				),
				'template'            => $this->template_single(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array( '画像が見つかりません' ),
					'images'  => array(),
					'url'     => '/a/',
					'title'   => '告知',
				),
			),
			array(
				'test_condition_name' => '添付ファイル ID が読めない => 下書き（必須の枠の理由は重ねない）',
				'parsed'              => $base_item(
					array(
						'images'        => array(
							array(
								'id'  => 0,
								'alt' => 'a',
								'src' => '',
							),
						),
						'attachment_id' => 0,
					)
				),
				'template'            => $this->template_single(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array( '画像の添付ファイル ID が読み取れません（wp-image-NNN の class がありません）' ),
					'images'  => array(),
					'url'     => '/a/',
					'title'   => '告知',
				),
			),
			array(
				'test_condition_name' => '必須の枠が2つで画像が1枚 => 下書き（足りない枠の名前を出す）',
				'parsed'              => $base_item(),
				'template'            => $this->template_double(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array( '必須の画像枠「縦長」に入れる画像がありません' ),
					'images'  => array( 'pc' => 10 ),
					'url'     => '/a/',
					'title'   => '告知',
				),
			),
			array(
				'test_condition_name' => '必須の枠が2つで画像が2枚 => 表示',
				'parsed'              => $base_item(
					array(
						'images' => array(
							array(
								'id'  => 10,
								'alt' => 'a',
								'src' => '',
							),
							array(
								'id'  => 11,
								'alt' => 'b',
								'src' => '',
							),
						),
					)
				),
				'template'            => $this->template_double(),
				'expected'            => array(
					'status'  => 'publish',
					'reasons' => array(),
					'images'  => array(
						'pc' => 10,
						'sp' => 11,
					),
					'url'     => '/a/',
					'title'   => '告知',
				),
			),
			array(
				'test_condition_name' => '同じ画像が2つの枠に入る => 下書き',
				'parsed'              => $base_item(
					array(
						'images' => array(
							array(
								'id'  => 10,
								'alt' => 'a',
								'src' => '',
							),
							array(
								'id'  => 10,
								'alt' => 'a',
								'src' => '',
							),
						),
					)
				),
				'template'            => $this->template_double(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array( '同じ画像が複数の枠に入っています' ),
					'images'  => array(
						'pc' => 10,
						'sp' => 10,
					),
					'url'     => '/a/',
					'title'   => '告知',
				),
			),
			array(
				'test_condition_name' => 'alt が空 => 下書き',
				'parsed'              => $base_item( array( 'alt' => '' ) ),
				'template'            => $this->template_single(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array( 'alt（代替テキスト）が空です' ),
					'images'  => array( 'image' => 10 ),
					'url'     => '/a/',
					'title'   => '',
				),
			),
			array(
				'test_condition_name' => 'リンク先の形式が正しくない（javascript:） => 下書き・使えないリンク先は入れない',
				'parsed'              => $base_item( array( 'url' => 'javascript:alert(1)' ) ),
				'template'            => $this->template_single(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array( 'リンク先の形式が正しくありません（javascript:alert(1)）' ),
					'images'  => array( 'image' => 10 ),
					'url'     => '',
					'title'   => '告知',
				),
			),
			array(
				'test_condition_name' => '理由が複数 => すべて並べる（alt・リンク先・添付ファイル）',
				'parsed'              => $base_item(
					array(
						'images'        => array(
							array(
								'id'  => 999,
								'alt' => '',
								'src' => '',
							),
						),
						'attachment_id' => 999,
						'alt'           => '',
						'url'           => '',
					)
				),
				'template'            => $this->template_single(),
				'expected'            => array(
					'status'  => 'draft',
					'reasons' => array(
						'添付ファイル #999 が画像として見つかりません（枠「画像」）',
						'alt（代替テキスト）が空です',
						'リンク先がありません',
					),
					'images'  => array(),
					'url'     => '',
					'title'   => '',
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Import::plan_item( $case['parsed'], $case['template'], $exists );
			foreach ( array( 'status', 'reasons', 'images', 'url', 'title' ) as $key ) {
				$this->assertSame( $case['expected'][ $key ], $actual[ $key ], $case['test_condition_name'] . '（' . $key . '）' );
			}
		}
	}

	/**
	 * ETSS_Import::plan() のテスト。
	 *
	 * @return void
	 */
	public function test_plan() {
		$parsed = $this->fixture_parsed();
		$start  = gmmktime( 3, 0, 0, 11, 6, 2026 );

		$test_cases = array(
			array(
				'test_condition_name' => '添付ファイルがすべて実在 => リンクなしの2枚だけ下書き',
				'existing'            => array( 538, 539, 540, 541, 542 ),
				'expected_status'     => array( 'publish', 'draft', 'publish', 'publish', 'draft' ),
				'expected_images'     => array(
					array( 'image' => 538 ),
					array( 'image' => 539 ),
					array( 'image' => 540 ),
					array( 'image' => 541 ),
					array( 'image' => 542 ),
				),
			),
			array(
				'test_condition_name' => '540 が存在しない => 3枚目も下書き・画像は入れない',
				'existing'            => array( 538, 539, 541, 542 ),
				'expected_status'     => array( 'publish', 'draft', 'draft', 'publish', 'draft' ),
				'expected_images'     => array(
					array( 'image' => 538 ),
					array( 'image' => 539 ),
					array(),
					array( 'image' => 541 ),
					array( 'image' => 542 ),
				),
			),
			array(
				'test_condition_name' => '添付ファイルが1つも無い => すべて下書き',
				'existing'            => array(),
				'expected_status'     => array( 'draft', 'draft', 'draft', 'draft', 'draft' ),
				'expected_images'     => array( array(), array(), array(), array(), array() ),
			),
		);

		foreach ( $test_cases as $case ) {
			$plan = ETSS_Import::plan( $parsed, $this->template_single(), $this->exists_only( $case['existing'] ), $start );

			$this->assertSame( $case['expected_status'], array_column( $plan, 'status' ), $case['test_condition_name'] . '（状態）' );
			$this->assertSame( $case['expected_images'], array_column( $plan, 'images' ), $case['test_condition_name'] . '（画像）' );
			$this->assertSame( array( 0, 1, 2, 3, 4 ), array_column( $plan, 'menu_order' ), $case['test_condition_name'] . '（表示順は元の並び）' );
			$this->assertSame( array_fill( 0, 5, $start ), array_column( $plan, 'start' ), $case['test_condition_name'] . '（表示開始）' );
			$this->assertSame( array_fill( 0, 5, 0 ), array_column( $plan, 'end' ), $case['test_condition_name'] . '（表示終了なし）' );
		}

		$this->assertSame( array(), ETSS_Import::plan( array( 'items' => array() ), $this->template_single(), $this->exists_only( array() ), $start ), '項目が無ければ計画も空' );
	}

	/**
	 * ETSS_Import::map_options() のテスト。
	 *
	 * @return void
	 */
	public function test_map_options() {
		$parsed   = $this->fixture_parsed();
		$defaults = ETSS_Meta::default_options();

		$test_cases = array(
			array(
				'test_condition_name' => 'ai-wp-demo の設定（間隔 4000・速さ 800）を既定値のスライダーへ => その2項目が変わる',
				'vk'                  => $parsed['settings'],
				'current'             => $defaults,
				'expected_changes'    => array( 'autoPlayDelay', 'speed' ),
				'expected_options'    => array(
					'autoPlayDelay' => 4000,
					'speed'         => 800,
					'pagination'    => 'bullets',
				),
				'expected_unsupp'     => array(),
			),
			array(
				'test_condition_name' => 'VK の設定がスライダーの設定と同じ => 変わる項目なし',
				'vk'                  => array(
					'autoPlay' => true,
					'speed'    => 300,
				),
				'current'             => $defaults,
				'expected_changes'    => array(),
				'expected_options'    => array(
					'autoPlay' => true,
					'speed'    => 300,
				),
				'expected_unsupp'     => array(),
			),
			array(
				'test_condition_name' => '複数枚表示・中央寄せ・フェード・ドットを番号に・全幅 => 写す',
				'vk'                  => array(
					'slidesPerViewPC' => 3.5,
					'centeredSlides'  => true,
					'effect'          => 'fade',
					'pagination'      => 'fraction',
					'width'           => 'full',
					'autoPlay'        => false,
					'loop'            => false,
				),
				'current'             => $defaults,
				'expected_changes'    => array( 'autoPlay', 'pagination', 'loop', 'effect', 'slidesPerViewPC', 'centeredSlides', 'width' ),
				'expected_options'    => array(
					'slidesPerViewPC' => 3.5,
					'centeredSlides'  => true,
					'effect'          => 'fade',
					'pagination'      => 'fraction',
					'width'           => 'full',
					'autoPlay'        => false,
					'loop'            => false,
				),
				'expected_unsupp'     => array(),
			),
			array(
				'test_condition_name' => '選択肢にない値・数でない値 => いまの値のまま',
				'vk'                  => array(
					'pagination' => 'weird',
					'speed'      => 'abc',
					'effect'     => 'cube',
				),
				'current'             => array_merge(
					$defaults,
					array(
						'pagination' => 'hide',
						'speed'      => 1000,
					)
				),
				'expected_changes'    => array(),
				'expected_options'    => array(
					'pagination' => 'hide',
					'speed'      => 1000,
					'effect'     => 'slide',
				),
				'expected_unsupp'     => array(),
			),
			array(
				'test_condition_name' => '範囲外の数値 => 範囲に収める',
				'vk'                  => array(
					'speed'         => 999999,
					'autoPlayDelay' => 1,
				),
				'current'             => $defaults,
				'expected_changes'    => array( 'autoPlayDelay', 'speed' ),
				'expected_options'    => array(
					'speed'         => 10000,
					'autoPlayDelay' => 500,
				),
				'expected_unsupp'     => array(),
			),
			array(
				'test_condition_name' => '一時停止ボタンとズームが使われている => 写せないと知らせる。動きの設定は変えない',
				'vk'                  => array(
					'pauseButton'   => true,
					'zoomAnimation' => true,
				),
				'current'             => $defaults,
				'expected_changes'    => array(),
				'expected_options'    => array( 'autoPlay' => true ),
				'expected_unsupp'     => array( 'pauseButton', 'zoomAnimation' ),
			),
			array(
				'test_condition_name' => 'VK の設定が空 => 何も変えない。足りない現在値は既定値で補う',
				'vk'                  => array(),
				'current'             => array( 'speed' => 500 ),
				'expected_changes'    => array(),
				'expected_options'    => array(
					'speed'    => 500,
					'autoPlay' => true,
				),
				'expected_unsupp'     => array(),
			),
		);

		foreach ( $test_cases as $case ) {
			$actual = ETSS_Import::map_options( $case['vk'], $case['current'] );

			$this->assertSame( $case['expected_changes'], array_keys( $actual['changes'] ), $case['test_condition_name'] . '（変わる項目）' );
			foreach ( $case['expected_options'] as $key => $value ) {
				$this->assertEquals( $value, $actual['options'][ $key ], $case['test_condition_name'] . '（' . $key . '）' );
			}
			$this->assertSame( $case['expected_unsupp'], $actual['unsupported'], $case['test_condition_name'] . '（写せない設定）' );
			$this->assertSame( array_keys( $defaults ), array_keys( $actual['options'] ), $case['test_condition_name'] . '（項目は動きの設定の項目だけ）' );
		}

		$changed = ETSS_Import::map_options( $parsed['settings'], $defaults );
		$this->assertSame(
			array(
				'from' => 2500,
				'to'   => 4000,
			),
			$changed['changes']['autoPlayDelay'],
			'変わる項目は、いまの値と写す値を持つ'
		);
	}

	/**
	 * ETSS_Import::validate_args() のテスト。
	 *
	 * @return void
	 */
	public function test_validate_args() {
		$test_cases = array(
			array(
				'test_condition_name' => '投稿 ID 123・index 0 => 問題なし',
				'post_id'             => '123',
				'index'               => 0,
				'expected_error'      => false,
			),
			array(
				'test_condition_name' => 'index が文字列の 2 => 問題なし',
				'post_id'             => 5,
				'index'               => '2',
				'expected_error'      => false,
			),
			array(
				'test_condition_name' => 'index が負数 => エラー（--index と指定の仕方を含む）',
				'post_id'             => '123',
				'index'               => '-1',
				'expected_error'      => true,
			),
			array(
				'test_condition_name' => 'index が数でない => エラー',
				'post_id'             => '123',
				'index'               => 'abc',
				'expected_error'      => true,
			),
			array(
				'test_condition_name' => 'index が小数 => エラー',
				'post_id'             => '123',
				'index'               => '1.5',
				'expected_error'      => true,
			),
			array(
				'test_condition_name' => '投稿 ID が 0 => エラー',
				'post_id'             => '0',
				'index'               => 0,
				'expected_error'      => true,
			),
			array(
				'test_condition_name' => '投稿 ID が負数 => エラー',
				'post_id'             => '-3',
				'index'               => 0,
				'expected_error'      => true,
			),
			array(
				'test_condition_name' => '投稿 ID が数でない => エラー',
				'post_id'             => 'abc',
				'index'               => 0,
				'expected_error'      => true,
			),
		);

		foreach ( $test_cases as $case ) {
			$error = ETSS_Import::validate_args( $case['post_id'], $case['index'] );
			$this->assertSame( $case['expected_error'], '' !== $error, $case['test_condition_name'] );
		}

		$this->assertStringContainsString( '--index', ETSS_Import::validate_args( '1', '-1' ), 'index のエラーは --index を名指しする' );
		$this->assertStringContainsString( '0 以上', ETSS_Import::validate_args( '1', '-1' ), 'index のエラーは指定の仕方を伝える' );
		$this->assertStringContainsString( '投稿 ID', ETSS_Import::validate_args( '0', 0 ), '投稿 ID のエラーは投稿 ID を名指しする' );
	}

	/**
	 * ETSS_Import::parse_start() のテスト。
	 *
	 * @return void
	 */
	public function test_parse_start() {
		$tokyo = new DateTimeZone( 'Asia/Tokyo' );
		$now   = gmmktime( 0, 0, 0, 10, 19, 2026 );
		$at    = gmmktime( 3, 0, 0, 11, 6, 2026 );

		$test_cases = array(
			array(
				'test_condition_name' => '指定なし => 今',
				'value'               => null,
				'expected'            => $now,
			),
			array(
				'test_condition_name' => '2026-11-06T12:00（東京） => UTC 3:00',
				'value'               => '2026-11-06T12:00',
				'expected'            => $at,
			),
			array(
				'test_condition_name' => '日付と時刻の間が空白 => 同じ',
				'value'               => '2026-11-06 12:00',
				'expected'            => $at,
			),
			array(
				'test_condition_name' => '秒つき => 秒まで読む',
				'value'               => '2026-11-06T12:00:30',
				'expected'            => $at + 30,
			),
			array(
				'test_condition_name' => '前後の空白は落とす',
				'value'               => '  2026-11-06T12:00 ',
				'expected'            => $at,
			),
			array(
				'test_condition_name' => '存在しない日付（2月30日） => null',
				'value'               => '2026-02-30T12:00',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '日付だけ => null',
				'value'               => '2026-11-06',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '空文字 => null（指定なしとは区別する）',
				'value'               => '',
				'expected'            => null,
			),
			array(
				'test_condition_name' => '文字列でない値 => null',
				'value'               => 12345,
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Import::parse_start( $case['value'], $tokyo, $now ), $case['test_condition_name'] );
		}
	}

	/**
	 * ETSS_Import::table_rows() のテスト。
	 *
	 * @return void
	 */
	public function test_table_rows() {
		$parsed = $this->fixture_parsed();
		$plan   = ETSS_Import::plan( $parsed, $this->template_single(), $this->exists_only( array( 538, 539, 540, 541, 542 ) ), 1000 );

		$dry_rows = ETSS_Import::table_rows( $plan );
		$this->assertCount( 5, $dry_rows, '項目ごとに1行' );
		$this->assertSame(
			array(
				'#'      => '1',
				'代替テキスト' => 'デモ 1 の告知文',
				'リンク先'   => '/campaign-a/',
				'画像'     => 'image=538',
				'状態'     => '表示',
				'理由'     => '',
			),
			$dry_rows[0],
			'--dry-run の行（ID の列は無い）'
		);
		$this->assertSame( '（なし）', $dry_rows[1]['リンク先'], 'リンクなしは（なし）' );
		$this->assertSame( '下書き', $dry_rows[1]['状態'], 'リンクなしは下書き' );
		$this->assertSame( 'リンク先がありません', $dry_rows[1]['理由'], '理由を出す' );

		$created_rows = ETSS_Import::table_rows(
			$plan,
			array(
				0 => 901,
				1 => 902,
			)
		);
		$this->assertSame( '901', $created_rows[0]['ID'], '作ったスライドの ID' );
		$this->assertSame( '902', $created_rows[1]['ID'], '作ったスライドの ID' );
		$this->assertSame( '', $created_rows[2]['ID'], '作れなかった項目の ID は空' );

		$no_image = ETSS_Import::plan(
			array(
				'items' => array(
					array(
						'images'               => array(),
						'attachment_id'        => 0,
						'url'                  => '/x/',
						'alt'                  => 'x',
						'has_background_image' => false,
					),
				),
			),
			$this->template_single(),
			$this->exists_only( array() ),
			1000
		);
		$this->assertSame( '（なし）', ETSS_Import::table_rows( $no_image )[0]['画像'], '画像が無ければ（なし）' );
	}
}
