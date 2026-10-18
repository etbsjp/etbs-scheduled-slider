<?php
/**
 * スライドの保存時の検査（docs/spec.md §4.4）。
 *
 * 送信値・保存済みの値・現在時刻・タイムゾーン・添付ファイルの情報をすべて引数で受け取る。
 * `$_POST`・`time()`・WordPress の関数は読まない（PHPUnit で境界を確かめるため）。
 * WordPress に依存しない純粋な処理だけを置く。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * スライドの検査をまとめたクラス。
 */
class ETSS_Validator {

	/**
	 * 縦横比が想定からこの割合を超えて外れたら警告する。
	 */
	const RATIO_TOLERANCE = 0.15;

	/**
	 * 送信値を、検査できる形（型をそろえた配列）にする。
	 *
	 * 文字列の項目は前後の空白を落とし、文字列でなければ空にする。画像は「枠 ID => 添付ファイル ID（整数）」にする。
	 *
	 * @param mixed $raw 送信値（`$_POST['etss']` を unslash したもの）。
	 * @return array `slider_id`・`images`・`url`・`start`・`end`（すべて文字列。images のみ配列）。
	 */
	public static function normalize_input( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		$input = array(
			'slider_id' => self::string_value( $raw, 'slider_id' ),
			'images'    => array(),
			'url'       => self::string_value( $raw, 'url' ),
			'start'     => self::string_value( $raw, 'start' ),
			'end'       => self::string_value( $raw, 'end' ),
		);

		if ( isset( $raw['images'] ) && is_array( $raw['images'] ) ) {
			foreach ( $raw['images'] as $slot_id => $attachment_id ) {
				if ( is_string( $slot_id ) && ( is_string( $attachment_id ) || is_int( $attachment_id ) ) ) {
					$input['images'][ $slot_id ] = self::parse_id( $attachment_id );
				}
			}
		}

		return $input;
	}

	/**
	 * 配列から文字列を取り出す。文字列でなければ空文字。前後の空白は落とす。
	 *
	 * @param array  $source 配列。
	 * @param string $key    キー。
	 * @return string 値。
	 */
	private static function string_value( array $source, $key ) {
		return ( isset( $source[ $key ] ) && is_string( $source[ $key ] ) ) ? trim( $source[ $key ] ) : '';
	}

	/**
	 * ID（正の整数）を取り出す。数字だけの文字列か整数のときだけ受け付ける。
	 *
	 * @param mixed $value 値。
	 * @return int ID。不正なら 0。
	 */
	public static function parse_id( $value ) {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		return ( is_string( $value ) && preg_match( '/^\d{1,18}\z/', $value ) ) ? (int) $value : 0;
	}

	/**
	 * 保存済みの値を、送信値と同じ形にする（送信値が無い経路を、保存済みの値で検査するため）。
	 *
	 * @param array        $saved    保存済みの値。`slider_id`・`images`・`url`・`start`・`end`（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone サイトのタイムゾーン。
	 * @return array normalize_input() と同じ形。
	 */
	public static function saved_to_input( array $saved, DateTimeZone $timezone ) {
		$images = array();
		if ( isset( $saved['images'] ) && is_array( $saved['images'] ) ) {
			foreach ( $saved['images'] as $slot_id => $attachment_id ) {
				$images[ (string) $slot_id ] = self::parse_id( is_scalar( $attachment_id ) ? (string) $attachment_id : '' );
			}
		}

		return array(
			'slider_id' => isset( $saved['slider_id'] ) ? (string) (int) $saved['slider_id'] : '',
			'images'    => $images,
			'url'       => isset( $saved['url'] ) && is_string( $saved['url'] ) ? trim( $saved['url'] ) : '',
			'start'     => ETSS_Schedule::format_datetime_local( isset( $saved['start'] ) ? $saved['start'] : 0, $timezone ),
			'end'       => ETSS_Schedule::format_datetime_local( isset( $saved['end'] ) ? $saved['end'] : 0, $timezone ),
		);
	}

