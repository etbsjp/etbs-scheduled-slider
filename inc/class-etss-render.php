<?php
/**
 * スライダーの HTML を組み立てる処理（docs/spec.md §5.2）。
 *
 * VK Blocks のスライダー（`vk-blocks/slider`）が保存する HTML と同じ形を作る。
 * どのスライドを出すかの判定と、HTML の組み立てだけを持ち、現在時刻・スライドの値・画像の有無は引数で受け取る
 * （time() や投稿の読み出しはここでは行わない。それは ETSS_Block が持つ）。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * スライダーの HTML の組み立てをまとめたクラス。
 */
class ETSS_Render {

	/**
	 * 描画を任せる VK Blocks のブロック名。
	 */
	const VK_BLOCK = 'vk-blocks/slider';

	/**
	 * VK の JS が Swiper を起動するときの、外枠の class と `data-vkb-slider` の `blockId` に使う接頭辞。
	 */
	const BLOCK_ID_PREFIX = 'etss-';

	/**
	 * 日時プレビュー（docs/spec.md §7）で、下書きも含めて出すときの公開状態。ゴミ箱・自動下書き・リビジョンは含めない。
	 */
	const PREVIEW_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * VK のスライダーの `blockId` を作る。
	 *
	 * VK の JS は `.vk_slider_<blockId>` で Swiper を起動するので、外枠の class と `data-vkb-slider` の `blockId` は必ずこの値で揃える。
	 * 同じスライダーを1ページに2回置いたときは、2つ目以降に連番を付ける（同じ class だと1つ目しか動かないため）。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @param int $instance  そのページで何回目の描画か（1 から）。
	 * @return string `etss-5`（2回目以降は `etss-5-2`）。
	 */
	public static function block_id( $slider_id, $instance = 1 ) {
		$id = self::BLOCK_ID_PREFIX . (int) $slider_id;

		return (int) $instance > 1 ? $id . '-' . (int) $instance : $id;
	}

	/**
	 * スライドを、いま出してよいかを判定する。
	 *
	 * 保存時の検査（ETSS_Validator::validate_slide()）と同じ必須項目を確かめる。
	 * 検査を通らずに公開になった経路があっても、壊れたスライドを出さないための多重の守り。
	 * 確かめる項目: 公開状態・属するスライダー・代替テキスト・リンク先・必須の画像枠（画像が実在すること）・同じ画像の重複・表示期間。
	 *
	 * @param array    $slide    スライド。`post_status`・`slider_id`・`title`・`url`・`images`（枠 ID => 添付ファイル ID）・`start`・`end` を持つ。
	 * @param int      $slider_id 描画するスライダーの投稿 ID。
	 * @param array    $slots    ひな形の画像枠（枠 ID => 定義）。
	 * @param int      $now      現在時刻（UTC の Unix 秒）。
	 * @param callable $is_image `callable( int $attachment_id ): bool`。画像の添付ファイルとして実在するか。
	 * @param bool     $include_unpublished true なら、公開状態が下書きなどのスライドも対象にする（日時プレビュー用。表示期間などの他の条件は同じ）。
	 * @return bool 出してよければ true。
	 */
	public static function is_displayable( array $slide, $slider_id, array $slots, $now, $is_image, $include_unpublished = false ) {
		$post_status = isset( $slide['post_status'] ) ? $slide['post_status'] : '';
		if ( $include_unpublished ? ! in_array( $post_status, self::PREVIEW_STATUSES, true ) : 'publish' !== $post_status ) {
			return false;
		}

		if ( (int) $slide['slider_id'] !== (int) $slider_id || '' === trim( (string) $slide['title'] ) ) {
			return false;
		}

		if ( ! ETSS_Link::is_valid( isset( $slide['url'] ) ? $slide['url'] : '' ) ) {
			return false;
		}

		if ( ! ETSS_Schedule::is_active( $slide['start'], $slide['end'], $now ) ) {
			return false;
		}

		return self::has_valid_images( isset( $slide['images'] ) && is_array( $slide['images'] ) ? $slide['images'] : array(), $slots, $is_image );
	}

