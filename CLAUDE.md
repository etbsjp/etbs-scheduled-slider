# etbs-scheduled-slider

etbs が配布する WordPress プラグイン。共通ルールの正本は `~/.claude/etbs-plugin-rules.md`。
**仕様の正本は `docs/spec.md`**（決定事項 D1〜D12 はユーザー確認済み。変えるときはユーザーに確かめる）。

## この repo の issue の所在

作業の issue は **この repo（`etbsjp/etbs-scheduled-slider`）の issue**。orchestrator が `etbsjp/task-queue` にミラーを作るが、
コードの対象はこの repo。「対象リポジトリに issue が無い」と止まったり、別の repo に起票したりしないこと。

## PR の書き方

- PR 本文に、対象の issue（この repo の issue）の完全な URL を書く
- 閉じるキーワード（`Closes` / `Fixes` / `Resolves`）は付けない。`対象 issue: <URL>` のように参照だけにする
- **版数（ヘッダの `Version:`）を上げない・`dist` に push しない。** 版数上げ・`dist` への反映は人が行う

## レビュー工程に大（シニアエンジニア）を追加する

このリポジトリでは、安藤（`vk-code-reviewer`）のレビューのあと、**PR を作成する前に**
大（`etbs-senior-wp`）の監査を必ず通すこと。大は etbs の申し送りと過去に踏んだ罠に照らして
「リリースできる形になっているか」を見る担当で、安藤の一般的なコード品質レビューとは層が違う。

- `Agent` ツールで `subagent_type: etbs-senior-wp`、`name: etbs-senior-wp`、
  **`run_in_background: false`** で起動する
- **`isolation: "worktree"` は使えるなら付ける**（付けないと起動応答は「成功」と返るのに
  一度も作業せず待機状態に入ることがある）。ただし ★★ **作業ディレクトリが git リポジトリでないと使えない。**
  その場合は **isolation なしで起動してよい**。**見分け方は起動応答の形**——`output_file` 付きの正常形なら動いている
- prompt には対象リポジトリ・ブランチ・差分（または PR 番号）を渡す
- 大には **出力の末尾に `監査結果: PASS` または `監査結果: FAIL` を必ず書くよう指示する**
  （★ 大の定義ファイルには出力形式の指定が無いため、指示しないと合否を機械判定できない）
- `監査結果: PASS` を受け取るまで PR を作成しない。`FAIL` なら和田へ差し戻して再監査する

★ 大は vk-agents のメンバー表に登録されていないため、指示が無いと**永久に呼ばれない**。

管理画面の画面・文言を変える issue では、植草（`vk-ux-designer`）のレビューも通す（docs/spec.md §4 は r1 の UX レビューを反映済み）。

## 作りの決まり（docs/spec.md の要点）

- 動きと CSS は VK Blocks（無料版でよい）に任せる。Swiper を自前で持たない。VK のスライダーと同じ HTML を `render_block()` に `vk-blocks/slider` として渡して描画する
- VK の JS は `.vk_slider_<blockId>` で Swiper を起動する。外枠の class と `data-vkb-slider` の `blockId` を必ず揃える
- 表示開始前のスライドは HTML に出さない。cron を使わず、LiteSpeed Cache の保存期間を次の切替時刻までにする（上限 1 日）。WP の予約投稿（`future`）は使わない
- ひな形の `render_item` が返す HTML は本体で `wp_kses_post` に通さない（VK の属性を落とさないため）。エスケープはひな形の責任
- 文言は日本語で直接書く（readme.txt は置かない。翻訳ファイルも作らない）
- 時刻・入力値・保存済みの値は引数で受け取る関数にし、`$_POST`・`$_GET`・`time()` を直接読む箇所はフックの入口だけにする

## 版数

版数は**ヘッダの `Version:` 1か所のみ**。CSS・JS の版は `get_file_data()` でヘッダから読む。block.json に `version` を書かない。

## 検証環境

Local の `ai-wp-demo`（`aiwpdemo.etbs.lc`）に、VK Blocks（wordpress.org の無料版）と LiteSpeed Cache を入れて使う。
**シンボリックリンク設置でよい**（`dirname( __FILE__, N )` を使わない）。

- Local のサーバは LiteSpeed ではないので、hit/miss は出ない。キャッシュの確認は LSCWP のデバッグログ（`[Ctrl] X Cache_control TTL -> N`・`[Tag] Add etss`・purge）で行う
- エージェントは `<repo>/.claude/worktrees/` で作業するが、Local のプラグインは本体クローンへのシンボリックリンク。実画面で確かめるときは向き先を worktree へ一時的に変え、終わったら戻す
- CLI 検証では Local の php.ini を `-c` で渡すこと。wp-cli は `-d mysqli.default_socket=<Local のソケット>` を付ける

## テスト

`composer install` のあと `vendor/bin/phpunit`。WordPress のテストスイートは使わず、`tests/phpunit/bootstrap.php` で必要な関数だけをスタブにする（etbs-notice-banner と同じ型）。
★ **CI は PHPUnit を走らせない**（`ci.yml` は原本と byte 一致で運用する決まりのため）。PHPUnit は手元で実行し、結果を PR に貼る。

## アンインストール

方針は**案A**。スライダー・スライドの投稿とメタは残す。`_etss_last_errors` は消す。cron・オプション・独自テーブルは持たない。

## 導入先

| サイト | ひな形 | 備考 |
|---|---|---|
| www.neogate.jp（トップのメインスライダー） | `neogate-top`（サイトの mu-plugin） | ひな形・ドットの CSS・移行の割り当てはサイトの mu-plugin に置く。この repo には入れない |
