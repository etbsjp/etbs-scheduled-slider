<?php
/**
 * スライドの一覧（§4.5）。列・状態の切り替え・スライダーでの絞り込み・一覧の上の要約と警告。
 *
 * 状態・件数・要約・0 枚になる時間帯の計算は、純粋な関数（ETSS_List・ETSS_Display）に任せる。
 * このクラスは、`$_GET`・`time()`・投稿やメタの読み出し・画面への出力といった WordPress に触れる部分（フックの入口）だけを持つ。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * スライドの一覧画面をまとめたクラス。
 */
class ETSS_List_Admin {

	/**
	 * 状態の切り替えを渡すクエリ引数。
	 */
	const STATE_ARG = 'etss_state';

	/**
	 * スライダーでの絞り込みを渡すクエリ引数（新規追加の画面でスライダーを選んでおく引数と同じ）。
	 */
	const SLIDER_ARG = 'etss_slider';

	/**
	 * 一覧で扱うスライドの投稿ステータス（ゴミ箱は含めない）。
	 *
	 * @var string[]
	 */
	const POST_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * 1回の表示のあいだ使い回す、スライドの行（読み出しは1回だけにするため）。
	 *
	 * @var array|null
	 */
	private static $rows = null;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		$slide = ETSS_Post_Types::SLIDE;

