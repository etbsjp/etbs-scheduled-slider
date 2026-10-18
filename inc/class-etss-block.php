<?php
/**
 * ブロック `etss/slider`（docs/spec.md §5.1）と、フロントへの描画（§5.2〜§5.4）・キャッシュの指定（§6）。
 *
 * このクラスは WordPress に触れる部分（ブロックの登録・投稿の読み出し・`time()`・フック）だけを持つ。
 * HTML の組み立ては ETSS_Render、少ないときの扱いは ETSS_Loop、保存期間の計算は ETSS_Schedule、キャッシュへの指定は ETSS_Cache に任せる。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ブロックと描画をまとめたクラス。
 */
class ETSS_Block {

	/**
	 * ブロック名。
	 */
	const NAME = 'etss/slider';

	/**
	 * 編集画面のスクリプトのハンドル名（block.json の editorScript が指す。block.json に版を持たせないため PHP で登録する）。
	 */
	const EDITOR_HANDLE = 'etss-slider-editor';

	/**
	 * VK Blocks のスライダーが使えないときの案内（編集画面のブロックの警告に使う）。原因を2つ並べる。
	 */
	const VK_UNAVAILABLE_MESSAGE = 'VK Blocks が有効でないか、VK Blocks のスライダーブロックが無効になっています。VK Blocks（無料版でよい）をインストール・有効化し、スライダーブロックを使える状態にしてください。';

