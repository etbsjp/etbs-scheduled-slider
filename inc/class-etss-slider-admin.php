<?php
/**
 * スライダーの編集画面（§4.2）。ひな形の選択・動きの設定・「このスライダーを置く」の説明。
 *
 * 値の整え方は ETSS_Options（純粋な関数）に任せる。このクラスは、フォームの出力と保存（フックの入口）だけを持つ。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * スライダーの編集画面と保存をまとめたクラス。
 */
class ETSS_Slider_Admin {

	/**
	 * nonce のアクション名（投稿 ID を後ろに付ける）。
	 */
	const NONCE_ACTION = 'etss_save_slider_';

	/**
	 * nonce のフィールド名。
	 */
	const NONCE_NAME = 'etss_slider_nonce';

	/**
	 * 編集画面のフィールド名（`etss[...]`）。
	 */
	const FIELD = 'etss';

	/**
	 * スライダーを置くブロックの名前（#5 で登録する）。
	 */
	const BLOCK_NAME = 'etss/slider';

	/**
	 * スライダーを置くブロックの、管理画面での呼び方。
	 */
	const BLOCK_TITLE = 'ETBS スライダー';

	/**
	 * 「置いているページ」を探すときに読む投稿の上限。
	 */
	const PLACEMENT_LIMIT = 100;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes_' . ETSS_Post_Types::SLIDER, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . ETSS_Post_Types::SLIDER, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * 編集画面に入力欄のまとまり（メタボックス）を足す。
	 *
	 * @return void
	 */
	public static function add_meta_boxes() {
		add_meta_box( 'etss_slider_settings', 'ひな形と動きの設定', array( __CLASS__, 'render_settings_box' ), ETSS_Post_Types::SLIDER, 'normal', 'high' );
		add_meta_box( 'etss_slider_placement', 'このスライダーを置く', array( __CLASS__, 'render_placement_box' ), ETSS_Post_Types::SLIDER, 'normal', 'default' );
	}

	/**
	 * タイトル欄の案内文を、スライダー用にする。
	 *
	 * @param string  $text 既定の案内文。
	 * @param WP_Post $post 投稿。
	 * @return string 案内文。
	 */
	public static function title_placeholder( $text, $post ) {
		return ( $post && ETSS_Post_Types::SLIDER === $post->post_type ) ? 'スライダーの名前（管理用。例: トップ）を入力' : $text;
	}

	/**
	 * 編集画面の CSS を読み込む。
	 *
	 * @param string $hook_suffix 管理画面のフック名。
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && ETSS_Post_Types::SLIDER === $screen->post_type ) {
			wp_enqueue_style( 'etss-admin', plugins_url( 'assets/admin.css', ETSS_PLUGIN_FILE ), array(), ETSS_Admin::asset_version() );
		}
	}

	/**
	 * このスライダーに属するスライドの数を返す（ゴミ箱の中も含める。戻したときに画像枠が合わなくなるため）。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @return int スライドの数。
	 */
	public static function count_slides( $slider_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => ETSS_Post_Types::SLIDE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'meta_key'       => ETSS_Meta::SLIDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 管理画面で1スライダー分の件数だけを数える。
				'meta_value'     => (int) $slider_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * 保存済みの動きの設定を返す。無い項目は、ひな形の既定値で補う。
	 *
	 * @param int    $slider_id   スライダーの投稿 ID。
	 * @param string $template_id ひな形の ID。
	 * @return array 動きの設定。
	 */
	private static function get_options( $slider_id, $template_id ) {
		$saved = get_post_meta( $slider_id, ETSS_Meta::OPTIONS, true );

		return array_merge( ETSS_Templates::default_options( $template_id ), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * 保存済みのひな形の ID を返す。無ければ既定のひな形。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @return string ひな形の ID。
	 */
	private static function get_template_id( $slider_id ) {
		$id = get_post_meta( $slider_id, ETSS_Meta::TEMPLATE, true );

		return ( is_string( $id ) && '' !== $id ) ? $id : ETSS_Templates::DEFAULT_ID;
	}

	/**
	 * 「ひな形と動きの設定」の入力欄を出す。
	 *
	 * @param WP_Post $post 投稿。
	 * @return void
	 */
	public static function render_settings_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION . $post->ID, self::NONCE_NAME );

		$template_id = self::get_template_id( $post->ID );
		$options     = self::get_options( $post->ID, $template_id );
		$templates   = ETSS_Templates::all();
		$slide_count = self::count_slides( $post->ID );
		$locked      = $slide_count > 0;
		$name        = self::FIELD;

		// ひな形。スライドが1枚でもあれば変えられない（画像枠が合わなくなるため）。
		echo '<div class="etss-field">';
		echo '<label for="etss-template" class="etss-label">ひな形</label>';
		printf( '<select id="etss-template" name="%1$s[template]" aria-describedby="etss-template-desc"%2$s>', esc_attr( $name ), $locked ? ' disabled' : '' );
		foreach ( $templates as $id => $template ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $id ), selected( $template_id, $id, false ), esc_html( $template['label'] ) );
		}
		if ( ! isset( $templates[ $template_id ] ) ) {
			printf( '<option value="%1$s" selected>%1$s（登録されていません）</option>', esc_attr( $template_id ) );
		}
		echo '</select>';
		echo '<p class="description" id="etss-template-desc">';
		if ( $locked ) {
			printf(
				'このスライダーには、スライドが %1$d 枚あるため、ひな形は変えられません（スライドの画像枠が合わなくなるため）。別のひな形で使いたいときは、新しいスライダーを作ってください。<a href="%2$s">スライドの一覧を開く</a>',
				(int) $slide_count,
				esc_url( admin_url( 'edit.php?post_type=' . ETSS_Post_Types::SLIDE ) )
			);
		} else {
			echo 'スライド1枚の画像の枚数と見せ方が決まります。スライドを1枚作ると、あとから変えられなくなります。';
		}
		echo '</p>';
		echo '</div>';

