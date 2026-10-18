<?php
/**
 * スライドの一覧と要約の計算（docs/spec.md §4.5）。
 *
 * 現在時刻・タイムゾーン・スライドの値はすべて引数で受け取る（time() や WordPress の関数を直接は読まない）。
 * WordPress に依存しない純粋な処理だけを置く。画面への出力と、スライドの読み出しは ETSS_List_Admin が持つ。
 *
 * スライド1枚は「行」として渡す。行は次のキーを持つ配列。
 * - `id`（int）投稿 ID
 * - `title`（string）代替テキスト
 * - `post_status`（string）WordPress の投稿ステータス
 * - `slider_id`（int）スライダーの投稿 ID
 * - `start`（int）表示開始（UTC の Unix 秒）
 * - `end`（int）表示終了（UTC の Unix 秒。0 は終了なし）
 * - `menu_order`（int）表示順
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 一覧の状態の切り替え・件数・要約・警告の計算をまとめたクラス。
 */
class ETSS_List {

	/**
	 * 切り替え：表示終了以外（一覧の既定）。
	 */
	const STATE_NOT_ENDED = 'not-ended';

	/**
	 * 切り替え：すべて。
	 */
	const STATE_ALL = 'all';

	/**
	 * 通知に出すタイトルの最大文字数（超えたら省略する）。
	 */
	const TITLE_LIMIT = 24;

	/**
	 * 状態の理由を並べる最大数。
	 */
	const REASON_LIMIT = 2;

	/**
	 * 一覧の上の切り替えの名前（並び順どおり）。
	 *
	 * @return array 切り替えの値 => 表示名。
	 */
	public static function states() {
		return array(
			self::STATE_NOT_ENDED          => '表示終了以外',
			ETSS_Display::STATUS_ACTIVE    => ETSS_Display::status_label( ETSS_Display::STATUS_ACTIVE ),
			ETSS_Display::STATUS_SCHEDULED => ETSS_Display::status_label( ETSS_Display::STATUS_SCHEDULED ),
			ETSS_Display::STATUS_DRAFT     => ETSS_Display::status_label( ETSS_Display::STATUS_DRAFT ),
			ETSS_Display::STATUS_ENDED     => ETSS_Display::status_label( ETSS_Display::STATUS_ENDED ),
			self::STATE_ALL                => 'すべて',
		);
	}

	/**
	 * 切り替えの値を整える。知らない値・文字列でない値は、既定（表示終了以外）にする。
	 *
	 * @param mixed $value 値（URL の引数）。
	 * @return string 切り替えの値。
	 */
	public static function sanitize_state( $value ) {
		return ( is_string( $value ) && array_key_exists( $value, self::states() ) ) ? $value : self::STATE_NOT_ENDED;
	}

	/**
	 * 行の状態（表示中・表示予定・表示終了・下書き）を返す。
	 *
	 * @param array $row 行。
	 * @param int   $now 現在時刻（UTC の Unix 秒）。
	 * @return string ETSS_Display::STATUS_* のどれか。
	 */
	public static function row_status( array $row, $now ) {
		return ETSS_Display::status(
			isset( $row['post_status'] ) ? (string) $row['post_status'] : '',
			isset( $row['start'] ) ? (int) $row['start'] : 0,
			isset( $row['end'] ) ? (int) $row['end'] : 0,
			$now
		);
	}

	/**
	 * 状態が、切り替えの条件に当てはまるかを返す。
	 *
	 * @param string $status ETSS_Display::STATUS_* のどれか。
	 * @param string $state  切り替えの値（sanitize_state() を通したもの）。
	 * @return bool 当てはまれば true。
	 */
	public static function matches_state( $status, $state ) {
		if ( self::STATE_ALL === $state ) {
			return true;
		}
		if ( self::STATE_NOT_ENDED === $state ) {
			return ETSS_Display::STATUS_ENDED !== $status;
		}

		return $status === $state;
	}

