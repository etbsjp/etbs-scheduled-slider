<?php
/**
 * アンインストール時の処理。
 *
 * 方針は etbs の配布プラグイン共通の「案A」（task-queue #108）。判定は3分類。
 *
 * | 利用者が作ったコンテンツ | 利用者が設定した値 | 一時状態・自分が仕掛けた cron |
 * |---|---|---|
 * | 消さない | 消さない | 消す |
 *
 * このプラグインでの当てはめ:
 * - 残す … 投稿タイプ etss_slider（スライダー）・etss_slide（スライド）の投稿と、そのメタ
 *           （_etss_template・_etss_options・_etss_extra_class・_etss_slider_id・_etss_images・_etss_url・_etss_start・_etss_end）。
 *           利用者が作ったコンテンツで、動きの設定もスライダーの投稿のメタとして持つ。入れ直したときに戻せるようにする。
 * - 残す … オプション。このプラグインはオプションを持たない（該当なし）。
 * - 消す … メタ _etss_last_errors。前回の保存で出たエラーを画面に出すための一時状態で、次の保存で作り直される。
 * - 該当なし … cron（予約投稿の future も使わない）・独自テーブル。仕掛けていない。
 *
 * 消さない理由は害の非対称性。残す害は DB に小さな行が残るだけだが、消す害は復旧できない
 * （入れ直したときに、作ったスライドが消えている）。
 *
 * このファイルはプラグインを読み込まずに動くので、メタのキーは ETSS_Meta を使わず文字列で書く。
 *
 * @package etbs-scheduled-slider
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 一時状態だけを消す。投稿の種類を問わず、このキーのメタをすべて消す。
delete_post_meta_by_key( '_etss_last_errors' );
