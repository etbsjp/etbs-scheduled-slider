<?php
/**
 * inc/class-etss-link.php（リンク先の検査）のテスト。
 *
 * @package etbs-scheduled-slider
 */

use PHPUnit\Framework\TestCase;

/**
 * ETSS_Link のテスト。
 */
class LinkTest extends TestCase {

	/**
	 * ETSS_Link::is_valid() のテスト。
	 *
	 * @return void
	 */
	public function test_is_valid() {
		$test_cases = array(
			array(
				'test_condition_name' => 'サイト内のパス => true',
				'input'               => '/news/2026/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ルートだけ => true',
				'input'               => '/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'クエリとフラグメントつきのパス => true',
				'input'               => '/shop/?id=3#top',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'https の URL => true',
				'input'               => 'https://example.com/page',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'http の URL => true',
				'input'               => 'http://example.com',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'スキームが大文字 => true',
				'input'               => 'HTTPS://example.com/',
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'ポートつき => true',
				'input'               => 'http://localhost:8080/a',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '//example.com（プロトコル相対） => false',
				'input'               => '//example.com',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '//example.com/path => false',
				'input'               => '//example.com/path',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '/\\example.com（ブラウザが // と同じに扱う） => false',
				'input'               => '/\\example.com',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '///example.com => false',
				'input'               => '///example.com',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'javascript: => false',
				'input'               => 'javascript:alert(1)',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'data: => false',
				'input'               => 'data:text/html;base64,AAAA',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'mailto: => false',
				'input'               => 'mailto:info@example.com',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ftp:// => false',
				'input'               => 'ftp://example.com/',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '相対パス（/ で始まらない） => false',
				'input'               => 'news/index.html',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ホストなし http:/// => false',
				'input'               => 'http:///path',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ホストが空 http://?x => false',
				'input'               => 'http://?x=1',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'URL にバックスラッシュ => false',
				'input'               => 'https://example.com\\@evil.example',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空白を含む => false',
				'input'               => '/news /1',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '先頭に空白 => false',
				'input'               => ' /news',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '改行を含む => false',
				'input'               => "/news\n/1",
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'タブを含む => false',
				'input'               => "https://exa\tmple.com/",
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空文字 => false',
				'input'               => '',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '文字列でない（null） => false',
				'input'               => null,
				'expected'            => false,
			),
			array(
				'test_condition_name' => '文字列でない（配列） => false',
				'input'               => array( '/news/' ),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], ETSS_Link::is_valid( $case['input'] ), $case['test_condition_name'] );
		}
	}
}
