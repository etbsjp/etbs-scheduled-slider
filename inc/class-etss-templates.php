<?php
/**
 * ひな形（D5）の登録と取り出し。
 *
 * 1枚のスライドの画像枠と HTML は「ひな形」で決まる。本体は `vk-image` を持ち、サイト固有のひな形は
 * そのサイトの mu-plugin が `etss_register_templates` アクションの中で `etss_register_template()` を呼んで足す。
 *
 * ここでは定義の形と検査までを持つ。`render_item` による描画は後の issue で作る（そのため `render_item` は
 * 省略できるが、指定するなら呼び出せる値でなければならない）。
 * `render_item` が返す HTML のエスケープはひな形の責任で、本体は `wp_kses_post` に通さない（VK の属性を落とさないため）。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ひな形の登録簿。
 */
class ETSS_Templates {

	/**
	 * 本体が持つひな形の ID。
	 */
	const DEFAULT_ID = 'vk-image';

	/**
	 * ID（ひな形・画像枠）に使える文字の正規表現。
	 */
	const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]*\z/';

	/**
	 * 登録済みのひな形（ID => 整形済みの定義）。
	 *
	 * @var array
	 */
	private static $templates = array();

	/**
	 * 本体のひな形を登録し、`etss_register_templates` アクションを呼んだか。
	 *
	 * @var bool
	 */
	private static $loaded = false;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		// 他のプラグイン・mu-plugin が init までにフックを足し終えてから、ひな形を集める。
		add_action( 'init', array( __CLASS__, 'load' ), 9 );
	}

	/**
	 * 本体のひな形を登録し、`etss_register_templates` アクションを呼ぶ。2回目以降は何もしない。
	 *
	 * @return void
	 */
	public static function load() {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;

		self::register( self::DEFAULT_ID, self::builtin_vk_image() );

		/**
		 * ひな形を登録するタイミング。この中で etss_register_template() を呼ぶ。
		 */
		do_action( 'etss_register_templates' );
	}

	/**
	 * 登録簿を空に戻す。テスト用。
	 *
	 * @return void
	 */
	public static function reset() {
		self::$templates = array();
		self::$loaded    = false;
	}

	/**
	 * ひな形を登録する。
	 *
	 * 定義に誤りがあるとき、同じ ID がすでにあるときは登録せず、`_doing_it_wrong()` で知らせて false を返す。
	 *
	 * @param string $id   ひな形の ID。
	 * @param array  $args 定義（docs/spec.md §3）。
	 * @return bool 登録できたら true。
	 */
	public static function register( $id, $args ) {
		$result = self::normalize( $id, $args );

		// 同じ ID の二重登録は、先に登録したほうを守る。
		if ( empty( $result['errors'] ) && isset( self::$templates[ $result['template']['id'] ] ) ) {
			$result['errors'][] = '同じ ID のひな形がすでに登録されています。';
		}

		if ( ! empty( $result['errors'] ) ) {
			_doing_it_wrong(
				'etss_register_template',
				esc_html( 'ひな形「' . ( is_string( $id ) ? $id : '' ) . '」を登録できません。' . implode( ' ', $result['errors'] ) ),
				''
			);
			return false;
		}

		self::$templates[ $result['template']['id'] ] = $result['template'];
		return true;
	}

	/**
	 * 登録済みのひな形をすべて返す。
	 *
	 * @return array ID => 定義。
	 */
	public static function all() {
		self::load();

		return self::$templates;
	}

	/**
	 * 登録済みのひな形を1つ返す。
	 *
	 * @param string $id ひな形の ID。
	 * @return array|null 定義。無ければ null。
	 */
	public static function get( $id ) {
		$templates = self::all();

		return ( is_string( $id ) && isset( $templates[ $id ] ) ) ? $templates[ $id ] : null;
	}

	/**
	 * ひな形で新しいスライダーを作ったときの動きの設定を返す（本体の既定値に、ひな形の `default_options` を重ねる）。
	 *
	 * @param string $id ひな形の ID。
	 * @return array 動きの設定。ひな形が無ければ本体の既定値。
	 */
	public static function default_options( $id ) {
		$template = self::get( $id );
		$options  = ETSS_Meta::default_options();

		return null === $template ? $options : array_merge( $options, $template['default_options'] );
	}

	/**
	 * 本体のひな形 `vk-image` の定義。画像枠は1つ（必須）。
	 *
	 * VK のスライダー項目に、リンク付きの画像ブロックを1つ置いた形で描画する（描画は後の issue）。
	 *
	 * @return array 定義。
	 */
	public static function builtin_vk_image() {
		return array(
			'label'           => '画像1枚＋リンク（VK 標準）',
			'slots'           => array(
				'image' => array(
					'label'       => '画像',
					'description' => 'スライドに表示する画像です。',
					'required'    => true,
				),
			),
			'default_options' => array(),
		);
	}

	/**
	 * ひな形の定義を検査し、整形する。WordPress に依存しない。
	 *
	 * 検査する項目: ID の形・label・slots（1つ以上・枠 ID の形と重複・枠の label）・
	 * render_item と import_item が呼び出せること・default_options のキーが既知であること・item_class の文字。
	 *
	 * default_options は、キー名だけを検査する。値の型・範囲の検査は、#2 の保存時の sanitize に任せる。
	 *
	 * @param mixed $id   ひな形の ID。
	 * @param mixed $args 定義。
	 * @return array `template`（整形済みの定義。エラーがあれば null）と `errors`（日本語のエラー文の配列）。
	 */
	public static function normalize( $id, $args ) {
		$errors = array();

		// ID の形。
		if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
			$errors[] = 'ID は英小文字・数字・ハイフン・アンダースコアだけで、英小文字か数字から始めてください。';
		}

		if ( ! is_array( $args ) ) {
			$errors[] = '定義は配列で渡してください。';
			return array(
				'template' => null,
				'errors'   => $errors,
			);
		}

		// 名前。
		if ( ! isset( $args['label'] ) || ! is_string( $args['label'] ) || '' === trim( $args['label'] ) ) {
			$errors[] = 'label（管理画面に出す名前）が必要です。';
		}

		// 画像枠。
		$slots_result = self::normalize_slots( isset( $args['slots'] ) ? $args['slots'] : null );
		$errors       = array_merge( $errors, $slots_result['errors'] );

		// 呼び出せる値。省略はよいが、指定するなら呼び出せなければならない。
		foreach ( array( 'render_item', 'import_item' ) as $key ) {
			if ( isset( $args[ $key ] ) && ! is_callable( $args[ $key ] ) ) {
				$errors[] = $key . ' は呼び出せる値で渡してください。';
			}
		}

		// 動きの設定の既定値。知らないキーは typo の可能性が高いので弾く。
		$default_options = array();
		if ( isset( $args['default_options'] ) ) {
			if ( ! is_array( $args['default_options'] ) ) {
				$errors[] = 'default_options は配列で渡してください。';
			} else {
				$unknown = array_diff( array_keys( $args['default_options'] ), array_keys( ETSS_Meta::default_options() ) );
				if ( $unknown ) {
					$errors[] = 'default_options に未知のキーがあります：' . implode( '、', array_map( 'strval', $unknown ) );
				} else {
					$default_options = $args['default_options'];
				}
			}
		}

		// class に使えない文字を弾く（属性に出すときのエスケープとは別に、形を絞る）。
		$item_class = isset( $args['item_class'] ) ? $args['item_class'] : '';
		if ( ! is_string( $item_class ) || ! preg_match( '/^[A-Za-z0-9_ -]*\z/', $item_class ) ) {
			$errors[]   = 'item_class は class に使える文字（英数字・ハイフン・アンダースコア・空白）だけで渡してください。';
			$item_class = '';
		}

		if ( $errors ) {
			return array(
				'template' => null,
				'errors'   => $errors,
			);
		}

		return array(
			'template' => array(
				'id'              => $id,
				'label'           => trim( $args['label'] ),
				'slots'           => $slots_result['slots'],
				'render_item'     => isset( $args['render_item'] ) ? $args['render_item'] : null,
				'default_options' => $default_options,
				'item_class'      => trim( $item_class ),
				'import_item'     => isset( $args['import_item'] ) ? $args['import_item'] : null,
			),
			'errors'   => array(),
		);
	}

	/**
	 * 画像枠の定義を検査し、整形する。
	 *
	 * 枠は「枠 ID => 定義」の形か、定義に `id` を持たせた並びの形のどちらでも渡せる（後者は ID の重複を検出できる）。
	 * 並びは編集画面の並びになる。
	 *
	 * @param mixed $slots 画像枠の定義。
	 * @return array `slots`（枠 ID => 整形済みの枠）と `errors`。
	 */
	private static function normalize_slots( $slots ) {
		$errors = array();
		$result = array();

		if ( ! is_array( $slots ) || ! $slots ) {
			return array(
				'slots'  => array(),
				'errors' => array( 'slots（画像枠）が1つ以上必要です。' ),
			);
		}

		foreach ( $slots as $key => $slot ) {
			if ( ! is_array( $slot ) ) {
				$errors[] = '画像枠の定義は配列で渡してください。';
				continue;
			}

			// 枠 ID は、文字列のキーか、定義の id から取る。
			$slot_id = is_string( $key ) ? $key : ( isset( $slot['id'] ) ? $slot['id'] : null );
			if ( is_string( $key ) && isset( $slot['id'] ) && $slot['id'] !== $key ) {
				$errors[] = '画像枠「' . $key . '」のキーと id が食い違っています。';
				continue;
			}
			if ( ! is_string( $slot_id ) || ! preg_match( self::ID_PATTERN, $slot_id ) ) {
				$errors[] = '画像枠の ID は英小文字・数字・ハイフン・アンダースコアだけで、英小文字か数字から始めてください。';
				continue;
			}
			if ( isset( $result[ $slot_id ] ) ) {
				$errors[] = '画像枠の ID「' . $slot_id . '」が重複しています。';
				continue;
			}
			if ( ! isset( $slot['label'] ) || ! is_string( $slot['label'] ) || '' === trim( $slot['label'] ) ) {
				$errors[] = '画像枠「' . $slot_id . '」に label が必要です。';
				continue;
			}
			if ( isset( $slot['ratio'] ) && ( ! is_numeric( $slot['ratio'] ) || (float) $slot['ratio'] <= 0 ) ) {
				$errors[] = '画像枠「' . $slot_id . '」の ratio は 0 より大きい数（横÷縦）で渡してください。';
				continue;
			}

			$result[ $slot_id ] = array(
				'id'          => $slot_id,
				'label'       => trim( $slot['label'] ),
				'description' => ( isset( $slot['description'] ) && is_string( $slot['description'] ) ) ? $slot['description'] : '',
				'required'    => ! empty( $slot['required'] ),
				'ratio'       => isset( $slot['ratio'] ) ? (float) $slot['ratio'] : null,
				'preview_dim' => ! empty( $slot['preview_dim'] ),
			);
		}

		return array(
			'slots'  => $result,
			'errors' => $errors,
		);
	}
}