	/**
	 * 画像枠が、保存時の検査と同じ条件を満たすかを判定する。
	 *
	 * 必須の枠に画像があること、画像が実在すること、同じ画像を別の枠に入れていないこと。
	 *
	 * @param array    $images   枠 ID => 添付ファイル ID。
	 * @param array    $slots    ひな形の画像枠。
	 * @param callable $is_image 画像の添付ファイルとして実在するかを返す関数。
	 * @return bool 満たせば true。
	 */
	private static function has_valid_images( array $images, array $slots, $is_image ) {
		$used = array();

		foreach ( $slots as $slot_id => $slot ) {
			$attachment_id = isset( $images[ $slot_id ] ) ? (int) $images[ $slot_id ] : 0;

			if ( 0 === $attachment_id ) {
				if ( ! empty( $slot['required'] ) ) {
					return false;
				}
				continue;
			}

			// 入っているのに画像でないものは、必須でなくても出さない（壊れた画像を出さない）。
			if ( ! call_user_func( $is_image, $attachment_id ) || isset( $used[ $attachment_id ] ) ) {
				return false;
			}
			$used[ $attachment_id ] = true;
		}

		return true;
	}

	/**
	 * 出してよいスライドだけを選び、表示順に並べる。
	 *
	 * @param array    $slides    スライドの配列（is_displayable() の形に `id`・`menu_order` を加えたもの）。
	 * @param int      $slider_id 描画するスライダーの投稿 ID。
	 * @param array    $slots     ひな形の画像枠。
	 * @param int      $now       現在時刻（UTC の Unix 秒）。
	 * @param callable $is_image  画像として実在するかを返す関数。
	 * @param bool     $include_unpublished true なら、下書きなども対象にする（日時プレビュー用）。
	 * @return array 並べたスライドの配列。
	 */
	public static function select_slides( array $slides, $slider_id, array $slots, $now, $is_image, $include_unpublished = false ) {
		$displayable = array();

		foreach ( $slides as $slide ) {
			if ( self::is_displayable( $slide, $slider_id, $slots, $now, $is_image, $include_unpublished ) ) {
				$displayable[] = $slide;
			}
		}

		return ETSS_List::sort_rows( $displayable );
	}

	/**
	 * VK の JS に渡す設定（外枠の `data-vkb-slider`）を作る。
	 *
	 * 項目と並びは、VK のスライダーのブロックが保存する値に合わせる。
	 *
	 * @param string $block_id VK の blockId。
	 * @param array  $options  動きの設定（ETSS_Meta::default_options() と同じキー）。
	 * @return array 設定名 => 値。
	 */
	public static function vk_settings( $block_id, array $options ) {
		return array(
			'autoPlay'            => (bool) $options['autoPlay'],
			'autoPlayStop'        => (bool) $options['autoPlayStop'],
			'autoPlayDelay'       => (int) $options['autoPlayDelay'],
			'pauseButton'         => false,
			'pagination'          => (string) $options['pagination'],
			'width'               => (string) $options['width'],
			'loop'                => (bool) $options['loop'],
			'effect'              => (string) $options['effect'],
			'speed'               => (int) $options['speed'],
			'direction'           => 'rtl',
			'slidesPerViewMobile' => $options['slidesPerViewMobile'] + 0,
			'slidesPerViewTablet' => $options['slidesPerViewTablet'] + 0,
			'slidesPerViewPC'     => $options['slidesPerViewPC'] + 0,
			'slidesPerGroup'      => (string) $options['slidesPerGroup'],
			'centeredSlides'      => (bool) $options['centeredSlides'],
			'zoomAnimation'       => false,
			'zoomInitialScale'    => 1,
			'zoomFinalScale'      => 1.25,
			'blockId'             => (string) $block_id,
		);
	}

	/**
	 * スライド1枚分の HTML を作る。VK のスライダー項目（`vk-blocks/slider-item`）と同じ外側を、本体が付ける。
	 *
	 * @param string $content    ひな形の `render_item` が返した、スライドの中身の HTML（エスケープ済み）。
	 * @param string $item_class ひな形の `item_class`（空でもよい）。
	 * @return string スライド1枚分の HTML。
	 */
	public static function slide_html( $content, $item_class ) {
		$class = 'wp-block-vk-blocks-slider-item vk_slider_item swiper-slide' . ( '' !== (string) $item_class ? ' ' . $item_class : '' );

		return '<div class="' . esc_attr( $class ) . '">'
			. '<div class="vk_slider_item-background-area"></div>'
			. '<div class="vk_slider_item_container">' . $content . '</div>'
			. '</div>';
	}