	/**
	 * 切り替えごとの件数を数える。
	 *
	 * @param array $rows      行の配列。
	 * @param int   $now       現在時刻（UTC の Unix 秒）。
	 * @param int   $slider_id 絞り込むスライダーの投稿 ID（0 ならすべて）。
	 * @return array 切り替えの値 => 件数（states() のキーすべて）。
	 */
	public static function count_by_state( array $rows, $now, $slider_id = 0 ) {
		$counts = array_fill_keys( array_keys( self::states() ), 0 );

		foreach ( $rows as $row ) {
			if ( ! self::in_slider( $row, $slider_id ) ) {
				continue;
			}

			$status = self::row_status( $row, $now );
			foreach ( array_keys( $counts ) as $state ) {
				if ( self::matches_state( $status, $state ) ) {
					++$counts[ $state ];
				}
			}
		}

		return $counts;
	}

	/**
	 * 切り替えに当てはまる行の投稿 ID を、表示順に並べて返す。
	 *
	 * @param array  $rows      行の配列。
	 * @param int    $now       現在時刻（UTC の Unix 秒）。
	 * @param string $state     切り替えの値。
	 * @param int    $slider_id 絞り込むスライダーの投稿 ID（0 ならすべて）。
	 * @return int[] 投稿 ID。
	 */
	public static function ids_for_state( array $rows, $now, $state, $slider_id = 0 ) {
		$matched = array();

		foreach ( $rows as $row ) {
			if ( self::in_slider( $row, $slider_id ) && self::matches_state( self::row_status( $row, $now ), $state ) ) {
				$matched[] = $row;
			}
		}

		return array_map( 'intval', array_column( self::sort_rows( $matched ), 'id' ) );
	}

