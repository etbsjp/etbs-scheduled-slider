# etbs-scheduled-slider

etbs が配布する WordPress プラグイン。共通ルールの正本は `~/.claude/etbs-plugin-rules.md`。
**仕様の正本は `docs/spec.md`**（決定事項 D1〜D12 はユーザー確認済み。変えるときはユーザーに確かめる）。

## この repo の issue の所在

作業の issue は **この repo（`etbsjp/etbs-scheduled-slider`）の issue**。orchestrator が `etbsjp/task-queue` にミラーを作るが、
コードの対象はこの repo。「対象リポジトリに issue が無い」と止まったり、別の repo に起票したりしないこと。

## PR の書き方

- PR 本文に、対象の issue の完全な URL を書く。**`etbsjp/etbs-scheduled-slider` の issue の URL**（task-queue のミラーではない）
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

## 移行コマンド（VK のスライダーからスライドを作る）

既存の VK Blocks のスライダーを、このプラグインのスライドに移す WP-CLI コマンド。導入先で数十枚を手で写さずに移すために使う（`docs/spec.md` §8）。

```
wp etss import-vk-slider <post_id> --slider=<スライダーの ID> [--index=0] [--start=2026-11-06T12:00] [--update-options] [--dry-run]
```

- `<post_id>`: VK のスライダーを置いている投稿（固定ページ）の ID。`--slider`: 移し先のスライダー（`etss_slider`）の投稿 ID。先に管理画面か `wp post create --post_type=etss_slider` で作っておく（ひな形は先に決める。スライドを作ったあとは変えられない）
- 手順は 1. `--dry-run` で割り当ての表を確かめる → 2. 同じコマンドから `--dry-run` を外して実行 → 3. スライドの一覧で、下書きのもの（理由が表に出る）を直して「表示に登録」→ 4. 移したスライダーのブロック（`etss/slider`）をページに置き、元の VK のスライダーを外す
- 画像は `wp-image-NNN` の class から添付ファイル ID を取る。リンク先は最初の `<a href>`、alt は画像の alt。理由のある項目（リンク先なし・存在しない添付ファイル・必須の画像枠が空・alt が空など）は、下書きで作られる
- 画像が複数ある項目（横長・縦長など）は、ひな形の `import_item( array $parsed ): array`（枠 ID => 添付ファイル ID を返す）で割り当てる。`$parsed` の形は `docs/spec.md` §3。サイト固有のひな形は、そのサイトの mu-plugin に置く（この repo には入れない）。`import_item` が無いひな形は、1枚目の画像を最初の枠に入れる
- `--update-options` を付けると、VK の動きの設定（自動で切り替える・間隔・速さ・ドット・ループなど）をスライダーの設定へ写す。付けなければスライダーの設定は変えない。一時停止ボタンとズームは写せない（警告が出る）
- `--start` の既定は今（付けるとその日時に表示が始まる。サイトのタイムゾーン）。表示順は元の並び。表示終了は付けない
- 同じ投稿に2回実行すると、同じスライドがもう一度作られる（置き換えはしない。すでにスライドがあるスライダーには警告が出る）。やり直すときは、できたスライドをゴミ箱へ入れてから実行する
- VK の HTML を取り出す処理はテスト済みだが、導入先の本文の書き方（画像ブロックでなく HTML ブロックで貼ってあるなど）によっては、画像が見つからず下書きになる。まず `--dry-run` で確かめる

## 版数

版数は**ヘッダの `Version:` 1か所のみ**。CSS・JS の版は `get_file_data()` でヘッダから読む。block.json に `version` を書かない。

## 検証環境

Local の `ai-wp-demo`（`aiwpdemo.etbs.lc`）に、VK Blocks（wordpress.org の無料版）と LiteSpeed Cache を入れて使う。
**シンボリックリンク設置でよい**（`dirname( __FILE__, N )` を使わない）。

