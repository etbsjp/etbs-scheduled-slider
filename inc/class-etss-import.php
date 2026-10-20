<?php
/**
 * 移行コマンド（docs/spec.md §8・D11）の、取り出しと割り当て。
 *
 * 既存の VK Blocks のスライダー（`vk-blocks/slider`）の本文から、スライダー項目ごとの画像・リンク先・alt を取り出し、
 * ひな形の画像枠に割り当て、作るスライドの計画（表示か下書きか・理由）を立てる。
 * ここに置くのは WordPress に依存しない純粋な処理だけで、本文・ひな形・時刻・「添付ファイルがあるか」の判定は引数で受け取る。
 * 投稿の読み書きとコマンドの出力は `ETSS_CLI`（WP-CLI の入口）が行う。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VK のスライダーからスライドを作る計画を立てるクラス。
 */
class ETSS_Import {

	/**
	 * VK のスライダーのブロック名。
	 */
	const VK_SLIDER = 'vk-blocks/slider';

	/**
	 * VK のスライダー項目のブロック名。
	 */
	const VK_ITEM = 'vk-blocks/slider-item';

	/**
	 * 画像ブロックの名前（`wp-image-NNN` の class が無いときに、ブロックの属性から添付ファイル ID を探す）。
	 */
	const IMAGE_BLOCK = 'core/image';