		echo '<h3 class="etss-heading">動きの設定</h3>';

		// 切り替え。
		echo '<fieldset class="etss-fieldset"><legend>切り替え</legend>';
		self::render_checkbox( $name, 'autoPlay', '自動で切り替える', $options );
		self::render_checkbox( $name, 'autoPlayStop', '利用者が操作したら、自動の切り替えを止める', $options );
		self::render_number( $name, 'autoPlayDelay', '切り替えまでの時間（ミリ秒）', $options, '1000 で1秒です。' );
		self::render_select( $name, 'effect', '切り替えの動き', $options );
		self::render_number( $name, 'speed', '切り替えにかける時間（ミリ秒）', $options, '数字が大きいほど、ゆっくり切り替わります。' );
		self::render_checkbox( $name, 'loop', '最後まで進んだら、最初に戻ってくり返す', $options );
		echo '</fieldset>';

		// 見せ方。
		echo '<fieldset class="etss-fieldset"><legend>見せ方</legend>';
		self::render_select( $name, 'pagination', 'ページの印（ドットなど）', $options );
		self::render_select( $name, 'navigationPosition', 'ナビゲーション（前後の矢印）の位置', $options );
		self::render_number( $name, 'slidesPerViewPC', '一度に見せる枚数（パソコン）', $options, '1.5 のように小数にすると、次のスライドが少し見えます。' );
		self::render_number( $name, 'slidesPerViewTablet', '一度に見せる枚数（タブレット）', $options, '' );
		self::render_number( $name, 'slidesPerViewMobile', '一度に見せる枚数（スマートフォン）', $options, '' );
		self::render_select( $name, 'slidesPerGroup', '一度に送る枚数', $options );
		self::render_checkbox( $name, 'centeredSlides', '表示中のスライドを中央に寄せる', $options );
		self::render_select( $name, 'width', '外枠の幅', $options );
		echo '</fieldset>';

