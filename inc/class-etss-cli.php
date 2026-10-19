<?php
/**
 * WP-CLI のコマンド `wp etss`（docs/spec.md §8・D11）。
 *
 * 取り出しと割り当て（ETSS_Import）は純粋な関数に任せ、ここは WP-CLI の引数・投稿の読み書き・出力といった、
 * WordPress に触れる部分（コマンドの入口）だけを持つ。現在時刻を読むのも、ここだけにする。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ETBS Scheduled Slider の WP-CLI コマンド。
 */
class ETSS_CLI {

	/**
	 * VK Blocks のスライダーから、スライドを作る。
	 *
	 * 指定した投稿の本文から VK Blocks のスライダー（vk-blocks/slider）を探し、スライダー項目ごとに、
	 * 画像（wp-image-NNN の添付ファイル ID）・最初のリンク先・alt を取り出して、スライドを作る。
	 * 表示順は元の並び。画像の枠への割り当ては、ひな形の import_item に任せる（無ければ、1枚目の画像を最初の枠に入れる）。
	 * 足りない枠・見つからない添付ファイル・リンク先なしなどの項目は、下書きで作り、理由を出す。
	 * 同じ投稿に2回実行すると、同じスライドがもう一度作られる。
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : VK Blocks のスライダーを置いている投稿（固定ページ）の ID。
	 *
	 * --slider=<slider_id>
	 * : スライドを入れるスライダー（etss_slider）の投稿 ID。
	 *
	 * [--index=<index>]
	 * : 本文に VK Blocks のスライダーが複数あるとき、何番目を使うか（0 から数える）。
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--start=<datetime>]
	 * : 作るスライドの表示開始日時。2026-11-06T12:00 の形で、サイトのタイムゾーンで解釈する。省略すると今。
	 *
	 * [--update-options]
	 * : VK のスライダーの設定（自動で切り替える・間隔・速さ・ドットなど）を、スライダーの動きの設定に写す。省略すると、スライダーの設定は変えない。
	 *
	 * [--dry-run]
	 * : スライドを作らず、割り当ての表だけを出す。
	 *
	 * ## EXAMPLES
	 *
	 *     # 何が作られるかを表で確かめる。
	 *     wp etss import-vk-slider 123 --slider=45 --dry-run
	 *
	 *     # 本文の2つめの VK スライダーから、動きの設定ごと、決まった日時を表示開始にして作る。
	 *     wp etss import-vk-slider 123 --slider=45 --index=1 --update-options --start=2026-11-06T12:00
	 *
	 * @subcommand import-vk-slider
	 *
	 * @param array $args       位置引数（投稿 ID）。
	 * @param array $assoc_args 名前つきの引数。
	 * @return void
	 */
	public function import_vk_slider( $args, $assoc_args ) {
		$arg_error = ETSS_Import::validate_args( $args[0], \WP_CLI\Utils\get_flag_value( $assoc_args, 'index', 0 ) );
		if ( '' !== $arg_error ) {
			\WP_CLI::error( $arg_error );
		}

		$post_id   = (int) $args[0];
		$slider_id = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'slider', 0 );
		$index     = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'index', 0 );
		$dry_run   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$update    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'update-options', false );

		// 時刻を読むのはここだけにする。
		$start = ETSS_Import::parse_start( \WP_CLI\Utils\get_flag_value( $assoc_args, 'start', null ), wp_timezone(), time() );
		if ( null === $start ) {
			\WP_CLI::error( '--start の形式が正しくありません。2026-11-06T12:00 の形で指定してください。' );
		}

		// 移し先のスライダーと、そのひな形。
		$template = $this->get_slider_template( $slider_id );

		// 移し元の VK のスライダー。
		$source = get_post( $post_id );
		if ( ! $source ) {
			\WP_CLI::error( '投稿 #' . $post_id . ' が見つかりません。' );
		}
		$sliders = ETSS_Import::find_sliders( parse_blocks( $source->post_content ) );
		if ( ! isset( $sliders[ $index ] ) ) {
			\WP_CLI::error( '投稿 #' . $post_id . ' の本文には、番号 ' . $index . ' の VK Blocks のスライダーがありません（見つかったのは ' . count( $sliders ) . ' 個）。' );
		}

		$parsed = ETSS_Import::parse_slider( $sliders[ $index ] );
		if ( ! $parsed['items'] ) {
			\WP_CLI::error( 'このスライダーには、スライダー項目がありません。' );
		}
		$plan = ETSS_Import::plan( $parsed, $template, array( __CLASS__, 'attachment_exists' ), $start );

		\WP_CLI::log( sprintf( '移し元: 投稿 #%d「%s」の VK スライダー（番号 %d・%d 項目）', $post_id, $source->post_title, $index, count( $parsed['items'] ) ) );
		\WP_CLI::log( sprintf( '移し先: スライダー #%d「%s」（ひな形 %s）／表示開始 %s', $slider_id, get_the_title( $slider_id ), $template['id'], ETSS_Display::format_list_datetime( $start, $start, wp_timezone() ) ) );

		$existing = ETSS_Slider_Admin::count_slides( $slider_id );
		if ( $existing > 0 ) {
			\WP_CLI::warning( 'このスライダーには、すでにスライドが ' . $existing . ' 枚あります。続けると、その後ろに足して作ります（置き換えはしません）。' );
		}

		// 動きの設定。
		$mapping = $this->plan_options( $slider_id, $template, $parsed['settings'] );
		$this->print_options( $mapping, $update );

		if ( $dry_run ) {
			\WP_CLI\Utils\format_items( 'table', ETSS_Import::table_rows( $plan ), array( '#', '代替テキスト', 'リンク先', '画像', '状態', '理由' ) );
			$this->print_summary( $plan );
			\WP_CLI::success( '--dry-run のため、何も作っていません。' );
			return;
		}

		// スライドを作る。
		$post_ids = $this->create_slides( $plan, $slider_id );
		$this->print_created( $plan, $post_ids );

		if ( $update ) {
			update_post_meta( $slider_id, ETSS_Meta::OPTIONS, wp_slash( $mapping['options'] ) );
			\WP_CLI::log( '動きの設定を写しました。' );
		}

		\WP_CLI::success( count( $post_ids ) . ' 枚のスライドを作りました。スライドの一覧で確かめてください。' );
	}

	/**
	 * 添付ファイルが、画像として使えるかを返す（計画に渡す関数）。
	 *
	 * @param int $attachment_id 添付ファイルの ID。
	 * @return bool 使えるなら true。
	 */
	public static function attachment_exists( $attachment_id ) {
		return null !== ETSS_Slide_Admin::attachment_info( (int) $attachment_id );
	}

	/**
	 * 移し先のスライダーを確かめ、そのひな形の定義を返す。使えなければコマンドを終える。
	 *
	 * @param int $slider_id スライダーの投稿 ID。
	 * @return array ひな形の定義。
	 */
	private function get_slider_template( $slider_id ) {
		if ( $slider_id <= 0 || ETSS_Post_Types::SLIDER !== get_post_type( $slider_id ) || 'trash' === get_post_status( $slider_id ) ) {
			\WP_CLI::error( 'スライダー #' . $slider_id . ' が見つかりません。--slider には、スライダー（etss_slider）の投稿 ID を指定してください。' );
		}

		$template = ETSS_Templates::for_slider( $slider_id );
		if ( null === $template ) {
			\WP_CLI::error( 'スライダー #' . $slider_id . ' のひな形が登録されていません。ひな形を登録しているプラグイン（mu-plugin）が有効か確かめてください。' );
		}

		return $template;
	}

	/**
	 * VK の設定を、スライダーの動きの設定に写したときの結果を求める。
	 *
	 * @param int   $slider_id   スライダーの投稿 ID。
	 * @param array $template    ひな形の定義。
	 * @param array $vk_settings VK の設定。
	 * @return array ETSS_Import::map_options() の結果。
	 */
	private function plan_options( $slider_id, array $template, array $vk_settings ) {
		$defaults = ETSS_Templates::default_options( $template['id'] );
		$saved    = get_post_meta( $slider_id, ETSS_Meta::OPTIONS, true );

		return ETSS_Import::map_options( $vk_settings, array_merge( $defaults, is_array( $saved ) ? $saved : array() ) );
	}

	/**
	 * 動きの設定の写し方を出力する。
	 *
	 * @param array $mapping ETSS_Import::map_options() の結果。
	 * @param bool  $update  `--update-options` が付いているか。
	 * @return void
	 */
	private function print_options( array $mapping, $update ) {
		if ( ! $mapping['changes'] ) {
			\WP_CLI::log( '動きの設定: VK の設定と、スライダーの設定は同じです。' );
		} else {
			\WP_CLI::log( '動きの設定: ' . ( $update ? '次のとおりに写します。' : 'VK の設定と違う項目があります（--update-options を付けると写します）。' ) );
			foreach ( $mapping['changes'] as $key => $change ) {
				\WP_CLI::log( sprintf( '  %s: %s => %s', $key, $this->format_value( $change['from'] ), $this->format_value( $change['to'] ) ) );
			}
		}

		if ( $mapping['unsupported'] ) {
			\WP_CLI::warning( 'VK で使われている次の設定は、このプラグインに無いため写せません: ' . implode( '、', $mapping['unsupported'] ) );
		}
	}

	/**
	 * 設定の値を、出力用の文字列にする。
	 *
	 * @param mixed $value 値。
	 * @return string 文字列。
	 */
	private function format_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return '' === $value ? "''" : (string) $value;
	}

	/**
	 * スライドを作る。
	 *
	 * 表示にできる項目は公開（publish）で作る。保存時の検査（ETSS_Slide_Admin）が通らなければ、そこで下書きに落ちる。
	 * 理由のある項目は下書きで作る。タイトル（alt）が空の項目も作れるよう、空のタイトルを拒む WordPress の判定を、作る間だけ外す。
	 *
	 * @param array $plan      ETSS_Import::plan() の結果。
	 * @param int   $slider_id スライダーの投稿 ID。
	 * @return array 計画の `index` => 作ったスライドの投稿 ID。
	 */
	private function create_slides( array $plan, $slider_id ) {
		$post_ids = array();

		add_filter( 'wp_insert_post_empty_content', '__return_false' );

		foreach ( $plan as $item ) {
			// 値は保存済みのメタとして書く。使えないリンク先・存在しない画像は入れない。
			$meta = array(
				ETSS_Meta::SLIDER_ID => $slider_id,
				ETSS_Meta::IMAGES    => $item['images'],
				ETSS_Meta::START     => $item['start'],
				ETSS_Meta::END       => $item['end'],
			);
			if ( '' !== $item['url'] ) {
				$meta[ ETSS_Meta::URL ] = $item['url'];
			}

			// update_post_meta() が slash を外すので、先に slash を通す。
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'   => ETSS_Post_Types::SLIDE,
						'post_status' => $item['status'],
						'post_title'  => $item['title'],
						'menu_order'  => $item['menu_order'],
						'meta_input'  => $meta,
					)
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::warning( ( $item['index'] + 1 ) . ' 番目の項目を作れませんでした: ' . $post_id->get_error_message() );
				continue;
			}

			$post_ids[ $item['index'] ] = (int) $post_id;
		}

		remove_filter( 'wp_insert_post_empty_content', '__return_false' );

		return $post_ids;
	}

	/**
	 * 作った結果を表で出す。表示にするつもりが、保存時の検査で下書きに落ちたものは知らせる。
	 *
	 * @param array $plan     ETSS_Import::plan() の結果。
	 * @param array $post_ids 計画の `index` => 作ったスライドの投稿 ID。
	 * @return void
	 */
	private function print_created( array $plan, array $post_ids ) {
		foreach ( $plan as $key => $item ) {
			if ( ! isset( $post_ids[ $item['index'] ] ) || 'publish' !== $item['status'] ) {
				continue;
			}
			if ( 'publish' !== get_post_status( $post_ids[ $item['index'] ] ) ) {
				$plan[ $key ]['status']    = 'draft';
				$plan[ $key ]['reasons'][] = '保存時の検査で下書きに落ちました（スライドの編集画面でエラーを確かめてください）';
			}
		}

		\WP_CLI\Utils\format_items( 'table', ETSS_Import::table_rows( $plan, $post_ids ), array( '#', 'ID', '代替テキスト', 'リンク先', '画像', '状態', '理由' ) );
		$this->print_summary( $plan );
	}

	/**
	 * 表示にするものと下書きにするものの数を出す。
	 *
	 * @param array $plan ETSS_Import::plan() の結果。
	 * @return void
	 */
	private function print_summary( array $plan ) {
		$drafts = 0;
		foreach ( $plan as $item ) {
			if ( 'draft' === $item['status'] ) {
				++$drafts;
			}
		}

		\WP_CLI::log( sprintf( '表示 %d 枚／下書き %d 枚（下書きは理由を直して、編集画面で「表示に登録」を押すと表示されます）', count( $plan ) - $drafts, $drafts ) );
	}
}