		add_filter( 'manage_' . $slide . '_posts_columns', array( __CLASS__, 'filter_columns' ) );
		add_action( 'manage_' . $slide . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . $slide . '_sortable_columns', array( __CLASS__, 'filter_sortable_columns' ) );
		add_filter( 'views_edit-' . $slide, array( __CLASS__, 'filter_views' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'filter_post_states' ), 10, 2 );
		add_filter( 'disable_months_dropdown', array( __CLASS__, 'disable_months_dropdown' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_slider_filter' ), 10, 2 );
		add_filter( 'the_posts', array( __CLASS__, 'prime_row_caches' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_summary' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * 現在時刻（UTC の Unix 秒）を返す。時刻を読むのはここだけにする。
	 *
	 * @return int UTC の Unix 秒。
	 */
	private static function now() {
		return time();
	}

	/**
	 * いま開いている画面が、スライドの一覧かを返す。
	 *
	 * @return bool スライドの一覧なら true。
	 */
	private static function is_slide_list_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && ETSS_Post_Types::SLIDE === $screen->post_type && 'edit' === $screen->base;
	}

	/**
	 * ゴミ箱の一覧を開いているかを返す。
	 *
	 * @return bool ゴミ箱の一覧なら true。
	 */
	private static function is_trash_view() {
		return isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 表示を切り替えるだけで、何も変更しない。比較だけに使う。
	}

	/**
	 * URL で指定された状態の切り替えを読む。
	 *
	 * @return string 切り替えの値（指定が無い・不正なら「表示終了以外」）。
	 */
	public static function read_request_state() {
		return ETSS_List::sanitize_state( isset( $_GET[ self::STATE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::STATE_ARG ] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示を切り替えるだけで、何も変更しない。
	}

	/**
	 * スライドの行を読み出す（ゴミ箱を除くすべて）。1回の表示のあいだ使い回す。
	 *
	 * @return array ETSS_List の「行」の配列。
	 */
	private static function load_rows() {
		if ( null !== self::$rows ) {
			return self::$rows;
		}

		$posts = get_posts(
			array(
				'post_type'        => ETSS_Post_Types::SLIDE,
				'post_status'      => self::POST_STATUSES,
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);

		self::$rows = array();
		foreach ( $posts as $post ) {
			$saved        = ETSS_Slide_Admin::get_saved( $post->ID );
			self::$rows[] = array(
				'id'          => (int) $post->ID,
				'title'       => (string) $post->post_title,
				'post_status' => (string) $post->post_status,
				'slider_id'   => $saved['slider_id'],
				'start'       => $saved['start'],
				'end'         => $saved['end'],
				'menu_order'  => (int) $post->menu_order,
			);
		}

		return self::$rows;
	}

	/**
	 * スライダーの名前を読み出す（ゴミ箱を除く。名前順）。
	 *
	 * @return array スライダーの投稿 ID => 名前。
	 */
	private static function load_slider_names() {
		$posts = get_posts(
			array(
				'post_type'        => ETSS_Post_Types::SLIDER,
				'post_status'      => self::POST_STATUSES,
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);

		$names = array();
		foreach ( $posts as $slider ) {
			$names[ (int) $slider->ID ] = '' !== trim( $slider->post_title ) ? $slider->post_title : '（名前なし）';
		}

		return $names;
	}

	/**
	 * 一覧の列を、スライド用にする。
	 *
	 * @param array $columns 列（列のキー => 見出し）。
	 * @return array 列。
	 */
	public static function filter_columns( $columns ) {
		return array(
			'cb'          => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'etss_thumb'  => 'サムネイル',
			'title'       => '代替テキスト',
			'etss_slider' => 'スライダー',
			'etss_status' => '状態',
			'etss_start'  => '表示開始',
			'etss_end'    => '表示終了',
			'etss_order'  => '表示順',
		);
	}

	/**
	 * 並べ替えできる列を足す（表示順）。
	 *
	 * @param array $columns 並べ替えできる列。
	 * @return array 並べ替えできる列。
	 */
	public static function filter_sortable_columns( $columns ) {
		$columns['etss_order'] = array( 'menu_order', false );

		return $columns;
	}

	/**
	 * タイトルの後ろの「— 下書き」を出さない（状態の列が、理由つきで伝えるため）。
	 *
	 * @param string[] $states 投稿の状態の表示（キー => 文言）。
	 * @param WP_Post  $post   投稿。
	 * @return string[] 投稿の状態の表示。
	 */
	public static function filter_post_states( $states, $post ) {
		if ( $post && ETSS_Post_Types::SLIDE === $post->post_type ) {
			unset( $states['draft'], $states['pending'] );
		}

		return $states;
	}

	/**
	 * 年月の絞り込みを出さない（表示開始・表示終了で見るため、投稿日では絞らない）。
	 *
	 * @param bool   $disabled  出さないか。
	 * @param string $post_type 投稿タイプ。
	 * @return bool 出さないか。
	 */
	public static function disable_months_dropdown( $disabled, $post_type ) {
		return ETSS_Post_Types::SLIDE === $post_type ? true : $disabled;
	}

	/**
	 * 一覧の列の中身を出す。
	 *
	 * @param string $column  列のキー。
	 * @param int    $post_id 投稿 ID。
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$saved    = ETSS_Slide_Admin::get_saved( $post->ID );
		$now      = self::now();
		$timezone = wp_timezone();

		switch ( $column ) {
			case 'etss_thumb':
				self::render_thumbnails( $saved );
				break;

			case 'etss_slider':
				self::render_slider_cell( $saved['slider_id'] );
				break;

			case 'etss_status':
				self::render_status_cell( $post, $saved, $now );
				break;

			case 'etss_start':
				self::render_start_cell( $post, $saved, $now, $timezone );
				break;

			case 'etss_end':
				echo $saved['end'] > 0 ? esc_html( ETSS_Display::format_list_datetime( $saved['end'], $now, $timezone ) ) : '<span class="etss-muted">なし</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定の文字列か、エスケープ済み。
				break;

			case 'etss_order':
				echo esc_html( (string) (int) $post->menu_order );
				break;
		}
	}

	/**
	 * サムネイルの列を出す。ひな形の画像枠を小さく並べ、足りない枠は「⚠ 縦長なし」のように出す。
	 *
	 * @param array $saved ETSS_Slide_Admin::get_saved() の結果。
	 * @return void
	 */
	private static function render_thumbnails( array $saved ) {
		$template = $saved['slider_id'] > 0 ? ETSS_Templates::for_slider( $saved['slider_id'] ) : null;
		if ( null === $template ) {
			echo '<span class="etss-muted">画像枠なし</span>';
			return;
		}

		echo '<ul class="etss-thumbs">';
		foreach ( $template['slots'] as $slot ) {
			$attachment_id = isset( $saved['images'][ $slot['id'] ] ) ? (int) $saved['images'][ $slot['id'] ] : 0;
			$image         = $attachment_id > 0 ? wp_get_attachment_image( $attachment_id, 'thumbnail', false, array( 'alt' => $slot['label'] ) ) : '';

			if ( '' !== $image ) {
				// wp_get_attachment_image() はエスケープ済みの HTML を返す。
				echo '<li class="etss-thumbs__item">' . $image . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( ! empty( $slot['required'] ) ) {
				echo '<li class="etss-thumbs__item etss-thumbs__missing"><span aria-hidden="true">⚠ </span>' . esc_html( $slot['label'] ) . 'なし</li>';
			} else {
				echo '<li class="etss-thumbs__item etss-thumbs__empty">' . esc_html( $slot['label'] ) . 'なし</li>';
			}
		}
		echo '</ul>';
	}

	/**
	 * スライダーの列を出す。名前を押すと、そのスライダーで絞り込む。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @return void
	 */
	private static function render_slider_cell( $slider_id ) {
		if ( $slider_id <= 0 ) {
			echo '<span class="etss-muted">未設定</span>';
			return;
		}

		if ( ETSS_Post_Types::SLIDER !== get_post_type( $slider_id ) ) {
			echo '<span class="etss-muted">見つかりません</span>';
			return;
		}

		$title = get_the_title( $slider_id );
		$name  = '' !== trim( $title ) ? $title : '（名前なし）';

		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::list_url( ETSS_List::STATE_NOT_ENDED, $slider_id ) ),
			esc_html( $name )
		);
	}

	/**
	 * 状態の列を出す。下書きには理由を添える。
	 *
	 * @param WP_Post $post  投稿。
	 * @param array   $saved ETSS_Slide_Admin::get_saved() の結果。
	 * @param int     $now   現在時刻（UTC の Unix 秒）。
	 * @return void
	 */
	private static function render_status_cell( $post, array $saved, $now ) {
		$status  = ETSS_Display::status( $post->post_status, $saved['start'], $saved['end'], $now );
		$reasons = array();

		if ( ETSS_Display::STATUS_DRAFT === $status ) {
			// 登録に必要な項目のうち、足りないものを理由にする（保存時と同じ検査を、保存済みの値にかける）。
			$input   = ETSS_Validator::saved_to_input( $saved, wp_timezone() );
			$result  = ETSS_Validator::validate_slide( $post->post_title, $input, ETSS_Slide_Admin::build_context( $input ) );
			$reasons = ETSS_List::draft_reasons( $result['errors'], $input );
		}

		$text = self::wrap_reasons( esc_html( ETSS_List::format_status( $status, $reasons ) ) );

		// 表示中だけ太字にする（色は足さない）。
		echo ETSS_Display::STATUS_ACTIVE === $status ? '<strong>' . $text . '</strong>' : $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html 済み。
	}

	/**
	 * 状態の文言を、折り返してよい位置（語句の区切り）ごとの span に分ける（語句の途中で改行されないため）。
	 *
	 * 区切りは、状態名の後ろ（括弧の前）、理由の「・」の後ろ、「…」の後ろ。「（」「）」「・」「…」は前後どちらかの語句に付けて、行頭に単独で落ちないようにする。
	 *
	 * @param string $escaped esc_html 済みの状態の文言（例「下書き（画像不足・リンク先なし）」）。
	 * @return string 語句ごとに span で囲んだ HTML。
	 */
	private static function wrap_reasons( $escaped ) {
		$wrap = static function ( $text ) {
			return '<span class="etss-status__part">' . $text . '</span>';
		};

		if ( ! preg_match( '/^(.*?)（(.+)）$/us', $escaped, $m ) ) {
			return $wrap( $escaped );
		}

		$pieces = preg_split( '/(?<=[・…])/u', $m[2], -1, PREG_SPLIT_NO_EMPTY );
		$last   = count( $pieces ) - 1;
		$html   = $wrap( $m[1] );
		foreach ( $pieces as $i => $piece ) {
			$html .= $wrap( ( 0 === $i ? '（' : '' ) . $piece . ( $last === $i ? '）' : '' ) );
		}

		return $html;
	}

	/**
	 * 表示開始の列を出す。表示予定には残り日数を添える。
	 *
	 * @param WP_Post      $post     投稿。
	 * @param array        $saved    ETSS_Slide_Admin::get_saved() の結果。
	 * @param int          $now      現在時刻（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone タイムゾーン。
	 * @return void
	 */
	private static function render_start_cell( $post, array $saved, $now, DateTimeZone $timezone ) {
		if ( $saved['start'] <= 0 ) {
			echo '<span class="etss-muted">未設定</span>';
			return;
		}

		echo esc_html( ETSS_Display::format_list_datetime( $saved['start'], $now, $timezone ) );

		if ( ETSS_Display::STATUS_SCHEDULED === ETSS_Display::status( $post->post_status, $saved['start'], $saved['end'], $now ) ) {
			echo '<span class="etss-remaining">' . esc_html( ETSS_Display::format_remaining_short( $saved['start'] - $now ) ) . '</span>';
		}
	}

	/**
	 * 一覧の URL を作る。
	 *
	 * @param string $state     状態の切り替え（「表示終了以外」は既定なので引数に付けない）。
	 * @param int    $slider_id 絞り込むスライダーの投稿 ID（0 なら付けない）。
	 * @return string URL。
	 */
	private static function list_url( $state, $slider_id ) {
		$args = array( 'post_type' => ETSS_Post_Types::SLIDE );
		if ( ETSS_List::STATE_NOT_ENDED !== $state ) {
			$args[ self::STATE_ARG ] = $state;
		}
		if ( $slider_id > 0 ) {
			$args[ self::SLIDER_ARG ] = (int) $slider_id;
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * 一覧の上の切り替え（表示終了以外／表示中／表示予定／下書き／表示終了／すべて）を、件数つきで作る。
	 *
	 * WordPress の「公開済み」「下書き」などの切り替えと入れ替える（WordPress の状態では、表示期間の状態を見分けられないため）。
	 * ゴミ箱の切り替えだけ、WordPress のものを残す。
	 *
	 * @param array $views 切り替え（キー => リンクの HTML）。
	 * @return array 切り替え。
	 */
	public static function filter_views( $views ) {
		$slider_id = ETSS_Slide_Admin::read_request_slider_id();
		$counts    = ETSS_List::count_by_state( self::load_rows(), self::now(), $slider_id );
		$current   = self::is_trash_view() ? '' : self::read_request_state();
		$links     = array();

		foreach ( ETSS_List::states() as $state => $label ) {
			$links[ 'etss-' . $state ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">（%4$d）</span></a>',
				esc_url( self::list_url( $state, $slider_id ) ),
				$state === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) $counts[ $state ]
			);
		}

		if ( isset( $views['trash'] ) ) {
			$links['trash'] = $views['trash'];
		}

		return $links;
	}

	/**
	 * 一覧の上に、スライダーでの絞り込みを出す。
	 *
	 * @param string $post_type 投稿タイプ。
	 * @param string $which     表示位置（`top`・`bottom`）。
	 * @return void
	 */
	public static function render_slider_filter( $post_type, $which = 'top' ) {
		if ( ETSS_Post_Types::SLIDE !== $post_type || 'top' !== $which || self::is_trash_view() ) {
			return;
		}

		$current = ETSS_Slide_Admin::read_request_slider_id();

		echo '<label for="etss-filter-slider" class="screen-reader-text">スライダーで絞り込む</label>';
		echo '<select name="' . esc_attr( self::SLIDER_ARG ) . '" id="etss-filter-slider">';
		echo '<option value="0">すべてのスライダー</option>';
		foreach ( self::load_slider_names() as $slider_id => $name ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $slider_id, selected( $current, $slider_id, false ), esc_html( $name ) );
		}
		echo '</select>';

		// 絞り込んでも、いまの切り替えを保つ。
		$state = self::read_request_state();
		if ( ETSS_List::STATE_NOT_ENDED !== $state ) {
			echo '<input type="hidden" name="' . esc_attr( self::STATE_ARG ) . '" value="' . esc_attr( $state ) . '">';
		}
	}

	/**
	 * 一覧の検索条件を、切り替え・絞り込みに合わせる。
	 *
	 * 表示期間の状態は投稿のメタと現在時刻で決まるので、当てはまる投稿 ID を先に求めて渡す。
	 * 並び順の指定が無いときは表示順（ETSS_List::sort_rows() の順）にする。
	 *
	 * @param WP_Query $query クエリ。
	 * @return void
	 */
	public static function filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ETSS_Post_Types::SLIDE !== $query->get( 'post_type' ) || ! self::is_slide_list_screen() || self::is_trash_view() ) {
			return;
		}

		$ids = ETSS_List::ids_for_state( self::load_rows(), self::now(), self::read_request_state(), ETSS_Slide_Admin::read_request_slider_id() );

		// 空の post__in は絞り込みなしと同じ扱いになるので、当てはまるものが無いときは存在しない ID を渡す。
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
		// URL の post_status（WordPress の「公開済み」などの切り替え）は使わず、状態の切り替えで絞った ID に任せる。
		$query->set( 'post_status', self::POST_STATUSES );

		if ( ! isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 並び順を切り替えるだけで、何も変更しない。
			$query->set( 'orderby', 'post__in' );
		}
	}

	/**
	 * 一覧に出す行が使う投稿（サムネイルの添付ファイル・スライダー）を、まとめて読み込む（行ごとの問い合わせを減らすため）。
	 *
	 * @param WP_Post[] $posts 一覧に出す投稿。
	 * @param WP_Query  $query クエリ。
	 * @return WP_Post[] 投稿（そのまま返す）。
	 */
	public static function prime_row_caches( $posts, $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ETSS_Post_Types::SLIDE !== $query->get( 'post_type' ) || ! self::is_slide_list_screen() ) {
			return $posts;
		}

		$ids = array();
		foreach ( $posts as $post ) {
			$saved = ETSS_Slide_Admin::get_saved( $post->ID );
			if ( $saved['slider_id'] > 0 ) {
				$ids[] = $saved['slider_id'];
			}
			foreach ( $saved['images'] as $attachment_id ) {
				$ids[] = (int) $attachment_id;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}

		return $posts;
	}

	/**
	 * 一覧の上に、スライダーごとの要約と警告を出す。
	 *
	 * @return void
	 */
	public static function render_summary() {
		if ( ! self::is_slide_list_screen() || self::is_trash_view() ) {
			return;
		}

		// スライダーで絞り込んでいるときは、要約も警告もそのスライダーの分だけにする。
		$sliders = ETSS_List::select_sliders( self::load_slider_names(), ETSS_Slide_Admin::read_request_slider_id() );
		if ( ! $sliders ) {
			return;
		}

		$now      = self::now();
		$timezone = wp_timezone();
		$by_slide = array();
		foreach ( self::load_rows() as $row ) {
			$by_slide[ $row['slider_id'] ][] = $row;
		}

		$summaries = '';
		$warnings  = '';
		$has_draft = false;
		foreach ( $sliders as $slider_id => $name ) {
			$rows       = isset( $by_slide[ $slider_id ] ) ? $by_slide[ $slider_id ] : array();
			$summaries .= self::summary_row_html( $slider_id, $name, $rows, $now, $timezone );
			$warnings  .= self::warning_items_html( $slider_id, $name, $rows, $now, $timezone );
			$has_draft  = $has_draft || (bool) ETSS_List::drafts_with_start( $rows, $now );
		}

		// 下書きの警告の説明は、警告のまとまりの先頭に1回だけ出す。
		if ( $has_draft ) {
			$warnings = '<li class="etss-summary__row"><span class="etss-summary__text">下書きのスライドは、編集画面で「表示に登録」を押すと、表示開始日時から表示されます。</span></li>' . $warnings;
		}

		echo '<div class="notice etss-summary"><ul class="etss-summary__list">' . $summaries . '</ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 各行はエスケープ済みの HTML。

		if ( '' !== $warnings ) {
			echo '<div class="notice notice-warning etss-summary-warnings"><ul class="etss-summary__list">' . $warnings . '</ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 各行はエスケープ済みの HTML。
		}
	}

	/**
	 * スライダー1つ分の要約の行を作る。「トップ: 表示中 9 枚／次の切り替え …」。
	 *
	 * @param int          $slider_id スライダーの投稿 ID。
	 * @param string       $name      スライダーの名前。
	 * @param array        $rows      そのスライダーに属する行。
	 * @param int          $now       現在時刻（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone  タイムゾーン。
	 * @return string エスケープ済みの HTML（`li`）。
	 */
	private static function summary_row_html( $slider_id, $name, array $rows, $now, DateTimeZone $timezone ) {
		$summary = ETSS_List::summarize_slider( $rows, $now );

		$html  = '<li class="etss-summary__row">';
		$html .= '<a href="' . esc_url( self::list_url( ETSS_List::STATE_NOT_ENDED, $slider_id ) ) . '" class="etss-summary__name">' . esc_html( $name ) . '</a>';
		$html .= '<span class="etss-summary__text">表示中 <strong>' . (int) $summary['active'] . ' 枚</strong>／' . esc_html( ETSS_List::describe_next( $summary['next'], $now, $timezone ) ) . '</span>';

		// 「この時刻の表示を見る」リンクの置き場所。中身は日時プレビュー（#5）が足す。
		ob_start();

		/**
		 * スライダーの要約の行に、「この時刻の表示を見る」リンクを足すための場所。
		 *
		 * @param int      $slider_id   スライダーの投稿 ID。
		 * @param int|null $next_switch 次の切り替え時刻（UTC の Unix 秒。無ければ null）。
		 */
		do_action( 'etss_summary_row_actions', $slider_id, null === $summary['next'] ? null : $summary['next']['time'] );
		$actions = ob_get_clean();
		if ( '' !== trim( (string) $actions ) ) {
			$html .= '<span class="etss-summary__actions">' . $actions . '</span>';
		}

		return $html . '</li>';
	}

	/**
	 * スライダー1つ分の警告の項目を作る。0 枚になる時間帯と、表示開始日時があるのに下書きのもの。
	 *
	 * @param int          $slider_id スライダーの投稿 ID。
	 * @param string       $name      スライダーの名前。
	 * @param array        $rows      そのスライダーに属する行。
	 * @param int          $now       現在時刻（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone  タイムゾーン。
	 * @return string エスケープ済みの HTML（`li` の並び。警告が無ければ空文字）。
	 */
	private static function warning_items_html( $slider_id, $name, array $rows, $now, DateTimeZone $timezone ) {
		$html = '';

		// これから 0 枚になる時間帯。
		foreach ( ETSS_List::zero_windows( $rows, $now ) as $window ) {
			$html .= '<li class="etss-summary__row"><span class="etss-summary__text"><strong>' . esc_html( $name ) . '</strong>: ' . esc_html( ETSS_List::describe_zero_window( $window, $now, $timezone ) ) . '</span>';
			$html .= ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=' . ETSS_Post_Types::SLIDE . '&' . self::SLIDER_ARG . '=' . (int) $slider_id ) ) . '" aria-label="' . esc_attr( $name . 'にスライドを追加' ) . '">スライドを追加</a></li>';
		}

		// 表示開始日時があるのに下書きのもの（表示に登録し忘れている疑い）。
		$drafts = ETSS_List::drafts_with_start( $rows, $now );
		if ( $drafts ) {
			$links = array();
			foreach ( array_slice( $drafts, 0, 3 ) as $row ) {
				$edit_link = (string) get_edit_post_link( $row['id'], 'raw' );
				if ( '' === $edit_link ) {
					continue;
				}
				$links[] = sprintf(
					'<a href="%1$s">%2$s（表示開始 %3$s）</a>',
					esc_url( $edit_link ),
					esc_html( ETSS_List::shorten_title( $row['title'] ) ),
					esc_html( ETSS_Display::format_list_datetime( $row['start'], $now, $timezone ) )
				);
			}
			$more = count( $drafts ) - count( $links );

			$html .= '<li class="etss-summary__row"><span class="etss-summary__text"><strong>' . esc_html( $name ) . '</strong>: 表示開始日時が入っているのに、下書きのため表示されないスライドが ' . count( $drafts ) . ' 枚あります。';
			$html .= ' ' . implode( '、', $links ) . ( $more > 0 ? ' ほか' . $more . '枚' : '' ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $links は上でエスケープ済み。
		}

		return $html;
	}

	/**
	 * 一覧の CSS を読み込む。
	 *
	 * @param string $hook_suffix 管理画面のフック名。
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'edit.php' !== $hook_suffix || ! self::is_slide_list_screen() ) {
			return;
		}

		wp_enqueue_style( 'etss-admin-list', plugins_url( 'assets/admin-list.css', ETSS_PLUGIN_FILE ), array(), ETSS_Admin::asset_version() );
	}
}
