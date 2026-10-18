<?php
/**
 * スライドの編集画面（§4.3）と、保存時の検査・状態の決定（§4.4）。
 *
 * 検査と状態の決め方は、純粋な関数（ETSS_Validator・ETSS_Display・ETSS_Schedule）に任せる。
 * このクラスは、`$_POST`・`time()`・投稿やメタの読み書きといった WordPress に触れる部分（フックの入口）だけを持つ。
 * `validate_slide()` の結果は、1回の保存のあいだ `$entries` に持ち、使い回す。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * スライドの編集画面と保存をまとめたクラス。
 */
class ETSS_Slide_Admin {

	/**
	 * nonce のアクション名（投稿 ID を後ろに付ける）。
	 */
	const NONCE_ACTION = 'etss_save_slide_';

	/**
	 * nonce のフィールド名。
	 */
	const NONCE_NAME = 'etss_slide_nonce';

	/**
	 * 編集画面のフィールド名（`etss[...]`）。
	 */
	const FIELD = 'etss';

	/**
	 * 保存後の画面に、エラー・警告を出すことを伝えるクエリ引数。
	 */
	const NOTICE_ARG = 'etss_notice';

	/**
	 * 1回の保存で使い回す検査の結果（投稿 ID => 結果）。
	 *
	 * @var array
	 */
	private static $entries = array();

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// 編集画面。
		add_action( 'add_meta_boxes_' . ETSS_Post_Types::SLIDE, array( __CLASS__, 'add_meta_boxes' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'render_title_error' ) );
		add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'render_submitbox_line' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'post_updated_messages', array( __CLASS__, 'post_updated_messages' ) );
		add_filter( 'title_edit_pre', array( __CLASS__, 'restore_rejected_title' ), 10, 2 );

		// 保存。
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'filter_insert_post_data' ), 99, 2 );
		add_action( 'save_post_' . ETSS_Post_Types::SLIDE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'filter_redirect_location' ), 10, 2 );

		// 検査を通らない経路を塞ぐ。
		add_filter( 'post_row_actions', array( __CLASS__, 'remove_quick_edit' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . ETSS_Post_Types::SLIDE, array( __CLASS__, 'remove_bulk_edit' ) );
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
	 * いま開いている画面が、スライドの編集画面（追加を含む）かを返す。
	 *
	 * @return bool スライドの編集画面なら true。
	 */
	private static function is_slide_edit_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && ETSS_Post_Types::SLIDE === $screen->post_type && 'post' === $screen->base;
	}

	/**
	 * 編集画面に入力欄のまとまり（メタボックス）を足す。
	 *
	 * @return void
	 */
	public static function add_meta_boxes() {
		add_meta_box( 'etss_slide_content', 'スライドの内容', array( __CLASS__, 'render_content_box' ), ETSS_Post_Types::SLIDE, 'normal', 'high' );
		add_meta_box( 'etss_slide_schedule', '表示期間', array( __CLASS__, 'render_schedule_box' ), ETSS_Post_Types::SLIDE, 'normal', 'high' );

		// WordPress の「公開」は、表示の登録とは別の言葉にする。
		add_filter( 'gettext', array( __CLASS__, 'relabel_publish_button' ), 10, 3 );
	}

	/**
	 * 公開ボックスの「公開」「公開済み」を、スライド用の言葉にする。
	 *
	 * @param string $translation 翻訳後の文字列。
	 * @param string $text        翻訳前の文字列。
	 * @param string $domain      テキストドメイン。
	 * @return string 文字列。
	 */
	public static function relabel_publish_button( $translation, $text, $domain ) {
		if ( 'default' !== $domain ) {
			return $translation;
		}

		$labels = array(
			'Publish'   => '表示に登録',
			'Published' => '表示に登録済み',
		);

		return isset( $labels[ $text ] ) ? $labels[ $text ] : $translation;
	}

	/**
	 * タイトル欄の案内文を、スライド用にする。
	 *
	 * @param string  $text 既定の案内文。
	 * @param WP_Post $post 投稿。
	 * @return string 案内文。
	 */
	public static function title_placeholder( $text, $post ) {
		return ( $post && ETSS_Post_Types::SLIDE === $post->post_type ) ? '代替テキスト（画像の告知文）を入力' : $text;
	}

	/**
	 * 変更を保存しなかった直後の画面（D7）で、タイトル欄に、入力されていたタイトルを戻す。
	 *
	 * @param string $title   編集画面に出すタイトル。
	 * @param int    $post_id 投稿 ID。
	 * @return string タイトル。
	 */
	public static function restore_rejected_title( $title, $post_id ) {
		if ( ! self::is_slide_edit_screen() ) {
			return $title;
		}

		$last = self::read_last_errors( (int) $post_id );

		return ( $last['protected'] && isset( $last['input']['title'] ) && is_string( $last['input']['title'] ) ) ? $last['input']['title'] : $title;
	}

	/**
	 * 保存済みの値を読む。
	 *
	 * @param int $post_id スライドの投稿 ID。
	 * @return array `slider_id`・`images`・`url`・`start`・`end`。
	 */
	private static function get_saved( $post_id ) {
		$images = get_post_meta( $post_id, ETSS_Meta::IMAGES, true );
		$url    = get_post_meta( $post_id, ETSS_Meta::URL, true );

		return array(
			'slider_id' => (int) get_post_meta( $post_id, ETSS_Meta::SLIDER_ID, true ),
			'images'    => is_array( $images ) ? $images : array(),
			'url'       => is_string( $url ) ? $url : '',
			'start'     => (int) get_post_meta( $post_id, ETSS_Meta::START, true ),
			'end'       => (int) get_post_meta( $post_id, ETSS_Meta::END, true ),
		);
	}

	/**
	 * 添付ファイルが画像なら、その寸法を返す（検査に渡す関数）。
	 *
	 * @param int $attachment_id 添付ファイルの ID。
	 * @return array|null `width`・`height`。画像でない・無いときは null。
	 */
	public static function attachment_info( $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );

		return array(
			'width'  => ( is_array( $meta ) && isset( $meta['width'] ) ) ? (int) $meta['width'] : 0,
			'height' => ( is_array( $meta ) && isset( $meta['height'] ) ) ? (int) $meta['height'] : 0,
		);
	}

	/**
	 * 検査に渡す外の情報を作る。
	 *
	 * @param array $input ETSS_Validator::normalize_input() の結果。
	 * @return array ETSS_Validator::validate_slide() の `$context`。
	 */
	private static function build_context( array $input ) {
		$slider_id = ETSS_Validator::parse_id( $input['slider_id'] );
		$found     = $slider_id > 0 && ETSS_Post_Types::SLIDER === get_post_type( $slider_id ) && 'trash' !== get_post_status( $slider_id );

		return array(
			'slider_found'    => $found,
			'template'        => $found ? ETSS_Templates::for_slider( $slider_id ) : null,
			'attachment_info' => array( __CLASS__, 'attachment_info' ),
			'timezone'        => wp_timezone(),
		);
	}

	/**
	 * 送信値を読む（`$_POST['etss']`）。
	 *
	 * @return array ETSS_Validator::normalize_input() の結果。
	 */
	private static function read_request_input() {
		// nonce は has_valid_nonce() で確かめたあとにだけ呼ぶ。
		$raw = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 型は normalize_input() でそろえる。

		return ETSS_Validator::normalize_input( $raw );
	}

	/**
	 * 編集画面から正しく送信されたか（nonce が合うか）を返す。
	 *
	 * @param int $post_id スライドの投稿 ID。
	 * @return bool 合えば true。
	 */
	private static function has_valid_nonce( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! is_string( $_POST[ self::NONCE_NAME ] ) ) {
			return false;
		}

		return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION . (int) $post_id );
	}

	/**
	 * 1回の保存の検査結果を作る。同じ保存の中では、作り直さずに使い回す。
	 *
	 * 編集画面から来た（nonce が合う）ときは送信値で、来ていないとき（一括編集・REST・プログラムからの保存など）は保存済みの値で検査する。
	 *
	 * @param int    $post_id          スライドの投稿 ID（新規で未確定なら 0）。
	 * @param string $title            保存しようとしているタイトル。
	 * @param string $requested_status 保存しようとしている投稿ステータス。
	 * @param array  $meta_input       wp_insert_post() に渡されたメタ（`meta_input`）。
	 * @return array `result`（validate_slide の結果）・`decision`・`from_form`・`input`・`title`・`snapshot`（保存前のメタ）。
	 */
	private static function get_entry( $post_id, $title, $requested_status, array $meta_input = array() ) {
		$post_id = (int) $post_id;
		if ( isset( self::$entries[ $post_id ] ) ) {
			return self::$entries[ $post_id ];
		}

		$timezone  = wp_timezone();
		$now       = self::now();
		$from_form = self::has_valid_nonce( $post_id );

		if ( $from_form ) {
			$input = self::read_request_input();
		} else {
			// 送信値が無いので、保存済みの値（と、同時に渡されたメタ）で検査する。
			$saved  = self::get_saved( $post_id );
			$keymap = array(
				ETSS_Meta::SLIDER_ID => 'slider_id',
				ETSS_Meta::IMAGES    => 'images',
				ETSS_Meta::URL       => 'url',
				ETSS_Meta::START     => 'start',
				ETSS_Meta::END       => 'end',
			);
			foreach ( $keymap as $meta_key => $name ) {
				if ( array_key_exists( $meta_key, $meta_input ) ) {
					$saved[ $name ] = $meta_input[ $meta_key ];
				}
			}
			$input = ETSS_Validator::saved_to_input( $saved, $timezone );
		}

		$result = ETSS_Validator::validate_slide( $title, $input, self::build_context( $input ) );

		// 保存済みの値で、いま表示中か（D7）。保存前なので、DB はまだ古い状態のまま。
		$stored       = self::get_saved( $post_id );
		$saved_active = $post_id > 0 && ETSS_Display::STATUS_ACTIVE === ETSS_Display::status( (string) get_post_status( $post_id ), $stored['start'], $stored['end'], $now );
		$decision     = ETSS_Validator::decide( $requested_status, ! empty( $result['errors'] ), $saved_active );
		$entry        = array(
			'result'    => $result,
			'decision'  => $decision,
			'from_form' => $from_form,
			'input'     => $input,
			'title'     => (string) $title,
			'snapshot'  => self::snapshot_meta( $post_id ),
		);

		self::$entries[ $post_id ] = $entry;

		return $entry;
	}

	/**
	 * 保存する直前に、投稿ステータスを検査の結果に合わせる。
	 *
	 * - `future` は `publish` に直す（予約投稿は WP-Cron に戻ってしまうため使わない）
	 * - 検査に通らなければ、表示中のスライドは変更を保存せず（状態と古い値を保つ）、それ以外は下書きに落とす
	 *
	 * @param array $data    保存する投稿データ。
	 * @param array $postarr 渡された投稿データ（`ID` など）。
	 * @return array 保存する投稿データ。
	 */
	public static function filter_insert_post_data( $data, $postarr ) {
		if ( ! isset( $data['post_type'] ) || ETSS_Post_Types::SLIDE !== $data['post_type'] ) {
			return $data;
		}

		// 公開状態の「非公開」・パスワードは使わない（検査を通らずに公開できる経路になるため）。
		$data = self::normalize_visibility( $data );

		// 公開で保存しようとしているときだけ見る（下書き・ゴミ箱などは、そのまま通す）。
		$requested = isset( $data['post_status'] ) ? $data['post_status'] : '';
		if ( ! in_array( $requested, array( 'publish', 'future' ), true ) ) {
			return $data;
		}

		$post_id    = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$meta_input = ( isset( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ) ? $postarr['meta_input'] : array();
		$title      = isset( $data['post_title'] ) ? $data['post_title'] : '';
		$entry      = self::get_entry( $post_id, $title, $requested, $meta_input );
		$current    = ( 'reject' === $entry['decision'] ) ? get_post( $post_id ) : null;

		return self::apply_decision( $data, $requested, $entry['decision'], $current, current_time( 'mysql' ), current_time( 'mysql', true ) );
	}

	/**
	 * 公開状態の「非公開（private）」とパスワード保護を外す。公開状態は「公開」だけを使うため。
	 *
	 * @param array $data 保存する投稿データ。
	 * @return array 保存する投稿データ。
	 */
	public static function normalize_visibility( array $data ) {
		if ( isset( $data['post_status'] ) && 'private' === $data['post_status'] ) {
			$data['post_status'] = 'publish';
		}
		$data['post_password'] = '';

		return $data;
	}

	/**
	 * 検査の結果に合わせて、保存する投稿データを直す。
	 *
	 * - `publish`: `future` は `publish` にし、投稿日時を今にする
	 * - `draft`: 下書きに落とす
	 * - `reject`: 変更を保存しない。タイトル・スラッグ・表示順・状態を、保存済みのものに戻す
	 *
	 * reject で戻す項目は、supports に項目を足したとき（本文・抜粋など）に、ここへ足す必要がある。
	 *
	 * @param array         $data      保存する投稿データ。
	 * @param string        $requested 保存しようとしていた投稿ステータス。
	 * @param string        $decision  ETSS_Validator::decide() の結果。
	 * @param object|null   $current   保存済みの投稿（reject のときに戻す元）。
	 * @param string        $now_local 現在時刻（サイトの時計）。
	 * @param string        $now_gmt   現在時刻（GMT）。
	 * @return array 保存する投稿データ。
	 */
	public static function apply_decision( array $data, $requested, $decision, $current, $now_local, $now_gmt ) {
		switch ( $decision ) {
			case 'publish':
				if ( 'future' === $requested ) {
					$data['post_status']   = 'publish';
					$data['post_date']     = $now_local;
					$data['post_date_gmt'] = $now_gmt;
				}
				break;

			case 'draft':
				$data['post_status'] = 'draft';
				break;

			case 'reject':
				if ( $current ) {
					$data['post_title']  = $current->post_title;
					$data['post_name']   = $current->post_name;
					$data['menu_order']  = $current->menu_order;
					$data['post_status'] = $current->post_status;
				}
				break;
		}

		return $data;
	}

	/**
	 * スライドの値を保存する。
	 *
	 * 編集画面から来たときだけ保存する。検査の結果が `reject`（表示中のスライドの変更）なら、値は保存しない。
	 * エラー・警告は、保存後の画面に出すために一時的に残す（前回のものは、成功したら消す）。
	 *
	 * @param int     $post_id スライドの投稿 ID。
	 * @param WP_Post $post    投稿。
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// 保存の直前に作った結果があれば使い回す。0 の枠は、新規で ID が未確定だったときのもの。
		// 1回の保存で1件の投稿だけを作る前提（一括で複数を作る経路は想定しない）。複数を作るなら ID ごとの枠に直すこと。
		if ( isset( self::$entries[ $post_id ] ) ) {
			$entry = self::$entries[ $post_id ];
		} elseif ( isset( self::$entries[0] ) ) {
			$entry = self::$entries[0];
		} else {
			$entry = self::get_entry( $post_id, $post->post_title, $post->post_status );
		}
		unset( self::$entries[ $post_id ], self::$entries[0] );

		// 編集画面を通っていない保存は、値を書き換えない。下書きに落とした・保存を断ったときの理由だけ残す。
		if ( ! $entry['from_form'] ) {
			if ( 'reject' === $entry['decision'] ) {
				// meta_input は save_post より前に書かれるので、保存済みの値に戻す。
				self::restore_meta( $post_id, $entry['snapshot'] );
			}
			if ( in_array( $entry['decision'], array( 'draft', 'reject' ), true ) ) {
				self::store_last_errors( $post_id, $entry );
			}
			return;
		}

		if ( 'reject' === $entry['decision'] ) {
			self::restore_meta( $post_id, $entry['snapshot'] );
			self::store_last_errors( $post_id, $entry );
			return;
		}

		$values = $entry['result']['values'];

		// 値を保存する。空のものは、メタを残さない。
		self::update_or_delete( $post_id, ETSS_Meta::SLIDER_ID, $values['slider_id'] > 0 ? $values['slider_id'] : null );
		update_post_meta( $post_id, ETSS_Meta::IMAGES, $values['images'] );
		self::update_or_delete( $post_id, ETSS_Meta::URL, '' !== $values['url'] ? $values['url'] : null );
		update_post_meta( $post_id, ETSS_Meta::START, $values['start'] );
		update_post_meta( $post_id, ETSS_Meta::END, $values['end'] );

		self::store_last_errors( $post_id, $entry );
	}

	/**
	 * スライドの値のメタを、いまの状態で控える（reject のときに戻すため）。
	 *
	 * @param int $post_id 投稿 ID。
	 * @return array メタのキー => 値の配列（無ければ空配列）。
	 */
	private static function snapshot_meta( $post_id ) {
		$snapshot = array();
		foreach ( array( ETSS_Meta::SLIDER_ID, ETSS_Meta::IMAGES, ETSS_Meta::URL, ETSS_Meta::START, ETSS_Meta::END ) as $key ) {
			$snapshot[ $key ] = $post_id > 0 ? get_post_meta( $post_id, $key, false ) : array();
		}

		return $snapshot;
	}

	/**
	 * 控えておいたメタに戻す（無かったものは消す）。
	 *
	 * @param int   $post_id  投稿 ID。
	 * @param array $snapshot snapshot_meta() の結果。
	 * @return void
	 */
	private static function restore_meta( $post_id, array $snapshot ) {
		foreach ( $snapshot as $key => $values ) {
			self::update_or_delete( $post_id, $key, $values ? $values[0] : null );
		}
	}

	/**
	 * テスト用: 1回の保存で使い回す結果を空にする。
	 *
	 * @return void
	 */
	public static function reset_entries() {
		self::$entries = array();
	}

	/**
	 * 値があれば保存し、無ければメタを消す。
	 *
	 * @param int        $post_id 投稿 ID。
	 * @param string     $key     メタのキー。
	 * @param mixed|null $value   値。null なら消す。
	 * @return void
	 */
	private static function update_or_delete( $post_id, $key, $value ) {
		if ( null === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}

		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * 前回の保存で出したエラー・警告を、保存後の画面に出すために残す。無ければ消す。
	 *
	 * 下書きとして保存しただけ（検査には通らなくてよい）のときは、エラーは出さず、警告だけ残す。
	 *
	 * @param int   $post_id 投稿 ID。
	 * @param array $entry   get_entry() の結果。
	 * @return void
	 */
	private static function store_last_errors( $post_id, array $entry ) {
		$show_errors = 'keep' !== $entry['decision'] && 'publish' !== $entry['decision'];
		$errors      = $show_errors ? $entry['result']['errors'] : array();
		$warnings    = ( 'reject' === $entry['decision'] ) ? array() : $entry['result']['warnings'];

		if ( ! $errors && ! $warnings ) {
			delete_post_meta( $post_id, ETSS_Meta::LAST_ERRORS );
			return;
		}

		$stored = array(
			'errors'    => $errors,
			'warnings'  => $warnings,
			'protected' => 'reject' === $entry['decision'],
		);

		// 下書きに落としたとき・変更を保存しなかったときは、画面に入力を戻せるよう送信値も残す（打った値を失わないため）。
		if ( $errors && $entry['from_form'] ) {
			$stored['input']          = $entry['input'];
			$stored['input']['title'] = $entry['title'];
		}

		update_post_meta( $post_id, ETSS_Meta::LAST_ERRORS, $stored );
	}

	/**
	 * 保存後の画面の移動先を直す。
	 *
	 * 下書きに落としたとき・変更を保存しなかったときに、WordPress の緑の「公開しました」を出さない。
	 * 代わりに、エラー・警告を出すための引数を付ける。
	 *
	 * @param string $location 移動先の URL。
	 * @param int    $post_id  投稿 ID。
	 * @return string 移動先の URL。
	 */
	public static function filter_redirect_location( $location, $post_id ) {
		if ( ETSS_Post_Types::SLIDE !== get_post_type( $post_id ) ) {
			return $location;
		}

		$last = get_post_meta( $post_id, ETSS_Meta::LAST_ERRORS, true );
		if ( ! is_array( $last ) || ( empty( $last['errors'] ) && empty( $last['warnings'] ) ) ) {
			return $location;
		}

		// 保存に失敗したときは、成功のメッセージを外す。
		if ( ! empty( $last['errors'] ) ) {
			$location = remove_query_arg( 'message', $location );
		}

		return add_query_arg( self::NOTICE_ARG, '1', $location );
	}

	/**
	 * 保存後のメッセージを、スライド用の言葉にする（「公開」を使わない）。
	 *
	 * @param array $messages 投稿タイプごとのメッセージ。
	 * @return array メッセージ。
	 */
	public static function post_updated_messages( $messages ) {
		$messages[ ETSS_Post_Types::SLIDE ] = array(
			0  => '',
			1  => 'スライドを更新しました。',
			2  => 'カスタムフィールドを更新しました。',
			3  => 'カスタムフィールドを削除しました。',
			4  => 'スライドを更新しました。',
			5  => 'スライドを以前の版に戻しました。',
			6  => '表示に登録しました。表示開始日時から、スライダーに表示されます。',
			7  => 'スライドを保存しました。',
			8  => 'スライドを保存しました。',
			9  => 'スライドを保存しました。',
			10 => 'スライドの下書きを保存しました。',
		);

		return $messages;
	}

	/**
	 * クイック編集を、行のアクションから外す（検査を通らずに内容・状態を変えられるため）。
	 *
	 * @param array   $actions 行のアクション。
	 * @param WP_Post $post    投稿。
	 * @return array 行のアクション。
	 */
	public static function remove_quick_edit( $actions, $post ) {
		if ( $post && ETSS_Post_Types::SLIDE === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}

		return $actions;
	}

	/**
	 * 一括操作から「編集」（状態の変更を含む）を外す。
	 *
	 * @param array $actions 一括操作。
	 * @return array 一括操作。
	 */
	public static function remove_bulk_edit( $actions ) {
		unset( $actions['edit'] );

		return $actions;
	}

	/**
	 * 保存後の画面に出すエラー・警告を読む。
	 *
	 * @param int $post_id 投稿 ID。
	 * @return array `errors`・`warnings`・`protected`・`input`（無ければ空）。
	 */
	private static function read_last_errors( $post_id ) {
		$empty = array(
			'errors'    => array(),
			'warnings'  => array(),
			'protected' => false,
			'input'     => array(),
		);

		// 保存した直後の画面（引数つき）にだけ出す。表示するだけで、何も変更しない。
		if ( ! isset( $_GET[ self::NOTICE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $empty;
		}

		$last = get_post_meta( $post_id, ETSS_Meta::LAST_ERRORS, true );

		return is_array( $last ) ? array_merge( $empty, $last ) : $empty;
	}

	/**
	 * 欄のキーから、入力欄の id を返す。
	 *
	 * @param string $key 欄のキー（`title`・`slider`・`image-<枠 ID>`・`url`・`start`・`end`）。
	 * @return string id。
	 */
	private static function field_id( $key ) {
		return 'title' === $key ? 'title' : 'etss-field-' . $key;
	}

	/**
	 * 欄に付ける、説明文とエラー文を結ぶ属性（aria-describedby・aria-invalid）を返す。
	 *
	 * @param string $key    欄のキー。
	 * @param array  $errors エラー（欄のキー => 文）。
	 * @return string 属性（先頭に空白つき）。
	 */
	private static function field_attrs( $key, array $errors ) {
		$attrs = ' aria-describedby="etss-desc-' . esc_attr( $key ) . ' etss-error-' . esc_attr( $key ) . '"';

		// 画像枠のボタンには aria-invalid を付けられない（無効な ARIA）。赤枠は is-invalid クラスで出す。
		if ( 0 === strpos( $key, 'image-' ) ) {
			return isset( $errors[ $key ] ) ? $attrs . ' class="button etss-slot__select is-invalid"' : $attrs . ' class="button etss-slot__select"';
		}

		return isset( $errors[ $key ] ) ? $attrs . ' aria-invalid="true"' : $attrs;
	}

	/**
	 * 欄のエラー文を出す（空でも、JS が使えるよう要素は出す）。
	 *
	 * @param string $key    欄のキー。
	 * @param array  $errors エラー（欄のキー => 文）。
	 * @return void
	 */
	private static function render_field_error( $key, array $errors ) {
		printf(
			'<p class="etss-field-error" id="etss-error-%1$s" data-field="%2$s"%3$s>%4$s</p>',
			esc_attr( $key ),
			esc_attr( self::field_id( $key ) ),
			isset( $errors[ $key ] ) ? '' : ' hidden',
			isset( $errors[ $key ] ) ? esc_html( $errors[ $key ] ) : ''
		);
	}

	/**
	 * 編集画面に出す値を決める。保存済みの値。ただし、保存に失敗した直後は、送信された値を戻す。
	 *
	 * @param WP_Post $post  投稿。
	 * @param array   $last  read_last_errors() の結果。
	 * @return array `slider_id`（int）・`images`・`url`・`start`・`end`（datetime-local の値）。
	 */
	private static function form_values( $post, array $last ) {
		$saved    = self::get_saved( $post->ID );
		$timezone = wp_timezone();
		$values   = array(
			'slider_id' => $saved['slider_id'],
			'images'    => $saved['images'],
			'url'       => $saved['url'],
			'start'     => ETSS_Schedule::format_datetime_local( $saved['start'], $timezone ),
			'end'       => ETSS_Schedule::format_datetime_local( $saved['end'], $timezone ),
		);

		if ( ! empty( $last['input'] ) && is_array( $last['input'] ) ) {
			$input               = $last['input'];
			$values['slider_id'] = isset( $input['slider_id'] ) ? ETSS_Validator::parse_id( $input['slider_id'] ) : $values['slider_id'];
			$values['images']    = ( isset( $input['images'] ) && is_array( $input['images'] ) ) ? $input['images'] : $values['images'];
			$values['url']       = isset( $input['url'] ) ? (string) $input['url'] : $values['url'];
			$values['start']     = isset( $input['start'] ) ? (string) $input['start'] : $values['start'];
			$values['end']       = isset( $input['end'] ) ? (string) $input['end'] : $values['end'];
		}

		// 新しく作るときは、選ぶ手間を減らす（URL で指定されたスライダー。無ければ、スライダーが1つだけならそれ）。
		if ( 0 === $values['slider_id'] ) {
			$requested = isset( $_GET['etss_slider'] ) ? ETSS_Validator::parse_id( sanitize_text_field( wp_unslash( $_GET['etss_slider'] ) ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sliders   = self::slider_choices();
			if ( $requested > 0 && isset( $sliders[ $requested ] ) ) {
				$values['slider_id'] = $requested;
			} elseif ( 1 === count( $sliders ) ) {
				$values['slider_id'] = (int) key( $sliders );
			}
		}

		return $values;
	}

	/**
	 * 選べるスライダーの一覧を返す。
	 *
	 * @return array スライダーの投稿 ID => 名前。
	 */
	private static function slider_choices() {
		$posts = get_posts(
			array(
				'post_type'        => ETSS_Post_Types::SLIDER,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		$choices = array();
		foreach ( $posts as $slider ) {
			$template = ETSS_Templates::for_slider( $slider->ID );
			$name     = '' !== trim( $slider->post_title ) ? $slider->post_title : '（名前なし）';

			// 同じ名前のスライダーを見分けられるよう、ひな形名と ID を添える。
			$choices[ (int) $slider->ID ] = $name . '（' . ( $template ? $template['label'] : 'ひな形不明' ) . '・#' . $slider->ID . '）';
		}

		return $choices;
	}

	/**
	 * 「スライドの内容」の入力欄（スライダー・画像枠・リンク先）を出す。
	 *
	 * @param WP_Post $post 投稿。
	 * @return void
	 */
	public static function render_content_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION . $post->ID, self::NONCE_NAME );

		$last     = self::read_last_errors( $post->ID );
		$errors   = $last['errors'];
		$warnings = $last['warnings'];
		$values   = self::form_values( $post, $last );
		$sliders  = self::slider_choices();
		$template = $values['slider_id'] > 0 ? ETSS_Templates::for_slider( $values['slider_id'] ) : null;

		// スライダー。
		echo '<div class="etss-field">';
		echo '<label for="etss-field-slider" class="etss-label">スライダー <span class="etss-required">必須</span></label>';
		printf(
			'<select id="etss-field-slider" name="%1$s[slider_id]" data-initial="%2$d"%3$s>',
			esc_attr( self::FIELD ),
			(int) $values['slider_id'],
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_attrs() 内でエスケープ済み。
			self::field_attrs( 'slider', $errors )
		);
		echo '<option value="">選択してください</option>';
		foreach ( $sliders as $slider_id => $slider_name ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $slider_id, selected( $values['slider_id'], $slider_id, false ), esc_html( $slider_name ) );
		}
		echo '</select>';
		echo '<p class="description" id="etss-desc-slider">';
		if ( $sliders ) {
			echo 'このスライドを表示するスライダーです。スライダーによって、選ぶ画像の枠が変わります。';
		} else {
			printf(
				'スライダーがまだありません。先に<a href="%s">スライダーを作成</a>してください。',
				esc_url( admin_url( 'post-new.php?post_type=' . ETSS_Post_Types::SLIDER ) )
			);
		}
		echo '</p>';
		echo '<p class="etss-slider-change-note" id="etss-slider-change-note" role="status" hidden>スライダーを選び直しました。「下書き保存」すると画像枠が変わります。画像は保存後の画面で選んでください。</p>';
		self::render_field_error( 'slider', $errors );
		echo '</div>';

		// 画像枠（保存済みのスライダーのひな形から作る）。
		if ( null !== $template ) {
			foreach ( $template['slots'] as $slot ) {
				self::render_slot( $slot, $values['images'], $errors, $warnings );
			}
		} elseif ( $values['slider_id'] > 0 ) {
			echo '<p class="description">このスライダーのひな形が見つかりません。ひな形を登録しているプラグインが有効か確認してください。</p>';
		} else {
			echo '<p class="description">スライダーを選んで「下書き保存」すると、画像を選ぶ欄が出ます。</p>';
		}

		// リンク先。
		$saved_url = ( '' !== $values['url'] && ETSS_Link::is_valid( $values['url'] ) ) ? $values['url'] : '';
		echo '<div class="etss-field">';
		echo '<label for="etss-field-url" class="etss-label">リンク先 <span class="etss-required">必須</span></label>';
		printf(
			'<input type="text" class="large-text" id="etss-field-url" name="%1$s[url]" value="%2$s" spellcheck="false" autocomplete="off" inputmode="url"%3$s>',
			esc_attr( self::FIELD ),
			esc_attr( $values['url'] ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_attrs() 内でエスケープ済み。
			self::field_attrs( 'url', $errors )
		);
		echo '<p class="description" id="etss-desc-url">画像をクリックしたときに開くページです。サイト内のページは「/」から始めます（例: /news/campaign/）。外部のサイトは「https://」から始めます（例: https://example.com/）。</p>';
		if ( '' !== $saved_url && '' === ( isset( $errors['url'] ) ? $errors['url'] : '' ) ) {
			$href = ( '/' === $saved_url[0] ) ? home_url( $saved_url ) : $saved_url;
			printf( '<p class="etss-link-check"><a href="%s" target="_blank" rel="noopener noreferrer">開いて確かめる<span class="screen-reader-text">（新しいタブで開きます）</span></a></p>', esc_url( $href ) );
		}
		self::render_field_error( 'url', $errors );
		echo '</div>';
	}

	/**
	 * 画像枠1つ分の入力欄を出す。
	 *
	 * @param array $slot     画像枠の定義。
	 * @param array $images   画面に出す画像（枠 ID => 添付ファイル ID）。
	 * @param array $errors   エラー（欄のキー => 文）。
	 * @param array $warnings 警告（欄のキー => 文）。
	 * @return void
	 */
	private static function render_slot( array $slot, array $images, array $errors, array $warnings ) {
		$key           = 'image-' . $slot['id'];
		$attachment_id = isset( $images[ $slot['id'] ] ) ? (int) $images[ $slot['id'] ] : 0;
		$info          = $attachment_id > 0 ? self::attachment_view( $attachment_id ) : null;
		$name_select   = ETSS_Display::button_name( $slot['label'], '選ぶ' );
		$name_change   = ETSS_Display::button_name( $slot['label'], '変更する' );
		$name_remove   = ETSS_Display::button_name( $slot['label'], '外す' );

		printf(
			'<div class="etss-field etss-slot" data-slot="%1$s" data-required="%2$d" data-label-select="%3$s" data-label-change="%4$s" data-label-remove="%5$s" data-required-message="%6$s">',
			esc_attr( $slot['id'] ),
			$slot['required'] ? 1 : 0,
			esc_attr( $name_select ),
			esc_attr( $name_change ),
			esc_attr( $name_remove ),
			esc_attr( ETSS_Display::button_name( $slot['label'], '選択' ) . 'してください。' )
		);
		printf(
			'<span class="etss-label">%1$s%2$s</span>',
			esc_html( $slot['label'] ),
			$slot['required'] ? ' <span class="etss-required">必須</span>' : ''
		);
		if ( '' !== $slot['description'] ) {
			echo '<p class="description" id="etss-desc-' . esc_attr( $key ) . '">' . esc_html( $slot['description'] ) . '</p>';
		} else {
			echo '<p class="description" id="etss-desc-' . esc_attr( $key ) . '" hidden></p>';
		}

		// プレビューと、ファイル名・寸法。
		printf(
			'<div class="etss-slot__preview%1$s"%2$s><img src="%3$s" alt=""></div>',
			$slot['preview_dim'] ? ' etss-slot__preview--dim' : '',
			$info ? '' : ' hidden',
			$info ? esc_url( $info['url'] ) : ''
		);
		printf(
			'<p class="etss-slot__meta"%1$s><span class="etss-slot__filename">%2$s</span> <span class="etss-slot__size">%3$s</span></p>',
			$info ? '' : ' hidden',
			$info ? esc_html( $info['filename'] ) : '',
			$info ? esc_html( $info['size'] ) : ''
		);
		if ( $slot['preview_dim'] ) {
			echo '<p class="description etss-slot__dim-note">表示では、この画像の上に暗幕が重なります。プレビューも同じ暗さで表示しています。</p>';
		}
		if ( $attachment_id > 0 && ! $info ) {
			echo '<p class="etss-slot__missing">選んだ画像が見つかりません（メディアから削除された可能性があります）。選び直してください。</p>';
		}

		// 選ぶ・変更・外す。
		printf( '<input type="hidden" name="%1$s[images][%2$s]" value="%3$d" class="etss-slot__input">', esc_attr( self::FIELD ), esc_attr( $slot['id'] ), absint( $attachment_id ) );
		echo '<p class="screen-reader-text etss-slot__live" aria-live="polite"></p>';
		echo '<p class="etss-slot__buttons">';
		printf(
			'<button type="button" id="etss-field-%1$s"%2$s>%3$s</button> ',
			esc_attr( $key ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_attrs() 内でエスケープ済み。
			self::field_attrs( $key, $errors ),
			esc_html( $info ? $name_change : $name_select )
		);
		printf(
			'<button type="button" class="button-link etss-slot__remove"%1$s>%2$s</button>',
			$attachment_id > 0 ? '' : ' hidden',
			esc_html( $name_remove )
		);
		echo '</p>';

		if ( isset( $warnings[ $key ] ) ) {
			echo '<p class="etss-slot__warning">' . esc_html( $warnings[ $key ] ) . '</p>';
		}
		self::render_field_error( $key, $errors );
		echo '</div>';
	}

	/**
	 * 画像のプレビュー用の情報（URL・ファイル名・寸法）を返す。
	 *
	 * @param int $attachment_id 添付ファイルの ID。
	 * @return array|null `url`・`filename`・`size`。画像でなければ null。
	 */
	private static function attachment_view( $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'medium' );
		if ( ! $url ) {
			$url = wp_get_attachment_url( $attachment_id );
		}

		$file = get_attached_file( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );
		$size = ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) ? (int) $meta['width'] . ' × ' . (int) $meta['height'] . ' px' : '';

		return array(
			'url'      => (string) $url,
			'filename' => $file ? wp_basename( $file ) : '',
			'size'     => $size,
		);
	}

	/**
	 * 「表示期間」の入力欄（表示開始日時・表示終了日時）を出す。
	 *
	 * @param WP_Post $post 投稿。
	 * @return void
	 */
	public static function render_schedule_box( $post ) {
		$last     = self::read_last_errors( $post->ID );
		$errors   = $last['errors'];
		$values   = self::form_values( $post, $last );
		$timezone = wp_timezone();
		$now      = self::now();

		// 書き戻しの文言は、保存済みの値（失敗直後は戻した値）から作る。
		$start_ts = ETSS_Schedule::parse_datetime_local( $values['start'], $timezone );
		$end_ts   = ETSS_Schedule::parse_datetime_local( $values['end'], $timezone );

		echo '<div class="etss-field">';
		echo '<label for="etss-field-start" class="etss-label">表示開始日時 <span class="etss-required">必須</span></label>';
		echo '<div class="etss-datetime-row">';
		printf(
			'<input type="datetime-local" id="etss-field-start" name="%1$s[start]" value="%2$s" step="60"%3$s>',
			esc_attr( self::FIELD ),
			esc_attr( $values['start'] ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_attrs() 内でエスケープ済み。
			self::field_attrs( 'start', $errors ) . ' aria-required="true"'
		);
		echo ' <button type="button" class="button" id="etss-start-now">今すぐ表示する</button>';
		echo '</div>';
		printf( '<p class="etss-writeback" id="etss-start-writeback">%s</p><p class="screen-reader-text" id="etss-start-live" aria-live="polite"></p>', esc_html( null === $start_ts ? '' : ETSS_Display::describe_start( $start_ts, $now, $timezone ) ) );
		echo '<p class="description" id="etss-desc-start">この日時になると、スライダーに表示されます。「今すぐ表示する」を押すと、いまの日時を入力します。</p>';
		self::render_field_error( 'start', $errors );
		echo '</div>';

		echo '<div class="etss-field">';
		echo '<label for="etss-field-end" class="etss-label">表示終了日時</label>';
		echo '<div class="etss-datetime-row">';
		printf(
			'<input type="datetime-local" id="etss-field-end" name="%1$s[end]" value="%2$s" step="60"%3$s>',
			esc_attr( self::FIELD ),
			esc_attr( $values['end'] ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_attrs() 内でエスケープ済み。
			self::field_attrs( 'end', $errors )
		);
		echo ' <button type="button" class="button-link" id="etss-end-clear">表示終了日時を空にする</button>';
		echo '</div>';
		printf( '<p class="etss-writeback" id="etss-end-writeback">%s</p><p class="screen-reader-text" id="etss-end-live" aria-live="polite"></p>', esc_html( ETSS_Display::describe_end( null === $end_ts ? 0 : $end_ts, $now, $timezone ) ) );
		echo '<p class="description" id="etss-desc-end">この日時になると、表示から消えます。空欄なら消えません。</p>';
		self::render_field_error( 'end', $errors );
		echo '</div>';

		echo '<p class="description">日時は、サイトのタイムゾーン（' . esc_html( $timezone->getName() ) . '）の24時間制で入力します。</p>';

		/**
		 * 表示期間の欄の下に、「この時刻の表示を見る」リンクを足すための場所。
		 *
		 * @param WP_Post $post 投稿。
		 */
		do_action( 'etss_after_schedule_fields', $post );
	}

	/**
	 * タイトル欄の下に、タイトルのエラー文を出す。
	 *
	 * @param WP_Post $post 投稿。
	 * @return void
	 */
	public static function render_title_error( $post ) {
		if ( ! $post || ETSS_Post_Types::SLIDE !== $post->post_type ) {
			return;
		}

		$last = self::read_last_errors( $post->ID );
		echo '<div class="etss-title-error">';
		self::render_field_error( 'title', $last['errors'] );
		echo '</div>';
	}

	/**
	 * 公開ボックスに、「表示: 〜」の1行を足す。日時の編集欄の代わり。
	 *
	 * @param WP_Post $post 投稿。
	 * @return void
	 */
	public static function render_submitbox_line( $post ) {
		if ( ! $post || ETSS_Post_Types::SLIDE !== $post->post_type ) {
			return;
		}

		$saved = self::get_saved( $post->ID );
		$line  = ETSS_Display::submitbox_line( $post->post_status, $saved['start'], $saved['end'], self::now(), wp_timezone() );

		echo '<div class="misc-pub-section etss-misc-pub-status">表示: <strong>' . esc_html( $line ) . '</strong></div>';
	}

	/**
	 * 画面の上に、エラー・警告の一覧を出す。各項目は、その欄へのリンクにする。
	 *
	 * 送信前の JS 検査の結果を出すための、空の入れ物も出す。
	 *
	 * @return void
	 */
	public static function render_notices() {
		if ( ! self::is_slide_edit_screen() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$last = self::read_last_errors( $post->ID );

		if ( $last['errors'] ) {
			echo '<div class="notice notice-error etss-notice" role="alert"><p><strong>';
			echo $last['protected']
				? '変更は保存されていません。このスライドは、保存済みの内容のまま表示を続けています。次の項目を直して、もう一度保存してください。'
				: '保存できなかった項目があるため、このスライドは下書きにしました。次の項目を直すと、表示に登録できます。';
			echo '</strong></p>';
			self::render_error_list( $last['errors'] );
			echo '</div>';
		}

		if ( $last['warnings'] ) {
			echo '<div class="notice notice-warning etss-notice"><p><strong>保存はできましたが、確かめてほしい点があります。</strong></p>';
			self::render_error_list( $last['warnings'] );
			echo '</div>';
		}

		// 送信前の JS 検査の結果を出す場所。
		echo '<div class="notice notice-error etss-notice etss-js-summary" id="etss-js-summary" role="alert" hidden><p><strong>入力に不足があります。次の項目を直してください。</strong></p><ul></ul></div>';
	}

	/**
	 * エラー・警告の一覧（各項目は欄へのリンク）を出す。
	 *
	 * @param array $items 欄のキー => 文。
	 * @return void
	 */
	private static function render_error_list( array $items ) {
		echo '<ul class="etss-notice__list">';
		foreach ( $items as $key => $message ) {
			printf( '<li><a href="#%1$s" class="etss-error-link">%2$s</a></li>', esc_attr( self::field_id( $key ) ), esc_html( $message ) );
		}
		echo '</ul>';
	}

	/**
	 * 編集画面の CSS と JS を読み込む。
	 *
	 * @param string $hook_suffix 管理画面のフック名。
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! self::is_slide_edit_screen() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$version = ETSS_Admin::asset_version();
		$now     = self::now();
		$tz      = wp_timezone();
		$saved   = self::get_saved( $post->ID );

		wp_enqueue_media( array( 'post' => $post->ID ) );
		wp_enqueue_style( 'etss-admin', plugins_url( 'assets/admin.css', ETSS_PLUGIN_FILE ), array(), $version );
		wp_enqueue_script( 'etss-admin-slide', plugins_url( 'assets/admin-slide.js', ETSS_PLUGIN_FILE ), array( 'jquery' ), $version, true );

		$config = array(
			// サイトの時計で「いま」を表す秒数（UTC の目盛りに、サイトの時差を足したもの）。JS が書き戻しと「今すぐ表示する」に使う。
			'nowLocal'    => $now + $tz->getOffset( new DateTimeImmutable( '@' . $now ) ),
			'savedActive' => ETSS_Display::STATUS_ACTIVE === ETSS_Display::status( $post->post_status, $saved['start'], $saved['end'], $now ),
			'weekdays'    => ETSS_Display::WEEKDAYS,
		);
		wp_add_inline_script( 'etss-admin-slide', 'window.etssSlide = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