	/**
	 * このリクエストで、スライダーごとに何回描画したか（スライダーの投稿 ID => 回数）。
	 *
	 * @var array
	 */
	private static $instances = array();

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'add_editor_data' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_vk_notice' ) );
		add_filter( 'wp_img_tag_add_loading_attr', array( __CLASS__, 'keep_no_lazy' ), 10, 2 );
	}

	/**
	 * 編集画面のスクリプトとブロックを登録する。
	 *
	 * @return void
	 */
	public static function register() {
		$dir   = dirname( ETSS_PLUGIN_FILE ) . '/blocks/slider';
		$asset = require $dir . '/index.asset.php';

		wp_register_script(
			self::EDITOR_HANDLE,
			plugins_url( 'blocks/slider/index.js', ETSS_PLUGIN_FILE ),
			$asset['dependencies'],
			ETSS_Admin::asset_version(),
			true
		);

		register_block_type(
			$dir,
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
	}

	/**
	 * VK Blocks のスライダーが使えるかを返す。
	 *
	 * ブロックが登録されているかで判定する。ハンドル名では判定しない（管理画面ではハンドルが登録されないため、誤った警告が出る）。
	 *
	 * @return bool 使えるなら true。
	 */
	public static function is_vk_available() {
		return WP_Block_Type_Registry::get_instance()->is_registered( ETSS_Render::VK_BLOCK );
	}

	/**
	 * ブロックの描画（フックの入口）。現在時刻はここで読む。
	 *
	 * @param array $attributes ブロックの属性（`sliderId`）。
	 * @return string HTML。出すものが無ければ空文字。
	 */
	public static function render( $attributes ) {
		$slider_id = ( is_array( $attributes ) && isset( $attributes['sliderId'] ) ) ? (int) $attributes['sliderId'] : 0;
		if ( $slider_id <= 0 ) {
			return '';
		}

		// VK Blocks が無いときは何も出さず、壊れた状態をキャッシュに保存させない。
		if ( ! self::is_vk_available() ) {
			ETSS_Cache::tag( $slider_id );
			ETSS_Cache::nocache( 'ETBS Scheduled Slider: VK Blocks のスライダーが使えない' );
			return '';
		}

		return self::render_slider( $slider_id, time() );
	}

	/**
	 * スライダー1つを描画する。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @param int $now       現在時刻（UTC の Unix 秒）。
	 * @return string HTML。出すものが無ければ空文字。
	 */
	public static function render_slider( $slider_id, $now ) {
		$slider_id = (int) $slider_id;

		// スライダーの変更・スライドの追加で捨てられるよう、何も出さないときも目印は付ける。
		ETSS_Cache::tag( $slider_id );

		$slider = get_post( $slider_id );
		if ( ! $slider || ETSS_Post_Types::SLIDER !== $slider->post_type || 'publish' !== $slider->post_status ) {
			return '';
		}

		$template = ETSS_Templates::for_slider( $slider_id );
		if ( null === $template || ! is_callable( $template['render_item'] ) ) {
			return '';
		}

		$slides = self::load_slides( $slider_id, $now );

		// 次の切替時刻までをページキャッシュの保存期間にする。
		$max_ttl = (int) apply_filters( 'etss_max_ttl', ETSS_Schedule::DEFAULT_MAX_TTL );
		ETSS_Cache::set_ttl( ETSS_Schedule::cache_ttl( ETSS_Schedule::next_switch_time( $slides, $now ), $now, $max_ttl ) );

		// 画像の読み出し（投稿・メタ）をまとめて済ませる。スライドの数だけ問い合わせないため。
		$attachment_ids = array();
		foreach ( $slides as $slide ) {
			$attachment_ids = array_merge( $attachment_ids, array_map( 'intval', array_values( $slide['images'] ) ) );
		}
		if ( $attachment_ids ) {
			_prime_post_caches( array_values( array_unique( array_filter( $attachment_ids ) ) ), false, true );
		}

		$selected = ETSS_Render::select_slides( $slides, $slider_id, $template['slots'], $now, 'wp_attachment_is_image' );
		if ( ! $selected ) {
			return '';
		}

		// 先にスライドの HTML を作り、空を返したものを除く。枚数に依る処理は、実際に出る枚数で決めるため。
		$slide_htmls = ETSS_Render::build_slide_htmls( $selected, $template['render_item'], $template['item_class'] );
		if ( ! $slide_htmls ) {
			return '';
		}

		// 少ないときの扱い（1枚ならループを止める・ループに足りなければ繰り返す）。HTML の配列に対して行う。
		$options     = self::load_options( $slider_id, $template );
		$options     = ETSS_Loop::adjust_options( $options, count( $slide_htmls ) );
		$slide_htmls = ETSS_Loop::repeat_for_loop( $slide_htmls, $options );

		// 同じスライダーを1ページに複数回置いたときは、VK の JS が別々に起動できるよう blockId を分ける。
		self::$instances[ $slider_id ] = ( isset( self::$instances[ $slider_id ] ) ? self::$instances[ $slider_id ] : 0 ) + 1;
		$block_id                      = ETSS_Render::block_id( $slider_id, self::$instances[ $slider_id ] );
		$extra_class                   = ETSS_Options::sanitize_extra_class( get_post_meta( $slider_id, ETSS_Meta::EXTRA_CLASS, true ) );
		$html                          = ETSS_Render::slider_html( $slider_id, $block_id, $options, $slide_htmls, $extra_class );

		// VK のブロックとして描画する。CSS と JS の読み込みは WordPress に任せる。
		return render_block( ETSS_Render::parsed_block( $html ) );
	}

	/**
	 * スライダーの動きの設定を読む。ひな形の既定値を重ね、保存されていない項目は既定値にする。
	 *
	 * @param int   $slider_id スライダーの投稿 ID。
	 * @param array $template  ひな形。
	 * @return array 動きの設定。
	 */
	private static function load_options( $slider_id, array $template ) {
		$defaults = ETSS_Templates::default_options( $template['id'] );
		$stored   = get_post_meta( $slider_id, ETSS_Meta::OPTIONS, true );

		return ETSS_Options::sanitize( array_merge( $defaults, is_array( $stored ) ? $stored : array() ), $defaults );
	}

	/**
	 * スライダーに属する公開状態のスライドを読み出す。終了済みは問い合わせの段階で外す。件数の上限は付けない。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @param int $now       現在時刻（UTC の Unix 秒）。
	 * @return array スライドの配列（`id`・`title`・`post_status`・`menu_order`・`slider_id`・`images`・`url`・`start`・`end`）。
	 */
	public static function load_slides( $slider_id, $now ) {
		$posts = get_posts(
			array(
				'post_type'      => ETSS_Post_Types::SLIDE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- スライダーと終了時刻で絞るのに必要。
					'relation' => 'AND',
					array(
						'key'   => ETSS_Meta::SLIDER_ID,
						'value' => (int) $slider_id,
						'type'  => 'NUMERIC',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => ETSS_Meta::END,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => ETSS_Meta::END,
							'value' => 0,
							'type'  => 'NUMERIC',
						),
						array(
							'key'     => ETSS_Meta::END,
							'value'   => (int) $now,
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);

		$slides = array();
		foreach ( $posts as $post ) {
			$saved    = ETSS_Slide_Admin::get_saved( $post->ID );
			$slides[] = array(
				'id'          => (int) $post->ID,
				'title'       => (string) $post->post_title,
				'post_status' => (string) $post->post_status,
				'menu_order'  => (int) $post->menu_order,
				'slider_id'   => $saved['slider_id'],
				'images'      => $saved['images'],
				'url'         => $saved['url'],
				'start'       => $saved['start'],
				'end'         => $saved['end'],
			);
		}

		return $slides;
	}

	/**
	 * 1枚目の画像（`data-no-lazy="1"`）に、WordPress が `loading="lazy"` を足さないようにする。
	 *
	 * 本体のひな形は 1枚目に `loading` を付けないが、そのままだと the_content などの後処理が lazy を足してしまい、LCP を遅らせる。
	 *
	 * @param string|bool $value   `loading` に付ける値。
	 * @param string      $image   `img` タグの HTML。
	 * @return string|bool 1枚目の画像なら false（付けない）、それ以外は渡された値。
	 */
	public static function keep_no_lazy( $value, $image ) {
		return ( is_string( $image ) && false !== strpos( $image, 'data-no-lazy="1"' ) ) ? false : $value;
	}

	/**
	 * 編集画面のブロックに、スライダーの一覧と状況を渡す。
	 *
	 * @return void
	 */
	public static function add_editor_data() {
		$data = array(
			'canManage'   => current_user_can( ETSS_Post_Types::get_capability() ),
			'vkAvailable' => self::is_vk_available(),
			'vkMessage'   => self::VK_UNAVAILABLE_MESSAGE,
			// プラグインの画面は、有効化できる人にだけ案内する。
			'pluginsUrl'  => current_user_can( 'activate_plugins' ) ? admin_url( 'plugins.php' ) : '',
			'sliders'     => array(),
			'newUrl'      => admin_url( 'post-new.php?post_type=' . ETSS_Post_Types::SLIDER ),
		);

		if ( $data['canManage'] ) {
			$data['sliders'] = self::editor_sliders( time() );
		}

		wp_add_inline_script( self::EDITOR_HANDLE, 'window.etssSliderBlock = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * 編集画面で選べるスライダーと、その状況（表示中の枚数・次の切り替え・各画面へのリンク）を作る。
	 *
	 * @param int $now 現在時刻（UTC の Unix 秒）。
	 * @return array 各要素は `id`・`label`・`active`・`next`・`listUrl`・`editUrl`。
	 */
	private static function editor_sliders( $now ) {
		$by_slider = array();
		foreach ( ETSS_List_Admin::load_rows() as $row ) {
			$by_slider[ $row['slider_id'] ][] = $row;
		}

		$timezone = wp_timezone();
		$sliders  = array();
		foreach ( ETSS_Slide_Admin::slider_choices() as $slider_id => $label ) {
			$summary   = ETSS_List::summarize_slider( isset( $by_slider[ $slider_id ] ) ? $by_slider[ $slider_id ] : array(), $now );
			$status    = (string) get_post_status( $slider_id );
			$sliders[] = array(
				'id'        => (int) $slider_id,
				// 公開されていないスライダーは、選ぶ前に分かるよう状態を添える。
				'label'     => $label . ETSS_Display::slider_status_suffix( $status ),
				'published' => 'publish' === $status,
				'active'    => (int) $summary['active'],
				'next'      => ETSS_List::describe_next( $summary['next'], $now, $timezone ),
				'listUrl'   => add_query_arg(
					array(
						'post_type'                 => ETSS_Post_Types::SLIDE,
						ETSS_List_Admin::SLIDER_ARG => (int) $slider_id,
					),
					admin_url( 'edit.php' )
				),
				'editUrl'   => (string) get_edit_post_link( $slider_id, 'raw' ),
			);
		}

		return $sliders;
	}

	/**
	 * VK Blocks が使えないとき、管理画面に知らせる。プラグインの画面と、このプラグインの画面にだけ出す。
	 *
	 * @return void
	 */
	public static function render_vk_notice() {
		if ( self::is_vk_available() || ! current_user_can( ETSS_Post_Types::get_capability() ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! ( 'plugins' === $screen->id || in_array( $screen->post_type, array( ETSS_Post_Types::SLIDE, ETSS_Post_Types::SLIDER ), true ) ) ) {
			return;
		}

		$message = 'VK Blocks のスライダーが使えないため、サイトにスライダーが出ていません。';
		if ( ! current_user_can( 'activate_plugins' ) ) {
			$message .= '管理者に VK Blocks の有効化を依頼してください。';
			$link     = '';
		} else {
			$link = 'plugins' !== $screen->id ? ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">プラグインの画面を開く</a>' : '';
		}

		echo '<div class="notice notice-error"><p><strong>ETBS Scheduled Slider:</strong> ' . esc_html( $message ) . wp_kses_post( $link ) . '</p></div>';
	}
}