	/**
	 * 行を表示順に並べる。表示順（小さい順）、同じなら表示開始の新しい順、それも同じなら投稿 ID の新しい順。
	 *
	 * @param array $rows 行の配列。
	 * @return array 並べた行の配列。
	 */
	public static function sort_rows( array $rows ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				$order = (int) $a['menu_order'] <=> (int) $b['menu_order'];
				if ( 0 !== $order ) {
					return $order;
				}

				$start = (int) $b['start'] <=> (int) $a['start'];
				if ( 0 !== $start ) {
					return $start;
				}

				return (int) $b['id'] <=> (int) $a['id'];
			}
		);

		return $rows;
	}

	/**
	 * 行が、絞り込むスライダーに属するかを返す。
	 *
	 * @param array $row       行。
	 * @param int   $slider_id 絞り込むスライダーの投稿 ID（0 ならすべて）。
	 * @return bool 属するなら true。
	 */
	private static function in_slider( array $row, $slider_id ) {
		return 0 === (int) $slider_id || (int) $row['slider_id'] === (int) $slider_id;
	}

	/**
	 * 行のうち、表示に関わるもの（表示中・表示予定）だけを返す。
	 *
	 * @param array $rows 行の配列。
	 * @param int   $now  現在時刻（UTC の Unix 秒）。
	 * @return array 行の配列。
	 */
	private static function displayable_rows( array $rows, $now ) {
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $now ) {
					return in_array( ETSS_List::row_status( $row, $now ), array( ETSS_Display::STATUS_ACTIVE, ETSS_Display::STATUS_SCHEDULED ), true );
				}
			)
		);
	}

	/**
	 * スライダー1つ分の要約を作る。表示中の枚数と、次の切り替え。
	 *
	 * @param array $rows 1つのスライダーに属する行の配列。
	 * @param int   $now  現在時刻（UTC の Unix 秒）。
	 * @return array `active`（表示中の枚数）・`next`（次の切り替え。無ければ null）。
	 *               `next` は `time`（UTC の Unix 秒）と `events`（`kind`＝`start`／`end`・`title`・`id` の配列。開始を先に並べる）。
	 */
	public static function summarize_slider( array $rows, $now ) {
		$active    = 0;
		$displayed = self::displayable_rows( $rows, $now );

		foreach ( $displayed as $row ) {
			if ( ETSS_Display::STATUS_ACTIVE === self::row_status( $row, $now ) ) {
				++$active;
			}
		}

		$time = ETSS_Schedule::next_switch_time( $displayed, $now );
		if ( null === $time ) {
			return array(
				'active' => $active,
				'next'   => null,
			);
		}

		// 次の切り替え時刻に、表示が変わるスライド（開始するもの・終了するもの）を集める。
		$starts = array();
		$ends   = array();
		foreach ( self::sort_rows( $displayed ) as $row ) {
			$status = self::row_status( $row, $now );
			$event  = array(
				'title' => (string) $row['title'],
				'id'    => (int) $row['id'],
			);
			if ( ETSS_Display::STATUS_SCHEDULED === $status && (int) $row['start'] === $time ) {
				$starts[] = array_merge( array( 'kind' => 'start' ), $event );
			} elseif ( ETSS_Display::STATUS_ACTIVE === $status && (int) $row['end'] === $time ) {
				$ends[] = array_merge( array( 'kind' => 'end' ), $event );
			}
		}

		return array(
			'active' => $active,
			'next'   => array(
				'time'   => $time,
				'events' => array_merge( $starts, $ends ),
			),
		);
	}

	/**
	 * スライダー1つ分の、表示するスライドが 0 枚になる時間帯を求める（これから先と、いま 0 枚の場合）。
	 *
	 * スライドが1枚も無いスライダーは、時間帯を返さない（まだ使っていないスライダーに警告を出さないため）。
	 *
	 * @param array $rows 1つのスライダーに属する行の配列。
	 * @param int   $now  現在時刻（UTC の Unix 秒）。
	 * @return array 時間帯の配列。各要素は `from`（開始。UTC の Unix 秒）・`to`（終わり。以降ずっと 0 枚なら null）・`current`（いま 0 枚で、いまから始まる時間帯か）。
	 */
	public static function zero_windows( array $rows, $now ) {
		if ( ! $rows ) {
			return array();
		}

		$now       = (int) $now;
		$displayed = self::displayable_rows( $rows, $now );

		// 表示が変わりうる時刻（いまより後の開始・終了）を、小さい順に並べる。
		$times = array();
		foreach ( $displayed as $row ) {
			foreach ( array( (int) $row['start'], (int) $row['end'] ) as $time ) {
				if ( $time > $now ) {
					$times[ $time ] = $time;
				}
			}
		}
		sort( $times );

		// いまと、各時刻に切り替わった直後の枚数を調べ、0 枚の間を時間帯にする。
		$windows = array();
		$open    = null;
		foreach ( array_merge( array( $now ), $times ) as $index => $point ) {
			$count = 0;
			foreach ( $displayed as $row ) {
				if ( ETSS_Schedule::is_active( $row['start'], $row['end'], $point ) ) {
					++$count;
				}
			}

			if ( 0 === $count && null === $open ) {
				$open = array(
					'from'    => $point,
					'to'      => null,
					'current' => 0 === $index,
				);
			} elseif ( $count > 0 && null !== $open ) {
				$open['to'] = $point;
				$windows[]  = $open;
				$open       = null;
			}
		}

		if ( null !== $open ) {
			$windows[] = $open;
		}

		return $windows;
	}

	/**
	 * 表示開始日時が入っているのに下書きのスライド（登録し忘れの疑い）を、表示開始の早い順に返す。
	 *
	 * 表示終了日時がすでに過ぎているものは、登録しても表示されないので含めない。
	 *
	 * @param array $rows 行の配列。
	 * @param int   $now  現在時刻（UTC の Unix 秒）。
	 * @return array 行の配列。
	 */
	public static function drafts_with_start( array $rows, $now ) {
		$found = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $now ) {
					$expired = (int) $row['end'] > 0 && (int) $row['end'] <= (int) $now;

					return 'publish' !== $row['post_status'] && (int) $row['start'] > 0 && ! $expired;
				}
			)
		);

		usort(
			$found,
			static function ( $a, $b ) {
				return array( (int) $a['start'], (int) $a['id'] ) <=> array( (int) $b['start'], (int) $b['id'] );
			}
		);

		return $found;
	}

	/**
	 * 下書きの理由を、検査のエラーから作る。
	 *
	 * @param array $errors ETSS_Validator::validate_slide() の `errors`（欄のキー => 文）。
	 * @param array $input  ETSS_Validator::normalize_input() と同じ形の入力（空かどうかで言い分けるため）。
	 * @return string[] 理由の短い文言（エラーの順）。エラーが無ければ空配列。
	 */
	public static function draft_reasons( array $errors, array $input ) {
		$reasons = array();

		foreach ( array_keys( $errors ) as $key ) {
			$key = (string) $key;

			if ( 'title' === $key ) {
				$reasons[] = '代替テキストなし';
			} elseif ( 'slider' === $key ) {
				$reasons[] = 'スライダー未設定';
			} elseif ( 0 === strpos( $key, 'image-' ) ) {
				$reasons[] = '画像不足';
			} elseif ( 'url' === $key ) {
				$reasons[] = ( isset( $input['url'] ) && '' !== $input['url'] ) ? 'リンク先が不正' : 'リンク先なし';
			} elseif ( 'start' === $key ) {
				$reasons[] = ( isset( $input['start'] ) && '' !== $input['start'] ) ? '表示開始日時が不正' : '表示開始日時なし';
			} elseif ( 'end' === $key ) {
				$reasons[] = '表示終了日時が不正';
			}
		}

		// 画像枠が複数足りなくても「画像不足」は1回だけ。
		return array_values( array_unique( $reasons ) );
	}

	/**
	 * 状態を、理由つきの文言にする。例「下書き（画像不足・リンク先なし）」。
	 *
	 * 下書き以外は理由を付けない。下書きで理由が無ければ「下書き（登録前）」（検査には通るが、まだ表示に登録していない）。
	 *
	 * @param string   $status  ETSS_Display::STATUS_* のどれか。
	 * @param string[] $reasons draft_reasons() の結果。
	 * @return string 文言。
	 */
	public static function format_status( $status, array $reasons ) {
		$label = ETSS_Display::status_label( $status );

		if ( ETSS_Display::STATUS_DRAFT !== $status ) {
			return $label;
		}

		if ( ! $reasons ) {
			return $label . '（登録前）';
		}

		$shown = array_slice( $reasons, 0, self::REASON_LIMIT );
		$more  = count( $reasons ) - count( $shown );

		return $label . '（' . implode( '・', $shown ) . ( $more > 0 ? '…ほか' . $more . '件' : '' ) . '）';
	}

	/**
	 * 通知に出すタイトルを整える。空なら代わりの文言、長ければ省略する。
	 *
	 * @param string $title タイトル。
	 * @return string タイトル。
	 */
	public static function shorten_title( $title ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return '（代替テキストなし）';
		}

		return mb_strlen( $title ) > self::TITLE_LIMIT ? mb_substr( $title, 0, self::TITLE_LIMIT ) . '…' : $title;
	}

	/**
	 * 「次の切り替え」の文言を作る。例「次の切り替え 11/06（金）12:00（あと3日）『○○』が表示開始」。同時に変わるものが複数あれば「ほか1件」。
	 *
	 * @param array|null   $next     summarize_slider() の `next`。
	 * @param int          $now      現在時刻（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone タイムゾーン。
	 * @return string 文言。次の切り替えが無ければ「次の切り替えはありません」。
	 */
	public static function describe_next( $next, $now, DateTimeZone $timezone ) {
		if ( ! is_array( $next ) || empty( $next['events'] ) ) {
			return '次の切り替えはありません';
		}

		$first = $next['events'][0];
		$more  = count( $next['events'] ) - 1;
		$text  = '次の切り替え ' . ETSS_Display::format_list_datetime( $next['time'], $now, $timezone )
			. '（' . ETSS_Display::format_remaining_short( (int) $next['time'] - (int) $now ) . '）'
			. '『' . self::shorten_title( $first['title'] ) . '』が' . ( 'start' === $first['kind'] ? '表示開始' : '表示終了' );

		return $text . ( $more > 0 ? '（ほか' . $more . '件）' : '' );
	}

	/**
	 * 0 枚になる時間帯の文言を作る。スライダーの名前は含めない。
	 *
	 * @param array        $window   zero_windows() の要素。
	 * @param int          $now      現在時刻（UTC の Unix 秒）。
	 * @param DateTimeZone $timezone タイムゾーン。
	 * @return string 文言。
	 */
	public static function describe_zero_window( array $window, $now, DateTimeZone $timezone ) {
		$from = ETSS_Display::format_list_datetime( $window['from'], $now, $timezone );
		$to   = null === $window['to'] ? '' : ETSS_Display::format_list_datetime( $window['to'], $now, $timezone );

		if ( ! empty( $window['current'] ) ) {
			return null === $window['to']
				? 'いま 0 枚です。表示するスライドがありません。'
				: 'いま 0 枚です。' . $to . ' から表示されます。';
		}

		return null === $window['to']
			? $from . ' 以降、表示するスライドが 0 枚になります。'
			: $from . ' から ' . $to . ' まで、表示するスライドが 0 枚になります。';
	}
}
