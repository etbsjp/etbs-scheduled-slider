<?php
/**
 * 日時プレビュー（docs/spec.md §7・D10）。
 *
 * `?etss_at=2026-11-06T12:00` を付けると、権限のある人にだけ、その時刻の並びを下書きも含めて見せる。
 * 時刻を読む（parse_at）・権限を見る（current_user_can_preview）・nocache を出す（start）を別の関数にしてある。
 * `$_GET` と `time()` を読むのはフックの入口（start・render_banner）だけで、そのほかは値を引数で受け取る。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 日時プレビューをまとめたクラス。
 */
class ETSS_Preview {

	/**
	 * プレビューの時刻を渡す URL の引数名。
	 */
	const QUERY_VAR = 'etss_at';

	/**
	 * プレビュー中のページをキャッシュさせない理由（LiteSpeed Cache のログに残る）。
	 */
	const NOCACHE_REASON = 'ETBS Scheduled Slider: 日時プレビュー';

	/**
	 * このリクエストでプレビューする時刻（UTC の Unix 秒）。プレビューしていなければ null。
	 *
	 * @var int|null
	 */
	private static $at = null;

	/**
	 * 権限のある人が `etss_at` を付けたが、日時として読めなかったか。
	 *
	 * @var bool
	 */
	private static $invalid = false;