- Local のサーバは LiteSpeed ではないので、hit/miss は出ない。キャッシュの確認は LSCWP のデバッグログで行う
  - TTL（`[Ctrl] X Cache_control TTL -> N`）・目印（`[Tag] Add etss`）・nocache は、そのままで記録される
  - ログの出し方: `wp litespeed-option set debug 1`。ログは `wp-content/litespeed/debug/debug*.log`（LSCWP 7.9.1 の実際の文言は `X Cache_control TTL -> N`・`[Tag] Add  --- etss`・`added _etss`）
  - purge の陽性対照（#1・LSCWP 7.9.1 で「出た」）の手順:
    1. 一時の mu-plugin（`wp-content/mu-plugins/` に置き、**終わったら必ず削除。コミットしない**）で、`template_redirect` に `do_action( 'litespeed_control_set_ttl', 600 )`・`do_action( 'litespeed_tag_add', 'etss' )` を書き、`?etss_purge=1` のときだけ `do_action( 'litespeed_purge', 'etss' )` を呼ぶ
    2. Apache の設定に `SetEnv X-LSCACHE on` を足して再起動する（Local の設定ファイルは書き換えず、`Include` で Local の `apache2.conf` を読み込む自前の設定ファイルを作って `httpd -f` に渡す）
    3. `curl -D -` で `/` と `/?etss_purge=1` を取り、`X-LiteSpeed-Cache-Control: public,max-age=600`・`X-LiteSpeed-Tag: b3c_etss,…`・`X-LiteSpeed-Purge: public,b3c_etss` とログを確かめる
  - 7.9.1 では `SetEnv` が無くても purge のヘッダとログは出た。CLI の purge（`wp plugin deactivate`）は「CLI request, queue stored」としてログに残る
- Local のサイトが GUI で止まっているときは、Local の設定ファイル（`~/Library/Application Support/Local/run/<サイト ID>/conf/`）で mysqld・php-fpm・httpd を手で起動できる。ただし php-fpm は Local の `php-fpm.conf` の `include` が相対パスで通らないので、絶対パスの `include` を書いた自前の conf を `-y` で渡す。**終わったら必ず3つとも止める**（止めないと、あとで GUI からサイトを起動したときにポートが衝突する）
  - 起動する実行ファイルは、httpd が `lightning-services/apache-2.4.43+11/bin/darwin/bin/httpd`（x86_64。`darwin-arm64` の httpd は同じ階層の modules が合わず起動しない）、mysqld が `mysql-8.0.35+4/bin/darwin-arm64/bin/mysqld`、php-fpm が `php-8.3.17+1/bin/darwin-arm64/sbin/php-fpm`（`bin/` ではなく `sbin/`）。WP-CLI は Local の `extraResources/bin/wp-cli/wp-cli.phar` を、`darwin-arm64` の php で動かす
  - Local の `php.ini` は imagick と xdebug の拡張（x86_64 用）を読もうとして、CLI の出力が起動時の警告で埋まる。その2行を除いた写しを作って `-c` で渡すと静かになる（Local の設定ファイルは書き換えない）
- WP_DEBUG は `wp-config.php` を触らずに有効にする。CLI は `wp --require=<define 3行のファイル>`（`WP_DEBUG`・`WP_DEBUG_LOG`・`WP_DEBUG_DISPLAY`）、Web は php-fpm の `-d auto_prepend_file=<同じファイル>`。ログが作られなければ、通知も警告も出ていない
- ★ ai-wp-demo は他のプラグインと共用の検証サイト。LiteSpeed Cache は有効化のときと、その後最初に管理画面を開いたときに `.htaccess` を書き換える。入れる前に `.htaccess` を控え、md5 を記録する
  - #1 の実測: 有効化前 `7fb26e61920d1bb387b6effebb071790`（15行）→ LSCWP 有効化後 `d4c495a541a9b879063d74af01b0d3b5`（`# BEGIN LSCACHE`〜`# END NON_LSCACHE` が先頭に入る）→ 停止後 `9bd4157f7bc7f73dde46f5d1401508c5`（空の `LSCACHE`・`NON_LSCACHE` の枠が残る）。控えを書き戻して `7fb26e61…` に戻した
  - LSCWP は `wp-config.php` にも `define( 'WP_CACHE', true )`（有効化で入り、停止で消える）と `WP_CACHE_KEY_SALT` を足す
  - 検証が終わったら LSCWP を停止して `.htaccess` を控えから戻す（共用サイトに他のプラグインの検証を邪魔する書き換えを残さない）。入れた VK Blocks（1.128.0）・LiteSpeed Cache（7.9.1）は削除せず残す
- ★★★ **uninstall の検証は `uninstall_plugin()` を CLI で呼ぶ。管理画面の「削除」は押さない**（シンボリックリンク設置のまま削除すると、リンク先のこの repo の中身が全部消える）
- エージェントは `<repo>/.claude/worktrees/` で作業するが、Local のプラグインは本体クローンへのシンボリックリンク。実画面で確かめるときは向き先を worktree へ一時的に変え、終わったら戻す
- 権限（D9）の確認: 一時の投稿者・編集者のユーザーを作り、`curl` で `wp-login.php` にログインして `edit.php?post_type=etss_slide`・`post.php?post=<ID>&action=edit` を直接開く（投稿者は 403、編集者は 200）。終わったらユーザーと検証用の投稿（自動下書きを含む）を削除する
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