	/**
	 * 本文のブロックから、VK のスライダーを文書の出てくる順にすべて探す。
	 *
	 * グループや段組みの中に置かれたものも探す。見つけたスライダーの中は、さらには探さない。
	 *
	 * @param array $blocks `parse_blocks()` の結果。
	 * @return array VK のスライダーのブロックの配列（添字は 0 から）。
	 */
	public static function find_sliders( array $blocks ) {
		$found = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['blockName'] ) && self::VK_SLIDER === $block['blockName'] ) {
				$found[] = $block;
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::find_sliders( $block['innerBlocks'] ) );
			}
		}

		return $found;
	}

	/**
	 * ブロックの HTML を、中のブロックも含めて組み立てる（`parse_blocks()` の結果は、中のブロックの HTML を持たないため）。
	 *
	 * @param array $block `parse_blocks()` が返した1つのブロック。
	 * @return string HTML。
	 */
	public static function block_html( array $block ) {
		if ( ! isset( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
			return isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
		}

		$html        = '';
		$inner_index = 0;
		foreach ( $block['innerContent'] as $chunk ) {
			if ( is_string( $chunk ) ) {
				$html .= $chunk;
				continue;
			}

			// null は、中のブロックを置く位置。
			if ( isset( $block['innerBlocks'][ $inner_index ] ) && is_array( $block['innerBlocks'][ $inner_index ] ) ) {
				$html .= self::block_html( $block['innerBlocks'][ $inner_index ] );
			}
			++$inner_index;
		}

		return $html;
	}

	/**
	 * VK のスライダーの設定（外枠の `data-vkb-slider`）を取り出す。
	 *
	 * `data-vkb-slider` が読めないときは、ブロックの属性にある設定項目で代える（既定値のままの項目は属性に書かれない）。
	 *
	 * @param array $slider VK のスライダーのブロック。
	 * @return array 設定名 => 値。
	 */
	public static function parse_settings( array $slider ) {
		$html = isset( $slider['innerHTML'] ) ? (string) $slider['innerHTML'] : '';
		if ( preg_match( '/\sdata-vkb-slider\s*=\s*(["\'])(.*?)\1/s', $html, $matches ) ) {
			$decoded = json_decode( html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return isset( $slider['attrs'] ) && is_array( $slider['attrs'] ) ? $slider['attrs'] : array();
	}

	/**
	 * スライダー項目1つから、画像・最初のリンク先・alt を取り出す。
	 *
	 * 画像は `<img>` の class `wp-image-NNN` から添付ファイル ID を取る。class が無いときは、同じ順の画像ブロックの `id` 属性で代える。
	 * リンク先は項目の中の最初の `<a href>`、無ければ項目の `linkUrl` 属性。alt は最初の画像のもの（空なら、次に空でない画像のもの）。
	 *
	 * @param array $item VK のスライダー項目のブロック。
	 * @return array `images`（画像の配列。`id`・`alt`・`src`。出てくる順）・`attachment_id`（最初の画像の添付ファイル ID。無ければ 0）・
	 *               `url`・`alt`・`has_background_image`（項目の背景画像が指定されているか。背景画像は移せない）。
	 */
	public static function parse_item( array $item ) {
		$html      = self::block_html( $item );
		$block_ids = self::image_block_ids( isset( $item['innerBlocks'] ) && is_array( $item['innerBlocks'] ) ? $item['innerBlocks'] : array() );

		// 画像。
		$images = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $position => $tag ) {
				$id = 0;
				if ( preg_match( '/\sclass\s*=\s*(["\'])(.*?)\1/is', $tag, $class_match ) && preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class_match[2], $id_match ) ) {
					$id = (int) $id_match[1];
				} elseif ( isset( $block_ids[ $position ] ) ) {
					$id = (int) $block_ids[ $position ];
				}

				$images[] = array(
					'id'  => $id,
					'alt' => self::attribute( $tag, 'alt' ),
					'src' => self::attribute( $tag, 'src' ),
				);
			}
		}

		// alt。最初の画像のものを使い、空なら次に空でないものを探す。
		$alt = '';
		foreach ( $images as $image ) {
			if ( '' !== trim( $image['alt'] ) ) {
				$alt = trim( $image['alt'] );
				break;
			}
		}

		// 最初のリンク先。
		$url = '';
		if ( preg_match( '/<a\b[^>]*?\shref\s*=\s*(["\'])(.*?)\1/is', $html, $link_match ) ) {
			$url = trim( html_entity_decode( $link_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		if ( '' === $url && isset( $item['attrs']['linkUrl'] ) && is_string( $item['attrs']['linkUrl'] ) ) {
			$url = trim( $item['attrs']['linkUrl'] );
		}

		return array(
			'images'               => $images,
			'attachment_id'        => $images ? (int) $images[0]['id'] : 0,
			'url'                  => $url,
			'alt'                  => $alt,
			'has_background_image' => ! empty( $item['attrs']['bgImage'] ) || ! empty( $item['attrs']['bgImageTablet'] ) || ! empty( $item['attrs']['bgImageMobile'] ),
		);
	}

	/**
	 * VK のスライダーから、設定とスライダー項目ごとの取り出し結果を得る。
	 *
	 * @param array $slider VK のスライダーのブロック。
	 * @return array `settings`（parse_settings() の結果に、矢印の位置 `navigationPosition` を足したもの）・`items`（parse_item() の結果の配列。元の並び）。
	 */
	public static function parse_slider( array $slider ) {
		$items = array();
		if ( ! empty( $slider['innerBlocks'] ) && is_array( $slider['innerBlocks'] ) ) {
			foreach ( $slider['innerBlocks'] as $inner ) {
				if ( is_array( $inner ) && isset( $inner['blockName'] ) && self::VK_ITEM === $inner['blockName'] ) {
					$items[] = self::parse_item( $inner );
				}
			}
		}

		return array(
			'settings' => array_merge( self::parse_settings( $slider ), array( 'navigationPosition' => self::parse_navigation_position( $slider ) ) ),
			'items'    => $items,
		);
	}

	/**
	 * VK のスライダーの矢印の位置を取り出す。
	 *
	 * `data-vkb-slider` には入らないので、ブロックの属性から読む（既定の `mobile-bottom` のときは属性に書かれない）。
	 * 属性が無い・選択肢にないときは、HTML の矢印の class で代え、それも読めなければ VK の既定にする。
	 *
	 * @param array $slider VK のスライダーのブロック。
	 * @return string 矢印の位置（`hide`・`center`・`mobile-bottom`・`always-bottom` のどれか）。
	 */
	public static function parse_navigation_position( array $slider ) {
		$choices  = ETSS_Options::choices();
		$choices  = $choices['navigationPosition'];
		$fallback = 'mobile-bottom';

		// ブロックの属性。
		if ( isset( $slider['attrs']['navigationPosition'] ) && is_string( $slider['attrs']['navigationPosition'] ) && array_key_exists( $slider['attrs']['navigationPosition'], $choices ) ) {
			return $slider['attrs']['navigationPosition'];
		}

		// HTML の矢印の class。
		$html = isset( $slider['innerHTML'] ) ? (string) $slider['innerHTML'] : '';
		if ( preg_match( '/swiper-button-next\s+swiper-button-([a-z-]+)/', $html, $matches ) && array_key_exists( $matches[1], $choices ) ) {
			return $matches[1];
		}

		return $fallback;
	}

	/**
	 * 取り出した項目を、ひな形の画像枠（枠 ID => 添付ファイル ID）に割り当てる。
	 *
	 * ひな形が `import_item` を持つときはそれに任せる。無いときは、1枚目の画像を最初の枠に入れる。
	 * `import_item` が返した枠のうち、ひな形に無い枠 ID・正の整数でない値は捨てる。
	 *
	 * @param array $parsed   parse_item() の結果。
	 * @param array $template ひな形の定義（`slots`・`import_item`）。
	 * @return array 枠 ID => 添付ファイル ID（入れられなかった枠は含めない）。
	 */
	public static function assign_images( array $parsed, array $template ) {
		$slot_ids = array_keys( $template['slots'] );
		$assigned = array();

		if ( isset( $template['import_item'] ) && is_callable( $template['import_item'] ) ) {
			$result = call_user_func( $template['import_item'], $parsed );
			if ( is_array( $result ) ) {
				foreach ( $result as $slot_id => $attachment_id ) {
					if ( in_array( $slot_id, $slot_ids, true ) && is_numeric( $attachment_id ) && (int) $attachment_id > 0 ) {
						$assigned[ $slot_id ] = (int) $attachment_id;
					}
				}
			}
			return $assigned;
		}

		// import_item が無いときは、1枚目の画像を最初の枠に入れる。
		if ( $parsed['attachment_id'] > 0 && $slot_ids ) {
			$assigned[ $slot_ids[0] ] = (int) $parsed['attachment_id'];
		}

		return $assigned;
	}

	/**
	 * 項目1つぶんの、スライドの作り方を決める。理由が1つでもあれば下書きにする。
	 *
	 * 下書きにする理由: 画像が見つからない・存在しない添付ファイル・必須の画像枠が空・同じ画像が複数の枠にある・
	 * alt が空・リンク先が無い・リンク先の形式が正しくない。
	 *
	 * @param array    $parsed            parse_item() の結果。
	 * @param array    $template          ひな形の定義。
	 * @param callable $attachment_exists `callable( int $attachment_id ): bool`。画像として使える添付ファイルか。
	 * @return array `title`（alt）・`url`（使えるリンク先。使えないときは空）・`raw_url`・`images`（実在する添付ファイルだけの 枠 ID => ID）・`status`（`publish` か `draft`）・`reasons`（日本語の理由の配列）。
	 */
	public static function plan_item( array $parsed, array $template, $attachment_exists ) {
		$reasons  = array();
		$assigned = self::assign_images( $parsed, $template );

		// 画像そのものが見つからない。
		if ( ! $parsed['images'] ) {
			$reasons[] = $parsed['has_background_image'] ? '画像が見つかりません（背景画像は移せません）' : '画像が見つかりません';
		}

		// 画像はあるのに、添付ファイル ID が1つも読めない（`wp-image-NNN` が無い）。
		$has_attachment_id = false;
		foreach ( $parsed['images'] as $image ) {
			if ( $image['id'] > 0 ) {
				$has_attachment_id = true;
				break;
			}
		}
		if ( $parsed['images'] && ! $has_attachment_id ) {
			$reasons[] = '画像の添付ファイル ID が読み取れません（wp-image-NNN の class がありません）';
		}

		// 必須の画像枠が空。上の理由で説明がつくときは重ねて出さない。
		if ( $has_attachment_id ) {
			foreach ( $template['slots'] as $slot_id => $slot ) {
				if ( $slot['required'] && ! isset( $assigned[ $slot_id ] ) ) {
					$reasons[] = '必須の画像枠「' . $slot['label'] . '」に入れる画像がありません';
				}
			}
		}

		// 添付ファイルが無い（画像でない）もの。
		$images = array();
		foreach ( $assigned as $slot_id => $attachment_id ) {
			if ( call_user_func( $attachment_exists, $attachment_id ) ) {
				$images[ $slot_id ] = $attachment_id;
				continue;
			}
			$reasons[] = '添付ファイル #' . $attachment_id . ' が画像として見つかりません（枠「' . $template['slots'][ $slot_id ]['label'] . '」）';
		}

		// 同じ画像が複数の枠にある。
		if ( count( $images ) !== count( array_unique( $images ) ) ) {
			$reasons[] = '同じ画像が複数の枠に入っています';
		}

		// alt。
		if ( '' === $parsed['alt'] ) {
			$reasons[] = 'alt（代替テキスト）が空です';
		}

		// リンク先。
		$url = '';
		if ( '' === $parsed['url'] ) {
			$reasons[] = 'リンク先がありません';
		} elseif ( ETSS_Link::is_valid( $parsed['url'] ) ) {
			$url = $parsed['url'];
		} else {
			$reasons[] = 'リンク先の形式が正しくありません（' . $parsed['url'] . '）';
		}

		return array(
			'title'   => $parsed['alt'],
			'url'     => $url,
			'raw_url' => $parsed['url'],
			'images'  => $images,
			'status'  => $reasons ? 'draft' : 'publish',
			'reasons' => $reasons,
		);
	}

	/**
	 * 取り出した VK のスライダー全体から、作るスライドの計画を立てる。
	 *
	 * @param array    $parsed            parse_slider() の結果。
	 * @param array    $template          ひな形の定義。
	 * @param callable $attachment_exists `callable( int $attachment_id ): bool`。
	 * @param int      $start             表示開始（UTC の Unix 秒）。
	 * @return array plan_item() の結果に、`index`（0 始まり）・`menu_order`（元の並び）・`start`・`end`（0 ＝ 終了なし）を足したものの配列。
	 */
	public static function plan( array $parsed, array $template, $attachment_exists, $start ) {
		$plan = array();

		foreach ( $parsed['items'] as $index => $item ) {
			$row               = self::plan_item( $item, $template, $attachment_exists );
			$row['index']      = $index;
			$row['menu_order'] = $index;
			$row['start']      = (int) $start;
			$row['end']        = 0;
			$plan[]            = $row;
		}

		return $plan;
	}

	/**
	 * VK の設定を、スライダーの動きの設定に写した結果を作る。
	 *
	 * 写すのは、動きの設定の項目（`ETSS_Meta::default_options()` のキー）のうち、VK の設定にあるものだけ。
	 * 写した値は、保存時と同じ整形（`ETSS_Options::sanitize()`）を通す。VK の値が選択肢にない・数でないときは、いまの値のままにする。
	 * 本プラグインに無い機能（一時停止ボタン・ズーム）は写せないので、使われていれば `unsupported` で知らせる。
	 *
	 * @param array $vk_settings VK の設定（parse_settings() の結果）。
	 * @param array $current     いまの動きの設定（足りない項目は、本体の既定値で補う）。
	 * @return array `options`（写したあとの動きの設定）・`changes`（変わる項目。設定名 => `from`・`to`）・`unsupported`（写せなかった設定の名前の配列）。
	 */
	public static function map_options( array $vk_settings, array $current ) {
		$base   = array_merge( ETSS_Meta::default_options(), $current );
		$mapped = array();
		foreach ( array_keys( $base ) as $key ) {
			if ( array_key_exists( $key, $vk_settings ) ) {
				$mapped[ $key ] = $vk_settings[ $key ];
			}
		}

		$options = ETSS_Options::sanitize( array_merge( $base, $mapped ), $base );

		$changes = array();
		foreach ( $options as $key => $value ) {
			$from = $base[ $key ];
			if ( ! self::is_same_value( $from, $value ) ) {
				$changes[ $key ] = array(
					'from' => $from,
					'to'   => $value,
				);
			}
		}

		$unsupported = array();
		foreach ( array( 'pauseButton', 'zoomAnimation' ) as $key ) {
			if ( ! empty( $vk_settings[ $key ] ) ) {
				$unsupported[] = $key;
			}
		}

		return array(
			'options'     => $options,
			'changes'     => $changes,
			'unsupported' => $unsupported,
		);
	}

	/**
	 * コマンドの投稿 ID と `--index` を確かめる。
	 *
	 * @param mixed $post_id 投稿 ID（コマンドの引数）。
	 * @param mixed $index   `--index` の値（何番目の VK スライダーか）。
	 * @return string エラー文（何が悪いか＋どう指定するか）。問題が無ければ空文字。
	 */
	public static function validate_args( $post_id, $index ) {
		if ( ! is_numeric( $post_id ) || (int) $post_id <= 0 || (string) (int) $post_id !== (string) $post_id ) {
			return '投稿 ID が正しくありません。1 以上の数（VK のスライダーを置いている投稿の ID）で指定してください。';
		}
		if ( ! is_numeric( $index ) || (int) $index < 0 || (string) (int) $index !== (string) $index ) {
			return '--index が正しくありません。0 以上の数（0 が1つめの VK スライダー）で指定してください。';
		}

		return '';
	}

	/**
	 * `--start` の値を UTC の Unix 秒にする。
	 *
	 * 表示開始の入力と同じ形（`2026-11-06T12:00`。秒つきも可）を、サイトのタイムゾーンで解釈する。
	 * 日付と時刻の間は空白でもよい（`2026-11-06 12:00`）。指定が無ければ今。
	 *
	 * @param mixed        $value    `--start` の値。指定が無ければ null。
	 * @param DateTimeZone $timezone サイトのタイムゾーン。
	 * @param int          $now      現在時刻（UTC の Unix 秒）。
	 * @return int|null UTC の Unix 秒。読めなければ null。
	 */
	public static function parse_start( $value, DateTimeZone $timezone, $now ) {
		if ( null === $value ) {
			return (int) $now;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}(?::\d{2})?)$/', $value, $matches ) ) {
			$value = $matches[1] . 'T' . $matches[2];
		}

		return ETSS_Schedule::parse_datetime_local( $value, $timezone );
	}

	/**
	 * 計画を、表に出す行にする（コマンドの出力用）。
	 *
	 * @param array $plan     plan() の結果。
	 * @param array $post_ids 作ったスライドの投稿 ID（計画の `index` => ID）。作っていない（`--dry-run`）ときは空。
	 * @return array 行の配列。キーは `#`・`代替テキスト`・`リンク先`・`画像`・`状態`・`理由`（作ったときは `ID` も）。
	 */
	public static function table_rows( array $plan, array $post_ids = array() ) {
		$rows = array();

		foreach ( $plan as $item ) {
			$images = array();
			foreach ( $item['images'] as $slot_id => $attachment_id ) {
				$images[] = $slot_id . '=' . $attachment_id;
			}

			$row = array(
				'#'      => (string) ( $item['index'] + 1 ),
				'代替テキスト' => $item['title'],
				'リンク先'   => '' !== $item['raw_url'] ? $item['raw_url'] : '（なし）',
				'画像'     => $images ? implode( ', ', $images ) : '（なし）',
				'状態'     => 'publish' === $item['status'] ? '表示' : '下書き',
				'理由'     => $item['reasons'] ? implode( ' / ', $item['reasons'] ) : '',
			);
			if ( $post_ids ) {
				$row['ID'] = isset( $post_ids[ $item['index'] ] ) ? (string) $post_ids[ $item['index'] ] : '';
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * 計画を、表示にするものと下書きにするものに数える（コマンドの出力用）。
	 *
	 * @param array      $plan     plan() の結果。
	 * @param array|null $post_ids 作ったスライドの投稿 ID（計画の `index` => ID）。渡すと、作れた項目だけを数える。null なら計画の全項目。
	 * @return array `publish`（表示の枚数）と `draft`（下書きの枚数）。
	 */
	public static function count_by_status( array $plan, $post_ids = null ) {
		$counts = array(
			'publish' => 0,
			'draft'   => 0,
		);

		foreach ( $plan as $item ) {
			if ( null !== $post_ids && ! isset( $post_ids[ $item['index'] ] ) ) {
				continue;
			}
			if ( 'publish' === $item['status'] ) {
				++$counts['publish'];
			} else {
				++$counts['draft'];
			}
		}

		return $counts;
	}

	/**
	 * 動きの設定の値が同じかを比べる（真偽はそのまま、数値と文字列は文字列にして比べる。1 と 1.0 は同じ）。
	 *
	 * @param mixed $a 値。
	 * @param mixed $b 値。
	 * @return bool 同じなら true。
	 */
	private static function is_same_value( $a, $b ) {
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return $a === $b;
		}

		return (string) $a === (string) $b;
	}

	/**
	 * 属性の値を取り出す（HTML の実体参照は文字に戻す）。
	 *
	 * @param string $tag       タグ1つぶんの HTML。
	 * @param string $attribute 属性名。
	 * @return string 値。無ければ空文字。
	 */
	private static function attribute( $tag, $attribute ) {
		if ( ! preg_match( '/\s' . preg_quote( $attribute, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $matches ) ) {
			return '';
		}

		return html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * 中のブロックから、画像ブロックの `id` 属性を出てくる順に集める。
	 *
	 * @param array $blocks ブロックの配列。
	 * @return int[] 添付ファイル ID の配列（`id` が無い画像ブロックは 0）。
	 */
	private static function image_block_ids( array $blocks ) {
		$ids = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( isset( $block['blockName'] ) && self::IMAGE_BLOCK === $block['blockName'] ) {
				$ids[] = isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, self::image_block_ids( $block['innerBlocks'] ) );
			}
		}

		return $ids;
	}
}