	/**
	 * 帯をすでに出したか。
	 *
	 * @var bool
	 */
	private static $banner_printed = false;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// 描画（ブロック）より前に時刻を決める。
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 1 );
		add_action( 'wp_body_open', array( __CLASS__, 'render_banner' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_banner_fallback' ) );
		add_action( 'etss_after_schedule_fields', array( __CLASS__, 'render_slide_links' ) );
		add_action( 'etss_summary_row_actions', array( __CLASS__, 'render_summary_link' ), 10, 2 );
	}

	/**
	 * プレビューの時刻を返す。
	 *
	 * @return int|null プレビュー中ならその時刻（UTC の Unix 秒）。そうでなければ null。
	 */
	public static function at() {
		return self::$at;
	}

	/**
	 * リクエストごとの状態を空にする。テスト用。
	 *
	 * @return void
	 */
	public static function reset() {
		self::$at             = null;
		self::$invalid        = false;
		self::$banner_printed = false;
	}

	/**
	 * `etss_at` を読み、権限があればプレビューを始める（フックの入口）。プレビュー中はページをキャッシュさせない。
	 *
	 * 権限の無い人・ログアウト中は、`etss_at` を付けても何もしない（今の並びのまま）。
	 *
	 * @return void
	 */
	public static function start() {
		// 引数が無ければ何もしない。
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示を変えるだけで、何も保存しない。権限は下で確かめる。
			return;
		}

		// 権限を確かめる。無ければ、引数が付いていないのと同じにする。
		if ( ! self::current_user_can_preview() ) {
			return;
		}

		// 時刻を読む。形式が正しくなければ時刻は決めず、帯で伝える。
		$raw           = $_GET[ self::QUERY_VAR ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 文字列のときだけ整えて使う。
		$raw           = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
		self::$at      = self::parse_at( $raw, wp_timezone() );
		self::$invalid = null === self::$at;

		// 見る人ごとに内容が違うので、このページはキャッシュさせない。
		ETSS_Cache::nocache( self::NOCACHE_REASON );
	}

	/**
	 * 日時プレビューをしてよい人かを判定する。ログイン中で、D9 と同じ権限（`ETSS_Post_Types::get_capability()`）を持つ人だけ。
	 *
	 * @return bool してよければ true。
	 */
	public static function current_user_can_preview() {
		return is_user_logged_in() && current_user_can( ETSS_Post_Types::get_capability() );
	}

	/**
	 * `etss_at` の値を UTC の Unix 秒にする。
	 *
	 * @param mixed        $value    `2026-11-06T12:00`（秒つきも可）の形の文字列。サイトのタイムゾーンで解釈する。
	 * @param DateTimeZone $timezone サイトのタイムゾーン。
	 * @return int|null UTC の Unix 秒。読めなければ null。
	 */
	public static function parse_at( $value, DateTimeZone $timezone ) {
		return ETSS_Schedule::parse_datetime_local( is_string( $value ) ? trim( $value ) : $value, $timezone );
	}

	/**
	 * `etss_at` に渡す値を作る。分が 0 秒でないときだけ秒まで付ける（開始・終了ちょうどの時刻を再現できるように）。
	 *
	 * @param int          $timestamp UTC の Unix 秒。
	 * @param DateTimeZone $timezone  サイトのタイムゾーン。
	 * @return string 例 `2026-11-06T12:00`。
	 */
	public static function format_at_param( $timestamp, DateTimeZone $timezone ) {
		$date = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $timezone );

		return $date->format( 'Y-m-d\TH:i' ) . ( '00' === $date->format( 's' ) ? '' : $date->format( ':s' ) );
	}

	/**
	 * ページの URL に、プレビューの時刻を付ける。
	 *
	 * @param string       $page_url  プレビューするページの URL。
	 * @param int          $timestamp プレビューする時刻（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone  サイトのタイムゾーン。
	 * @return string プレビュー用の URL。
	 */
	public static function build_url( $page_url, $timestamp, DateTimeZone $timezone ) {
		$page_url = remove_query_arg( self::QUERY_VAR, $page_url );

		return $page_url . ( false === strpos( $page_url, '?' ) ? '?' : '&' ) . self::QUERY_VAR . '=' . rawurlencode( self::format_at_param( $timestamp, $timezone ) );
	}

	/**
	 * 帯の文言を作る。
	 *
	 * @param int|null     $at       プレビューする時刻（UTC の Unix 秒）。読めなかったときは null。
	 * @param int          $now      現在時刻（UTC の Unix 秒。年が違うときだけ年を付けるために使う）。
	 * @param DateTimeZone $timezone サイトのタイムゾーン。
	 * @return string 文言（エスケープ前）。
	 */
	public static function banner_text( $at, $now, DateTimeZone $timezone ) {
		if ( null === $at ) {
			return '日時を読み取れませんでした（今の並びを表示しています）。スライドの編集画面の「この時刻の表示を見る」や、一覧の「切り替え後の表示を見る」から開き直してください。';
		}

		return 'プレビュー中: ' . ETSS_Display::format_list_datetime( $at, $now, $timezone ) . ' の並び（下書きを含む）';
	}

	/**
	 * 帯の HTML を作る。
	 *
	 * @param string $text     banner_text() の結果。
	 * @param string $exit_url プレビューを終えるリンク先（`etss_at` を外した URL）。
	 * @param bool   $at_top   true なら本文の先頭に置く帯。false なら画面の下に固定する帯（テーマが `wp_body_open` を呼ばないとき）。
	 * @param bool   $is_error true なら、日時を読み取れなかったときの帯（色を変え、読み上げにも知らせる）。
	 * @param string $list_url 日時を読み取れなかったときに、文言が案内する移動先（スライドの一覧）へのリンク先。権限のある人にだけ渡す。空ならリンクを出さない。
	 * @return string エスケープ済みの HTML。
	 */
	public static function banner_html( $text, $exit_url, $at_top = true, $is_error = false, $list_url = '' ) {
		// テーマの色に左右されないよう、背景と文字の色を両方とも指定する。失敗の帯は赤系にして、プレビュー中の帯と見分ける。
		$background = $is_error ? '#fcf0f1' : '#fcf9e8';
		$border     = $is_error ? '#d63638' : '#dba617';
		$style      = 'margin:0;padding:8px 16px;background:' . $background . ';color:#1d2327;border-bottom:1px solid ' . $border . ';font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;text-align:left;';
		if ( ! $at_top ) {
			$style .= 'position:fixed;left:0;right:0;bottom:0;z-index:99999;border-top:1px solid ' . $border . ';border-bottom:0;';
		}

		// リンクの高さは 24px 以上にする（WCAG 2.2 の最小のタップ範囲）。
		$link_style = 'color:#1d2327;text-decoration:underline;display:inline-flex;align-items:center;min-height:24px;margin-right:8px;';

		$list_link = ( $is_error && '' !== $list_url )
			? ' <a href="' . esc_url( $list_url ) . '" style="' . esc_attr( $link_style ) . '">スライドの一覧を開く</a>'
			: '';

		return '<div class="etss-preview-banner" ' . ( $is_error ? 'role="alert"' : 'role="region" aria-label="日時プレビュー"' ) . ' style="' . esc_attr( $style ) . '">'
			. '<strong>' . esc_html( $text ) . '</strong>'
			. $list_link
			. ' <a href="' . esc_url( $exit_url ) . '" style="' . esc_attr( $link_style ) . '">プレビューを終了</a>'
			. '</div>';
	}

	/**
	 * 帯を本文の先頭に出す（`wp_body_open` の入口）。
	 *
	 * @return void
	 */
	public static function render_banner() {
		self::print_banner( true );
	}

	/**
	 * テーマが `wp_body_open` を呼ばず、帯を出せなかったときに、画面の下に固定して出す（`wp_footer` の入口）。
	 *
	 * @return void
	 */
	public static function render_banner_fallback() {
		self::print_banner( false );
	}

	/**
	 * 帯を出力する。プレビューしていないとき・すでに出したときは何もしない。
	 *
	 * @param bool $at_top 本文の先頭に置くか。
	 * @return void
	 */
	private static function print_banner( $at_top ) {
		if ( self::$banner_printed || ( null === self::$at && ! self::$invalid ) ) {
			return;
		}
		self::$banner_printed = true;

		$html = self::banner_html(
			self::banner_text( self::$at, time(), wp_timezone() ),
			remove_query_arg( self::QUERY_VAR ),
			$at_top,
			null === self::$at,
			// 案内する移動先は、権限のある人にだけ出す。
			self::current_user_can_preview() ? admin_url( 'edit.php?post_type=' . ETSS_Post_Types::SLIDE ) : ''
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- banner_html() 内でエスケープ済み。
	}

	/**
	 * スライドの編集画面の表示期間の下に、「この時刻の表示を見る」リンクを出す（`etss_after_schedule_fields` の入口）。
	 *
	 * 保存済みの表示開始・表示終了の日時で、スライダーを置いているページを開く。
	 *
	 * @param WP_Post $post スライドの投稿。
	 * @return void
	 */
	public static function render_slide_links( $post ) {
		$saved = ETSS_Slide_Admin::get_saved( $post->ID );
		if ( $saved['slider_id'] <= 0 || ( $saved['start'] <= 0 && $saved['end'] <= 0 ) ) {
			return;
		}

		$timezone = wp_timezone();
		$now      = time();
		$page_url = ETSS_Slider_Admin::placement_url( $saved['slider_id'] );

		echo '<div class="etss-preview-links">';

		// 置いているページが無いときは、見る先が無いので、置き方の案内に誘導する。
		if ( '' === $page_url ) {
			$slider_link = get_edit_post_link( $saved['slider_id'] );
			echo '<p class="description">この時刻の表示を見るには、公開しているページにこのスライダーを置いてください。';
			if ( $slider_link ) {
				echo ' <a href="' . esc_url( $slider_link ) . '">スライダーの設定</a>で、置き方を確かめられます。';
			}
			echo '</p></div>';
			return;
		}

		echo '<ul class="etss-preview-links__list">';
		$periods = array(
			'表示開始' => $saved['start'],
			'表示終了' => $saved['end'],
		);
		foreach ( $periods as $label => $timestamp ) {
			if ( $timestamp <= 0 ) {
				continue;
			}
			$time_text = ETSS_Display::format_list_datetime( $timestamp, $now, $timezone );
			$text      = '表示終了' === $label
				? '表示終了 ' . $time_text . ' に消えた後の並びを見る'
				: 'この時刻の表示を見る（表示開始 ' . $time_text . '）';
			printf(
				'<li><a href="%1$s" target="_blank" rel="noopener" aria-label="%2$s">%3$s</a></li>',
				esc_url( self::build_url( $page_url, $timestamp, $timezone ) ),
				esc_attr( $text . '（新しいタブで開きます）' ),
				esc_html( $text )
			);
		}
		echo '</ul>';
		echo '<p class="description">保存した日時で、スライダーを置いているページを新しいタブで開きます。下書きのスライドも並びに含まれます。入力を変えたときは、保存してから開いてください。</p>';
		echo '</div>';
	}

	/**
	 * スライドの一覧の上の要約の行に、「この時刻の表示を見る」リンクを出す（`etss_summary_row_actions` の入口）。
	 *
	 * 次の切り替え時刻の並びを、スライダーを置いているページで開く。
	 *
	 * @param int      $slider_id   スライダーの投稿 ID。
	 * @param int|null $next_switch 次の切り替え時刻（UTC の Unix 秒）。無ければ null。
	 * @return void
	 */
	public static function render_summary_link( $slider_id, $next_switch ) {
		if ( null === $next_switch ) {
			return;
		}

		$page_url = ETSS_Slider_Admin::placement_url( (int) $slider_id );
		if ( '' === $page_url ) {
			echo '<span class="description">公開しているページに置くと、切り替え後の表示を確かめられます。</span>';
			return;
		}

		$timezone = wp_timezone();
		printf(
			'<a href="%1$s" target="_blank" rel="noopener" aria-label="%2$s">切り替え後の表示を見る</a>',
			esc_url( self::build_url( $page_url, (int) $next_switch, $timezone ) ),
			esc_attr( '切り替え後の表示を見る（次の切り替え ' . ETSS_Display::format_list_datetime( (int) $next_switch, time(), $timezone ) . '。新しいタブで開きます）' )
		);
	}
}