	/**
	 * スライド1枚を検査する。
	 *
	 * 検査する項目: 代替テキスト（タイトル）・スライダー・ひな形の必須の画像枠・同じ添付ファイルの重複・リンク先・表示開始（必須）・
	 * 終了が開始より後か。縦横比の外れは警告だけ。1つの欄につきエラーは最初の1つだけ返す。
	 *
	 * `$context` のキー:
	 * - `slider_found`（bool）選んだスライダーが存在するか。
	 * - `template`（array|null）そのスライダーのひな形（ETSS_Templates の整形済みの定義）。無ければ null。
	 * - `attachment_info`（callable）`callable( int $id ): array|null`。画像の添付ファイルなら `width`・`height` を持つ配列、画像でない・無いなら null。
	 * - `timezone`（DateTimeZone）サイトのタイムゾーン。
	 *
	 * @param string $title   タイトル（画像の代替テキスト）。
	 * @param array  $input   normalize_input() の結果。
	 * @param array  $context 検査に使う外の情報。
	 * @return array `errors`（欄のキー => エラー文。検査した順＝画面の上から下の順）・`warnings`（欄のキー => 警告文）・
	 *               `values`（保存する値。`slider_id`・`images`・`url`・`start`・`end`）。
	 */
	public static function validate_slide( $title, array $input, array $context ) {
		$errors   = array();
		$warnings = array();
		$template = isset( $context['template'] ) ? $context['template'] : null;

		// 代替テキスト（タイトル）。
		if ( '' === trim( (string) $title ) ) {
			$errors['title'] = '代替テキスト（タイトル）を入力してください。';
		}

		// スライダー。
		$slider_id = self::parse_id( $input['slider_id'] );
		if ( 0 === $slider_id ) {
			$errors['slider'] = 'スライダーを選択してください。';
		} elseif ( empty( $context['slider_found'] ) ) {
			$errors['slider'] = '選んだスライダーが見つかりません。選び直してください。';
		} elseif ( null === $template ) {
			$errors['slider'] = '選んだスライダーのひな形が見つかりません。ひな形を登録しているプラグインが有効か確認してください。';
		}

		// ひな形の画像枠。
		$images = self::validate_images( $input['images'], null === $template ? array() : $template['slots'], $context, $errors, $warnings );

		// リンク先。
		$url = $input['url'];
		if ( '' === $url ) {
			$errors['url'] = 'リンク先を入力してください。';
		} elseif ( ! ETSS_Link::is_valid( $url ) ) {
			$errors['url'] = 'リンク先の形式が正しくありません。「/」で始まるサイト内のパスか、「http://」「https://」で始まる URL を入力してください。';
		}

		// 表示開始・表示終了。
		$timezone = $context['timezone'];
		$start    = 0;
		$end      = 0;

		if ( '' === $input['start'] ) {
			$errors['start'] = '表示開始日時を入力してください。';
		} else {
			$parsed = ETSS_Schedule::parse_datetime_local( $input['start'], $timezone );
			if ( null === $parsed ) {
				$errors['start'] = '表示開始日時の形式が正しくありません。';
			} else {
				$start = $parsed;
			}
		}

		if ( '' !== $input['end'] ) {
			$parsed = ETSS_Schedule::parse_datetime_local( $input['end'], $timezone );
			if ( null === $parsed ) {
				$errors['end'] = '表示終了日時の形式が正しくありません。';
			} elseif ( $start > 0 && $parsed <= $start ) {
				$errors['end'] = '表示終了日時は、表示開始日時より後にしてください。';
			} else {
				$end = $parsed;
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
			'values'   => array(
				'slider_id' => $slider_id,
				'images'    => $images,
				'url'       => ( '' !== $url && ! isset( $errors['url'] ) ) ? $url : '',
				'start'     => $start,
				'end'       => $end,
			),
		);
	}

	/**
	 * ひな形の画像枠ごとに検査する。
	 *
	 * @param array $posted  送信された画像（枠 ID => 添付ファイル ID）。
	 * @param array $slots   ひな形の画像枠（枠 ID => 定義）。
	 * @param array $context validate_slide() の `$context`。
	 * @param array $errors  エラー（参照渡しで追記する）。
	 * @param array $warnings 警告（参照渡しで追記する）。
	 * @return array 保存する画像（枠 ID => 添付ファイル ID）。有効なものだけ。
	 */
	private static function validate_images( array $posted, array $slots, array $context, array &$errors, array &$warnings ) {
		$images = array();
		$used   = array();

		foreach ( $slots as $slot_id => $slot ) {
			$key           = 'image-' . $slot_id;
			$attachment_id = isset( $posted[ $slot_id ] ) ? (int) $posted[ $slot_id ] : 0;

			if ( 0 === $attachment_id ) {
				if ( ! empty( $slot['required'] ) ) {
					$errors[ $key ] = ETSS_Display::button_name( $slot['label'], '選択' ) . 'してください。';
				}
				continue;
			}

			$info = call_user_func( $context['attachment_info'], $attachment_id );
			if ( ! is_array( $info ) ) {
				$errors[ $key ] = $slot['label'] . 'の画像が見つかりません。選び直してください。';
				continue;
			}

			// 同じ添付ファイルを別の枠にも入れていないか。
			if ( isset( $used[ $attachment_id ] ) ) {
				$errors[ $key ] = $slot['label'] . 'に、「' . $used[ $attachment_id ] . '」と同じ画像が選ばれています。別の画像にしてください。';
				continue;
			}
			$used[ $attachment_id ] = $slot['label'];
			$images[ $slot_id ]     = $attachment_id;

			// 縦横比が想定から大きく外れていれば、警告だけ出す。
			$warning = self::ratio_warning( $slot, $info );
			if ( null !== $warning ) {
				$warnings[ $key ] = $warning;
			}
		}

		return $images;
	}

	/**
	 * 画像の縦横比が、枠の想定から大きく外れているときの警告文を返す。
	 *
	 * @param array $slot 画像枠の定義（`label`・`ratio`）。
	 * @param array $info 添付ファイルの情報（`width`・`height`）。
	 * @return string|null 警告文。外れていなければ（想定が無い・寸法が不明も）null。
	 */
	public static function ratio_warning( array $slot, array $info ) {
		$expected = isset( $slot['ratio'] ) ? (float) $slot['ratio'] : 0.0;
		$width    = isset( $info['width'] ) ? (int) $info['width'] : 0;
		$height   = isset( $info['height'] ) ? (int) $info['height'] : 0;

		if ( $expected <= 0 || $width <= 0 || $height <= 0 ) {
			return null;
		}

		$actual = $width / $height;
		// 浮動小数点の誤差で、ちょうど許容値のものが警告にならないよう、わずかに余裕を持たせる。
		if ( abs( $actual - $expected ) / $expected <= self::RATIO_TOLERANCE + 1e-9 ) {
			return null;
		}

		return $slot['label'] . 'の画像の縦横比（横÷縦 ' . round( $actual, 2 ) . '）が、想定（' . round( $expected, 2 ) . '）から大きく外れています。表示が切れたり余白が出たりしないか確かめてください。';
	}

	/**
	 * 保存したあとの扱いを決める。
	 *
	 * - 公開（publish・future）で保存しようとしていなければ `keep`（状態に手を入れない。エラーも出さない）
	 * - エラーが無ければ `publish`（future は publish に直す）
	 * - エラーがあって、保存済みの値でいま表示中なら `reject`（変更を保存せず、状態と古い値を保つ。D7）
	 * - エラーがあって、それ以外なら `draft`（下書きに落とす）
	 *
	 * @param string $requested_status 保存しようとしている投稿ステータス。
	 * @param bool   $has_errors       検査でエラーがあったか。
	 * @param bool   $saved_active     保存済みの値で、いま表示中か。
	 * @return string `keep`・`publish`・`draft`・`reject` のどれか。
	 */
	public static function decide( $requested_status, $has_errors, $saved_active ) {
		if ( ! in_array( $requested_status, array( 'publish', 'future' ), true ) ) {
			return 'keep';
		}

		if ( ! $has_errors ) {
			return 'publish';
		}

		return $saved_active ? 'reject' : 'draft';
	}
}