		// 外枠の class。
		$extra_class = get_post_meta( $post->ID, ETSS_Meta::EXTRA_CLASS, true );
		echo '<div class="etss-field">';
		echo '<label for="etss-extra-class" class="etss-label">外枠に足す class（任意）</label>';
		printf( '<input type="text" class="regular-text" id="etss-extra-class" name="%1$s[extra_class]" value="%2$s" aria-describedby="etss-extra-class-desc" spellcheck="false" autocomplete="off">', esc_attr( $name ), esc_attr( is_string( $extra_class ) ? $extra_class : '' ) );
		echo '<p class="description" id="etss-extra-class-desc">サイトの追加 CSS で、このスライダーだけ見た目を変えたいときに入力します。半角英数字・ハイフン・アンダースコアを、空白で区切って複数入力できます（例: top-main-slider）。空欄でも動きます。</p>';
		echo '</div>';
	}

	/**
	 * チェックボックスを1つ出す。
	 *
	 * @param string $name    フィールド名の接頭辞。
	 * @param string $key     設定名。
	 * @param string $label   ラベル。
	 * @param array  $options 動きの設定。
	 * @return void
	 */
	private static function render_checkbox( $name, $key, $label, array $options ) {
		printf(
			'<p class="etss-option"><label><input type="checkbox" name="%1$s[options][%2$s]" value="1"%3$s> %4$s</label></p>',
			esc_attr( $name ),
			esc_attr( $key ),
			checked( ! empty( $options[ $key ] ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * 数値の入力欄を1つ出す。範囲は ETSS_Options::number_ranges() に合わせる。
	 *
	 * @param string $name    フィールド名の接頭辞。
	 * @param string $key     設定名。
	 * @param string $label   ラベル。
	 * @param array  $options 動きの設定。
	 * @param string $help    補足（空なら出さない）。
	 * @return void
	 */
	private static function render_number( $name, $key, $label, array $options, $help ) {
		$ranges = ETSS_Options::number_ranges();
		$range  = $ranges[ $key ];
		$id     = 'etss-option-' . $key;

		echo '<p class="etss-option">';
		printf( '<label for="%1$s">%2$s</label> ', esc_attr( $id ), esc_html( $label ) );
		printf(
			'<input type="number" class="small-text" id="%1$s" name="%2$s[options][%3$s]" value="%4$s" min="%5$s" max="%6$s" step="%7$s"%8$s>',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $key ),
			esc_attr( isset( $options[ $key ] ) ? $options[ $key ] : '' ),
			esc_attr( $range['min'] ),
			esc_attr( $range['max'] ),
			esc_attr( $range['step'] ),
			'' !== $help ? ' aria-describedby="' . esc_attr( $id ) . '-desc"' : ''
		);
		printf( ' <span class="description">%1$s〜%2$s</span>', esc_html( $range['min'] ), esc_html( $range['max'] ) );
		if ( '' !== $help ) {
			printf( '<span class="description etss-option__help" id="%1$s-desc">%2$s</span>', esc_attr( $id ), esc_html( $help ) );
		}
		echo '</p>';
	}

	/**
	 * 選択式の入力欄を1つ出す。
	 *
	 * @param string $name    フィールド名の接頭辞。
	 * @param string $key     設定名。
	 * @param string $label   ラベル。
	 * @param array  $options 動きの設定。
	 * @return void
	 */
	private static function render_select( $name, $key, $label, array $options ) {
		$choices = ETSS_Options::choices();
		$id      = 'etss-option-' . $key;

		echo '<p class="etss-option">';
		printf( '<label for="%1$s">%2$s</label> ', esc_attr( $id ), esc_html( $label ) );
		printf( '<select id="%1$s" name="%2$s[options][%3$s]">', esc_attr( $id ), esc_attr( $name ), esc_attr( $key ) );
		foreach ( $choices[ $key ] as $value => $text ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( isset( $options[ $key ] ) ? (string) $options[ $key ] : '', (string) $value, false ), esc_html( $text ) );
		}
		echo '</select></p>';
	}

	/**
	 * 「このスライダーを置く」の説明を出す。ブロック名と、置いているページへのリンク。
	 *
	 * @param WP_Post $post 投稿。
	 * @return void
	 */
	public static function render_placement_box( $post ) {
		echo '<p>ページの本文に、ブロック「' . esc_html( self::BLOCK_TITLE ) . '」（<code>' . esc_html( self::BLOCK_NAME ) . '</code>）を追加し、スライダーの選択でこのスライダー「' . esc_html( '' !== trim( $post->post_title ) ? $post->post_title : '（名前未入力）' ) . '」を選ぶと、そこに表示されます。</p>';

		$pages = self::find_placements( $post->ID );

		if ( $pages ) {
			echo '<p>このスライダーを置いているページ:</p><ul class="etss-placements">';
			foreach ( $pages as $page ) {
				$title = '' !== trim( $page->post_title ) ? $page->post_title : '（タイトルなし）';
				$link  = get_edit_post_link( $page->ID );
				if ( $link ) {
					printf( '<li><a href="%1$s">%2$s</a>（%3$s）</li>', esc_url( $link ), esc_html( $title ), esc_html( self::post_type_label( $page ) ) );
				} else {
					printf( '<li>%1$s（%2$s）</li>', esc_html( $title ), esc_html( self::post_type_label( $page ) ) );
				}
			}
			echo '</ul>';
		} else {
			printf(
				'<p>このスライダーを置いているページは、まだ見つかっていません。<a href="%1$s">固定ページを追加</a>するか、<a href="%2$s">固定ページの一覧</a>から開いて、ブロックを追加してください。</p>',
				esc_url( admin_url( 'post-new.php?post_type=page' ) ),
				esc_url( admin_url( 'edit.php?post_type=page' ) )
			);
		}

		echo '<p class="description">投稿・固定ページの本文から探しています。再利用ブロックの中や、テーマのテンプレート（サイトエディターで編集するもの）に置いたものは、ここには出ません。</p>';
	}

	/**
	 * 投稿タイプの表示名を返す。
	 *
	 * @param WP_Post $page 投稿。
	 * @return string 表示名。
	 */
	private static function post_type_label( $page ) {
		$object = get_post_type_object( $page->post_type );

		return $object ? $object->labels->singular_name : $page->post_type;
	}

	/**
	 * このスライダーを置いているページのうち、公開されていて開けるものの URL を返す（日時プレビューの行き先）。
	 *
	 * 1つのリクエストの中では、スライダーごとに1回だけ探す。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @return string URL。見つからなければ空文字。
	 */
	public static function placement_url( $slider_id ) {
		static $cache = array();

		$slider_id = (int) $slider_id;
		if ( ! isset( $cache[ $slider_id ] ) ) {
			$cache[ $slider_id ] = '';
			foreach ( self::find_placements( $slider_id ) as $page ) {
				// 下書き・承認待ち・予約のページは、そのままの URL では開けない。
				if ( in_array( $page->post_status, array( 'publish', 'private' ), true ) ) {
					$cache[ $slider_id ] = (string) get_permalink( $page );
					break;
				}
			}
		}

		return $cache[ $slider_id ];
	}

	/**
	 * このスライダーのブロックを本文に置いている投稿を探す。
	 *
	 * 本文にブロック名を含むものを検索し、`has_block()` で絞り、ブロックの属性（sliderId）で、このスライダーのものだけにする。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @return WP_Post[] 投稿。
	 */
	private static function find_placements( $slider_id ) {
		$query = new WP_Query(
			array(
				'post_type'              => array_values( get_post_types( array( 'public' => true ) ) ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				's'                      => self::BLOCK_NAME,
				'posts_per_page'         => self::PLACEMENT_LIMIT,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$found = array();
		foreach ( $query->posts as $page ) {
			// 読める権限のない投稿は出さない（タイトルの漏れを防ぐ）。
			if ( current_user_can( 'read_post', $page->ID ) && has_block( self::BLOCK_NAME, $page ) && self::content_uses_slider( $page->post_content, $slider_id ) ) {
				$found[] = $page;
			}
		}

		return $found;
	}

	/**
	 * 本文のブロックの中に、指定したスライダーを選んだブロックがあるかを判定する。
	 *
	 * ブロックの区切りのコメント（`<!-- wp:etss/slider {"sliderId":12} /-->`）の属性を読む。WordPress に依存しない。
	 *
	 * @param string $content   投稿の本文。
	 * @param int    $slider_id スライダーの投稿 ID。
	 * @return bool あれば true。
	 */
	public static function content_uses_slider( $content, $slider_id ) {
		$pattern = '/<!--\s+wp:' . preg_quote( self::BLOCK_NAME, '/' ) . '(?:\s+(\{.*?\}))?\s+\/?-->/s';

		if ( ! preg_match_all( $pattern, (string) $content, $matches ) ) {
			return false;
		}

		foreach ( $matches[1] as $json ) {
			$attributes = '' === $json ? array() : json_decode( $json, true );
			if ( is_array( $attributes ) && isset( $attributes['sliderId'] ) && (int) $attributes['sliderId'] === (int) $slider_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * スライダーの設定を保存する。
	 *
	 * @param int     $post_id スライダーの投稿 ID。
	 * @param WP_Post $post    投稿。
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		unset( $post );

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! is_string( $_POST[ self::NONCE_NAME ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION . $post_id ) ) {
			return;
		}

		$input = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 項目ごとに ETSS_Options で整える。

		// ひな形。スライドがあるときは変えない。登録されていない ID は受け付けない。
		$template_id = self::get_template_id( $post_id );
		if ( 0 === self::count_slides( $post_id ) && isset( $input['template'] ) && is_string( $input['template'] ) && null !== ETSS_Templates::get( $input['template'] ) ) {
			$template_id = $input['template'];
		}
		update_post_meta( $post_id, ETSS_Meta::TEMPLATE, $template_id );

		// 動きの設定。
		$posted_options = ( isset( $input['options'] ) && is_array( $input['options'] ) ) ? $input['options'] : array();
		update_post_meta( $post_id, ETSS_Meta::OPTIONS, ETSS_Options::sanitize( $posted_options, ETSS_Templates::default_options( $template_id ) ) );

		// 外枠の class。
		$extra_class = ETSS_Options::sanitize_extra_class( isset( $input['extra_class'] ) ? $input['extra_class'] : '' );
		if ( '' === $extra_class ) {
			delete_post_meta( $post_id, ETSS_Meta::EXTRA_CLASS );
		} else {
			update_post_meta( $post_id, ETSS_Meta::EXTRA_CLASS, $extra_class );
		}
	}
}
