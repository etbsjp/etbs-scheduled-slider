<?php
/**
 * 管理画面に出す状態・日時・ボタン名の文言づくり。
 *
 * 現在時刻・タイムゾーンはすべて引数で受け取る（time() や WordPress の関数を直接は読まない）。
 * WordPress に依存しない純粋な処理だけを置く。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 状態と日時の文言をまとめたクラス。
 */
class ETSS_Display {

	/**
	 * 状態：表示中。
	 */
	const STATUS_ACTIVE = 'active';

	/**
	 * 状態：表示予定。
	 */
	const STATUS_SCHEDULED = 'scheduled';

	/**
	 * 状態：表示終了。
	 */
	const STATUS_ENDED = 'ended';

	/**
	 * 状態：下書き。
	 */
	const STATUS_DRAFT = 'draft';

	/**
	 * 曜日の表記（date の `w` の順。日曜が 0）。
	 *
	 * @var string[]
	 */
	const WEEKDAYS = array( '日', '月', '火', '水', '木', '金', '土' );

	/**
	 * スライドの状態を決める。WordPress の公開状態ではなく、表示期間で見分ける。
	 *
	 * 公開（publish）以外は下書き。公開でも、開始が未設定・終了が不正なものは表示されないので下書きと同じ扱いにする。
	 *
	 * @param string $post_status WordPress の投稿ステータス。
	 * @param int    $start       表示開始（UTC の Unix 秒）。
	 * @param int    $end         表示終了（UTC の Unix 秒。0 は終了なし）。
	 * @param int    $now         現在時刻（UTC の Unix 秒）。
	 * @return string STATUS_* のどれか。
	 */
	public static function status( $post_status, $start, $end, $now ) {
		$start = (int) $start;
		$end   = (int) $end;
		$now   = (int) $now;

		if ( 'publish' !== $post_status || $start <= 0 || $end < 0 ) {
			return self::STATUS_DRAFT;
		}

		if ( $now < $start ) {
			return self::STATUS_SCHEDULED;
		}

		return ETSS_Schedule::is_active( $start, $end, $now ) ? self::STATUS_ACTIVE : self::STATUS_ENDED;
	}

	/**
	 * 状態の表示名を返す。
	 *
	 * @param string $status STATUS_* のどれか。
	 * @return string 表示名。
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::STATUS_ACTIVE    => '表示中',
			self::STATUS_SCHEDULED => '表示予定',
			self::STATUS_ENDED     => '表示終了',
			self::STATUS_DRAFT     => '下書き',
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels[ self::STATUS_DRAFT ];
	}

	/**
	 * 日時を「11/06（金）12:00」の形にする（一覧・公開ボックス用）。24時間制。
	 *
	 * @param int          $timestamp UTC の Unix 秒。
	 * @param DateTimeZone $timezone  表示するタイムゾーン。
	 * @return string 書式化した日時。
	 */
	public static function format_short( $timestamp, DateTimeZone $timezone ) {
		$date = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $timezone );

