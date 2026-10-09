<?php
/**
 * 記事本文から <a> リンクを抽出する。
 * 各リンクについて「直前の見出し（h2〜h4）」も記録し、商品名照合に使う。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMC_Extractor {

	/**
	 * 本文HTMLからリンク一覧を抽出。
	 *
	 * @param string $html 投稿本文（ブロックコメント含んでいてもよい）。
	 * @return array[] 各要素: url, anchor_text, heading, is_image_link
	 */
	public static function extract( $html ) {
		return self::extract_all( $html )['links'];
	}

	/**
	 * 本文HTMLから <a> リンクと、idつき要素（JS遷移ボタン候補）を抽出。
	 *
	 * @param string $html 投稿本文。
	 * @return array{links: array[], buttons: array[]}
	 *         links:   url, anchor_text, heading, is_image_link
	 *         buttons: id, text, heading
	 */
	public static function extract_all( $html ) {
		$empty = array( 'links' => array(), 'buttons' => array() );
		if ( '' === trim( (string) $html ) ) {
			return $empty;
		}

		// ブロックエディタのコメントはDOM解析に影響しないのでそのまま処理。
		// ショートコードは展開してから解析（カエレバ等のリンク系ショートコード対策）。
		$html = do_shortcode( $html );

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="UTF-8"><div id="lmc-root">' . $html . '</div>',
			LIBXML_NOWARNING | LIBXML_NOERROR
		);
		libxml_clear_errors();

		$xpath = new DOMXPath( $doc );
		$root  = $xpath->query( '//div[@id="lmc-root"]' )->item( 0 );
		if ( ! $root ) {
			return $empty;
		}

		// 文書順で見出し・リンク・idつき要素を走査し、「直前の見出し」を割り当てる。
		$nodes   = $xpath->query( './/h2|.//h3|.//h4|.//a[@href]|.//*[@id]', $root );
		$links   = array();
		$buttons = array();
		$current_heading = '';

		foreach ( $nodes as $node ) {
			$tag = strtolower( $node->nodeName );

			if ( in_array( $tag, array( 'h2', 'h3', 'h4' ), true ) ) {
				$current_heading = self::clean_text( $node->textContent );
				continue;
			}

			if ( 'a' === $tag && $node->hasAttribute( 'href' ) ) {
				$href = trim( (string) $node->getAttribute( 'href' ) );
				if ( '' === $href ) {
					continue;
				}
				// ページ内アンカー・メール・電話などは対象外。
				if ( preg_match( '/^(#|mailto:|tel:|javascript:)/i', $href ) ) {
					continue;
				}

				$anchor_text = self::clean_text( $node->textContent );
				$is_image    = ( '' === $anchor_text && $xpath->query( './/img', $node )->length > 0 );
				if ( $is_image ) {
					$img = $xpath->query( './/img', $node )->item( 0 );
					$anchor_text = $img ? self::clean_text( (string) $img->getAttribute( 'alt' ) ) : '';
				}

				$links[] = array(
					'url'           => $href,
					'anchor_text'   => $anchor_text,
					'heading'       => $current_heading,
					'is_image_link' => $is_image,
				);
				continue;
			}

			// idつき要素 = JS遷移リンクの候補。
			$id = trim( (string) $node->getAttribute( 'id' ) );
			if ( '' === $id || 'lmc-root' === $id ) {
				continue;
			}

			$buttons[] = array(
				'id'      => $id,
				'text'    => self::clean_text( $node->textContent ),
				'heading' => $current_heading,
			);
		}

		return array( 'links' => $links, 'buttons' => $buttons );
	}

	/**
	 * 広告（ASP）リンクだけに絞る。
	 *
	 * パターンの書き方:
	 *  - 「/」を含まない行（例: a8.net）→ ホスト名で照合（サブドメインも一致）
	 *  - 「/」を含む行（例: /code/ や example.com/news/finance/code/）
	 *    → URLにその文字列が含まれれば一致（自ドメインのクッションページ対応）
	 *
	 * @param array[]  $links    extract() の結果。
	 * @param string[] $patterns パターンリスト。
	 */
	public static function filter_ad_links( $links, $patterns ) {
		if ( empty( $patterns ) ) {
			return array();
		}
		return array_values( array_filter( $links, function ( $link ) use ( $patterns ) {
			return self::matches_patterns( $link['url'], $patterns );
		} ) );
	}

	/**
	 * URLがパターンリストのいずれかに一致するか。
	 *
	 * @param string   $url      対象URL。
	 * @param string[] $patterns 「/」なし=ドメイン一致（サブドメイン含む）、「/」あり=URL部分一致。
	 */
	public static function matches_patterns( $url, $patterns ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		foreach ( (array) $patterns as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}
			if ( false !== strpos( $pattern, '/' ) ) {
				// パスつきパターン: URL部分一致。
				if ( false !== strpos( $url, $pattern ) ) {
					return true;
				}
			} elseif ( $host && ( $host === $pattern || str_ends_with( $host, '.' . $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function clean_text( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}
}