	/**
	 * 選ばれたスライドから、スライド1枚分の HTML の配列を作る。`render_item` が空（や文字列以外）を返したスライドは除く。
	 *
	 * 出る枚数はここで決まるので、ループ・自動送り・ドットの調整や繰り返しは、この結果の枚数で行うこと。
	 * `index`・`is_first` は、除いたあとの並びで数える（先頭に空が出ても、実際の先頭に `is_first` が付く）。
	 *
	 * @param array    $selected    select_slides() の結果。
	 * @param callable $render_item ひな形の `render_item`。
	 * @param string   $item_class  ひな形の `item_class`。
	 * @return string[] slide_html() の結果の配列（添字は 0 から）。
	 */
	public static function build_slide_htmls( array $selected, $render_item, $item_class ) {
		$slide_htmls = array();
		foreach ( $selected as $slide ) {
			$content = call_user_func(
				$render_item,
				$slide,
				array(
					'index'    => count( $slide_htmls ),
					'is_first' => array() === $slide_htmls,
				)
			);
			if ( is_string( $content ) && '' !== $content ) {
				$slide_htmls[] = self::slide_html( $content, $item_class );
			}
		}

		return $slide_htmls;
	}

	/**
	 * スライダー全体の HTML を作る。VK のスライダー（`vk-blocks/slider`）が保存する HTML と同じ形。
	 *
	 * 外枠は `wp-block-vk-blocks-slider swiper vk_slider vk_slider_<blockId> etss-slider etss-slider-<ID> <追加の class>`。
	 * 矢印・ドットは VK の既定（矢印は mobile-bottom）と同じ。ドットは `pagination` が hide のときは出さない。
	 *
	 * @param int    $slider_id   スライダーの投稿 ID。
	 * @param string $block_id    VK の blockId。
	 * @param array  $options     動きの設定。
	 * @param array  $slide_htmls slide_html() の結果の配列。
	 * @param string $extra_class 外枠に足す class（空でもよい）。
	 * @return string HTML。
	 */
	public static function slider_html( $slider_id, $block_id, array $options, array $slide_htmls, $extra_class ) {
		$classes = array( 'wp-block-vk-blocks-slider', 'swiper', 'vk_slider', 'vk_slider_' . $block_id );

		// 幅の指定は、VK と同じ alignfull / alignwide にする。
		if ( 'full' === $options['width'] ) {
			$classes[] = 'alignfull';
		} elseif ( 'wide' === $options['width'] ) {
			$classes[] = 'alignwide';
		}

		$classes[] = 'etss-slider';
		$classes[] = 'etss-slider-' . (int) $slider_id;
		if ( '' !== (string) $extra_class ) {
			$classes[] = $extra_class;
		}

		$settings = wp_json_encode( self::vk_settings( $block_id, $options ) );
		$html     = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-vkb-slider="' . esc_attr( $settings ) . '">';
		$html    .= '<div class="swiper-wrapper">' . implode( '', $slide_htmls ) . '</div>';
		$html    .= '<div class="swiper-button-next swiper-button-mobile-bottom"></div>';
		$html    .= '<div class="swiper-button-prev swiper-button-mobile-bottom"></div>';

		if ( 'hide' !== $options['pagination'] ) {
			$html .= '<div class="swiper-pagination swiper-pagination-' . esc_attr( $options['pagination'] ) . '"></div>';
		}

		return $html . '</div>';
	}

	/**
	 * `render_block()` に渡す、パース済みのブロックの配列を作る。
	 *
	 * @param string $html slider_html() の結果。
	 * @return array `blockName`・`attrs`・`innerBlocks`・`innerHTML`・`innerContent`。
	 */
	public static function parsed_block( $html ) {
		return array(
			'blockName'    => self::VK_BLOCK,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}
}