		return $date->format( 'm/d' ) . '（' . self::WEEKDAYS[ (int) $date->format( 'w' ) ] . '）' . $date->format( 'H:i' );
	}

	/**
	 * 日時を「2026年11月6日（金）12:00」の形にする（入力欄の書き戻し用）。24時間制。
	 *
	 * 管理画面の言語に関わらず、同じ形で出す（英語環境の datetime-local が12時間制になり、取り違えるため）。
	 *
	 * @param int          $timestamp UTC の Unix 秒。
	 * @param DateTimeZone $timezone  表示するタイムゾーン。
	 * @return string 書式化した日時。
	 */
	public static function format_full( $timestamp, DateTimeZone $timezone ) {
		$date = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $timezone );

		return $date->format( 'Y' ) . '年' . (int) $date->format( 'n' ) . '月' . (int) $date->format( 'j' ) . '日（' . self::WEEKDAYS[ (int) $date->format( 'w' ) ] . '）' . $date->format( 'H:i' );
	}

	/**
	 * 秒数を「3日4時間」「2時間30分」「5分」の形にする。1分未満は「1分未満」。
	 *
	 * @param int $seconds 0 以上の秒数。
	 * @return string 期間の文言。
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		$days    = intdiv( $seconds, 86400 );
		$hours   = intdiv( $seconds % 86400, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );

		if ( $days > 0 ) {
			return $days . '日' . ( $hours > 0 ? $hours . '時間' : '' );
		}
		if ( $hours > 0 ) {
			return $hours . '時間' . ( $minutes > 0 ? $minutes . '分' : '' );
		}
		if ( $minutes > 0 ) {
			return $minutes . '分';
		}

		return '1分未満';
	}

	/**
	 * いまとの差を「あと3日4時間」「3日4時間前」の形にする。1分未満は「まもなく」「たった今」。
	 *
	 * @param int $diff 対象の時刻 − 現在時刻（秒）。正なら未来。
	 * @return string 文言。
	 */
	public static function format_relative( $diff ) {
		$diff = (int) $diff;

		if ( abs( $diff ) < 60 ) {
			return $diff > 0 ? 'まもなく' : 'たった今';
		}

		return $diff > 0 ? 'あと' . self::format_duration( $diff ) : self::format_duration( -$diff ) . '前';
	}

	/**
	 * 表示開始の入力欄の横に出す、書き戻しの文言を返す。
	 *
	 * @param int          $start    表示開始（UTC の Unix 秒）。0 以下は未入力。
	 * @param int          $now      現在時刻（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone タイムゾーン。
	 * @return string 文言。未入力なら空文字。
	 */
	public static function describe_start( $start, $now, DateTimeZone $timezone ) {
		if ( (int) $start <= 0 ) {
			return '';
		}

		return '→ ' . self::format_full( $start, $timezone ) . ' に表示開始（' . self::format_relative( (int) $start - (int) $now ) . '）';
	}

	/**
	 * 表示終了の入力欄の横に出す、書き戻しの文言を返す。
	 *
	 * @param int          $end      表示終了（UTC の Unix 秒）。0 以下は終了なし。
	 * @param int          $now      現在時刻（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone タイムゾーン。
	 * @return string 文言。
	 */
	public static function describe_end( $end, $now, DateTimeZone $timezone ) {
		if ( (int) $end <= 0 ) {
			return '→ 表示終了日時なし（自分で外すまで表示し続けます）';
		}

		return '→ ' . self::format_full( $end, $timezone ) . ' に表示終了（' . self::format_relative( (int) $end - (int) $now ) . '）';
	}

	/**
	 * 書き戻しの文言を HTML にする。末尾の「（あと3日4時間）」の中身を、折り返さない span で包む。
	 *
	 * assets/admin-slide.js の setWriteback() と同じ形にそろえること。
	 *
	 * @param string $text describe_start() / describe_end() の結果。
	 * @return string エスケープ済みの HTML。
	 */
	public static function writeback_html( $text ) {
		$text = (string) $text;

		// 「あと〜」「〜前」「まもなく」「たった今」だけが対象（「自分で外すまで〜」の注記は包まない）。
		if ( ! preg_match( '/^(.*（)([^（）]+)(）)$/u', $text, $m ) || ! preg_match( '/^(あと|まもなく$|たった今$|.*前$)/u', $m[2] ) ) {
			return esc_html( $text );
		}

		return esc_html( $m[1] ) . '<span class="etss-writeback__relative">' . esc_html( $m[2] ) . '</span>' . esc_html( $m[3] );
	}

	/**
	 * 公開ボックスに足す「表示: 〜」の1行（「表示: 」より後ろ）を返す。
	 *
	 * 例「表示予定（11/06（金）12:00 から）」。
	 *
	 * @param string       $post_status WordPress の投稿ステータス。
	 * @param int          $start       表示開始（UTC の Unix 秒）。
	 * @param int          $end         表示終了（UTC の Unix 秒。0 は終了なし）。
	 * @param int          $now         現在時刻（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone    タイムゾーン。
	 * @return string 文言。
	 */
	public static function submitbox_line( $post_status, $start, $end, $now, DateTimeZone $timezone ) {
		$status = self::status( $post_status, $start, $end, $now );
		$label  = self::status_label( $status );

		switch ( $status ) {
			case self::STATUS_SCHEDULED:
				return $label . '（' . self::format_short( $start, $timezone ) . ' から）';
			case self::STATUS_ACTIVE:
				return $label . ( (int) $end > 0 ? '（' . self::format_short( $end, $timezone ) . ' まで）' : '（終了日時なし）' );
			case self::STATUS_ENDED:
				return $label . '（' . self::format_short( $end, $timezone ) . ' に終了）';
			default:
				return $label;
		}
	}

	/**
	 * 画像枠のボタン名を作る。例「横長の画像を選ぶ」。
	 *
	 * 枠の名前がすでに「画像」で終わるとき（例「画像」）は「画像の画像を選ぶ」にならないよう、「の画像」を足さない。
	 *
	 * @param string $slot_label 枠の名前。
	 * @param string $verb       動詞（「選ぶ」「変更する」「外す」）。
	 * @return string ボタン名。
	 */
	public static function button_name( $slot_label, $verb ) {
		$slot_label = trim( (string) $slot_label );

		if ( 0 === substr_compare( $slot_label, '画像', -strlen( '画像' ) ) ) {
			return $slot_label . 'を' . $verb;
		}

		return $slot_label . 'の画像を' . $verb;
	}
}
