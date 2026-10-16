<?php
/**
 * リンク1件ごとの検査ロジック。
 *
 * 検査項目:
 *  - URL形式の妥当性・末尾欠けの疑い
 *  - クエリパラメータの重複
 *  - 内部リンクの旧スラッグ・存在しないスラッグ
 *  - 実アクセスでのHTTPステータス（リダイレクト追跡つき）
 *  - トップページへのリダイレクト検出（広告リンク切れの典型パターン）
 *  - リンク先ページタイトルと直前見出しの商品名照合
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMC_Checker {

	const SEVERITY_ERROR   = 'error';
	const SEVERITY_WARNING = 'warning';

	/** @var array 同一リクエスト内のHTTP結果キャッシュ（URL => 結果） */
	private static $http_cache = array();

	/**
	 * リンク1件を検査して問題の配列を返す。
	 *
	 * @param array $link    LMC_Extractor::extract() の1要素。
	 * @param array $options check_http の有効・無効など。
	 * @return array[] 各問題: code, severity, message
	 */
	public static function check_link( $link, $options = array() ) {
		$options = wp_parse_args( $options, array(
			'check_http' => true,
		) );

		$issues = array();
		$url    = $link['url'];

		// --- 1. URL形式・末尾欠け ---
		$format_issues = self::check_format( $url );
		$issues        = array_merge( $issues, $format_issues );

		// 形式エラーが致命的（スキーム不正など）な場合はここで終了。
		foreach ( $format_issues as $issue ) {
			if ( 'invalid_url' === $issue['code'] ) {
				return $issues;
			}
		}

		// --- 2. パラメータ重複 ---
		$issues = array_merge( $issues, self::check_duplicate_params( $url ) );

		// --- 3. 内部リンクのスラッグ照合 ---
		$internal = self::check_internal_slug( $url );
		$issues   = array_merge( $issues, $internal['issues'] );

		// --- 4. 実アクセス確認 ---
		// 内部リンクとして確認済み、または内部チェックで問題確定済みならアクセス省略。
		$confirmed_ok = $internal['is_internal_ok'];
		if ( $options['check_http'] && ! $internal['is_internal_ok'] && empty( $internal['issues'] ) ) {
			$http = self::fetch( $url );
			$issues = array_merge( $issues, self::check_http_result( $url, $http ) );
			$confirmed_ok = ( empty( $http['error'] ) && $http['status'] < 400 );
		}

		// 末尾欠けの「疑い」は、実際にアクセスできたなら報告しない
		// （?param= のように「=」等で終わる正当なURLもあるため）。
		if ( $confirmed_ok ) {
			$issues = array_values( array_filter( $issues, function ( $issue ) {
				return 'truncated' !== $issue['code'];
			} ) );
		}

		return $issues;
	}

	/**
	 * URL形式と末尾欠けの疑いを検査。
	 */
	public static function check_format( $url ) {
		$issues = array();

		// href=\"https://...\" のように、エスケープされた引用符ごとhrefに入っているケース。
		// （一括置換ツール等の変換ミスの典型。読者がクリックしても正しく開けない）
		if ( preg_match( '/^[\\\\\'"]+(https?:\/\/.+?)[\\\\\'"]+$/', $url, $m ) ) {
			$issues[] = array(
				'code'     => 'broken_markup',
				'severity' => self::SEVERITY_ERROR,
				'message'  => 'リンクのHTML記述が壊れています。href の前後に余分な \\" （バックスラッシュ付き引用符）が付いているため、クリックしても正しいページが開きません。コードエディタでこのリンクを探し、バックスラッシュを削除して href="' . $m[1] . '" に直してください',
			);
			return $issues;
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts || empty( $parts['host'] ) || empty( $parts['scheme'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			// 相対URL（/page/ など）は内部リンクとして許容。
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				return $issues;
			}
			$issues[] = array(
				'code'     => 'invalid_url',
				'severity' => self::SEVERITY_ERROR,
				'message'  => 'URLの形式が不正です（スキームやホストが欠けています）',
			);
			return $issues;
		}

		// 末尾欠けの典型パターン: 「-」「=」「%」「?」「&」「.」で終わる。
		if ( preg_match( '/[-=%?&.,_]$/', $url ) ) {
			$issues[] = array(
				'code'     => 'truncated',
				'severity' => self::SEVERITY_ERROR,
				'message'  => '末尾が「' . substr( $url, -1 ) . '」で終わっており、URLが途中で欠けている可能性があります',
			);
		}

		// パーセントエンコードの欠け（% の後ろが16進2桁でない）。
		if ( preg_match( '/%(?![0-9A-Fa-f]{2})/', $url ) ) {
			$issues[] = array(
				'code'     => 'truncated',
				'severity' => self::SEVERITY_ERROR,
				'message'  => '「%」の後ろが不完全で、URLが途中で欠けている可能性があります',
			);
		}

		// 「https://」だけ、ホストのみでパスやパラメータを期待する短縮URL等は判定困難なためここでは扱わない。

		return $issues;
	}

	/**
	 * クエリパラメータの重複を検査。
	 */
	public static function check_duplicate_params( $url ) {
		$issues = array();
		$query  = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! $query ) {
			return $issues;
		}

		$keys = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$key = rawurldecode( explode( '=', $pair, 2 )[0] );
			// a8等で使われる配列形式 key[] は重複が正常なので除外。
			if ( str_ends_with( $key, '[]' ) ) {
				continue;
			}
			$keys[ $key ] = ( $keys[ $key ] ?? 0 ) + 1;
		}

		$dupes = array_keys( array_filter( $keys, function ( $count ) {
			return $count > 1;
		} ) );

		if ( $dupes ) {
			$issues[] = array(
				'code'     => 'dup_param',
				'severity' => self::SEVERITY_ERROR,
				'message'  => 'パラメータが重複しています: ' . implode( ', ', $dupes ),
			);
		}

		return $issues;
	}

	/**
	 * 内部リンクのスラッグを照合。
	 *
	 * @return array{issues: array[], is_internal_ok: bool}
	 *         is_internal_ok=true なら投稿が確認できたのでHTTPアクセスを省略できる。
	 */
	public static function check_internal_slug( $url ) {
		$result = array( 'issues' => array(), 'is_internal_ok' => false );

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		// 相対URLは内部リンク扱い。
		if ( null === $url_host && 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
			$url_host = $site_host;
		}

		if ( $url_host !== $site_host ) {
			return $result;
		}

		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			if ( 'publish' !== get_post_status( $post_id ) ) {
				$result['issues'][] = array(
					'code'     => 'internal_not_public',
					'severity' => self::SEVERITY_ERROR,
					'message'  => 'リンク先の記事が非公開（' . get_post_status( $post_id ) . '）です',
				);
			} else {
				$result['is_internal_ok'] = true;
			}
			return $result;
		}

		// 投稿が見つからない → 旧スラッグに一致するか調べる。
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			$result['is_internal_ok'] = true; // トップページ
			return $result;
		}

		$slug = sanitize_title( basename( $path ) );
		if ( $slug ) {
			$old_slug_post = self::find_post_by_old_slug( $slug );
			if ( $old_slug_post ) {
				$result['issues'][] = array(
					'code'     => 'old_slug',
					'severity' => self::SEVERITY_ERROR,
					'message'  => '古いスラッグへのリンクです。現在のURL: ' . get_permalink( $old_slug_post ),
				);
				return $result;
			}
		}

		// 固定ページ・アーカイブ・カテゴリ等の可能性があるため、404かどうかはHTTPアクセスで最終確認。
		return $result;
	}

	/**
	 * _wp_old_slug メタから旧スラッグに一致する投稿を探す。
	 */
	private static function find_post_by_old_slug( $slug ) {
		global $wpdb;
		$post_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 1",
			$slug
		) );
		return $post_id ? (int) $post_id : 0;
	}

	/**
	 * HTTPアクセス結果から問題を抽出。
	 */
	public static function check_http_result( $url, $http ) {
		$issues = array();

		if ( ! empty( $http['error'] ) ) {
			$issues[] = array(
				'code'     => 'http_error',
				'severity' => self::SEVERITY_ERROR,
				'message'  => 'アクセスできませんでした: ' . $http['error'],
			);
			return $issues;
		}

		// SSL証明書の期限切れは、読者のブラウザでも実際にブロックされるためエラー。
		// （ルート証明書の問題などチェック元都合のSSL検証失敗は、応答があれば何も報告しない）
		if ( ! empty( $http['ssl_issue'] ) ) {
			$issues[] = array(
				'code'     => 'ssl_expired',
				'severity' => self::SEVERITY_ERROR,
				'message'  => 'リンク先のSSL証明書の有効期限が切れています。ほとんどのブラウザでアクセスがブロックされるため、リンク先サイトの証明書更新が必要です',
			);
		}

		// 401/403/429はbot対策による拒否がほとんどで、読者のブラウザでは
		// 正常に開けるケースが大半のため、一切報告しない
		// （企業サイトのFAQ等は正規ブラウザ以外に401を返すものがある）。
		if ( in_array( $http['status'], array( 401, 403, 429 ), true ) ) {
			return $issues;
		}

		// App StoreのWebはbotに404を返すことがあるため、公式のLookup APIで
		// アプリが実在するかを照合してから判定する。
		if ( 404 === (int) $http['status'] && preg_match( '#https?://(?:apps|itunes)\.apple\.com/.*?id(\d+)#', $http['final_url'], $m ) ) {
			if ( self::app_store_exists( $m[1] ) ) {
				return $issues; // アプリは実在。botへの404なので問題なし。
			}
			$issues[] = array(
				'code'     => 'http_error',
				'severity' => self::SEVERITY_ERROR,
				'message'  => 'App Storeにアプリが見つかりません（配信終了・取り下げの可能性があります）',
			);
			return $issues;
		}

		if ( $http['status'] >= 400 ) {
			$issues[] = array(
				'code'     => 'http_error',
				'severity' => self::SEVERITY_ERROR,
				'message'  => 'リンク先がエラーを返しました（HTTP ' . $http['status'] . '）',
			);
			return $issues;
		}

		// 元URLにパスやクエリがあったのに、最終的にトップページへ飛ばされた場合は
		// 「商品ページ削除→トップへリダイレクト」の典型パターンとして警告。
		$orig_path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$orig_query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$final_path = (string) wp_parse_url( $http['final_url'], PHP_URL_PATH );
		$had_target = ( '' !== trim( $orig_path, '/' ) || '' !== $orig_query );

		if ( $had_target && $http['redirected'] && in_array( $final_path, array( '', '/' ), true )
			&& '' === (string) wp_parse_url( $http['final_url'], PHP_URL_QUERY ) ) {
			$issues[] = array(
				'code'     => 'redirect_to_top',
				'severity' => self::SEVERITY_WARNING,
				'message'  => 'トップページへリダイレクトされました。商品ページが削除されている可能性があります（最終URL: ' . $http['final_url'] . '）',
			);
		}

		return $issues;
	}

	/**
	 * URLへ実アクセスし、ステータス・最終URL・本文を返す。
	 * リダイレクトは手動で追跡して最終URLを記録する。
	 */
	public static function fetch( $url ) {
		if ( isset( self::$http_cache[ $url ] ) ) {
			return self::$http_cache[ $url ];
		}

		$timeout = (int) LMC_Settings::get( 'timeout' );
		// bot対策のあるサイトが偽の404/403を返さないよう、UAだけでなく
		// Chromeが実際に送るヘッダー一式をすべて付けてブラウザとして振る舞う。
		$args = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
			'headers'     => array(
				'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
				'Accept-Language'           => 'ja,en-US;q=0.9,en;q=0.8',
				'Cache-Control'             => 'max-age=0',
				'Upgrade-Insecure-Requests' => '1',
				'Sec-Fetch-Dest'            => 'document',
				'Sec-Fetch-Mode'            => 'navigate',
				'Sec-Fetch-Site'            => 'none',
				'Sec-Fetch-User'            => '?1',
				'sec-ch-ua'                 => '"Chromium";v="131", "Google Chrome";v="131", "Not_A Brand";v="24"',
				'sec-ch-ua-mobile'          => '?0',
				'sec-ch-ua-platform'        => '"macOS"',
			),
		);

		$current    = $url;
		$redirected = false;
		$status     = 0;
		$body       = '';
		$error      = '';
		$ssl_issue  = false;
		$visited    = array();

		// HTTPリダイレクトに加え、クッションページ（meta refresh / JS遷移）も追跡する。
		for ( $requests = 0; $requests < 8; $requests++ ) {
			if ( isset( $visited[ $current ] ) ) {
				break; // ループ防止。
			}
			$visited[ $current ] = true;

			$response = wp_remote_get( $current, $args );

			// SSL検証エラーは、チェック元サーバーのルート証明書が古いだけの場合が
			// 多い（ブラウザでは正常）ため、検証なしで再試行して生死を確かめる。
			// ただし「期限切れ」は読者のブラウザも実際にブロックするため区別して記録する。
			if ( is_wp_error( $response ) && false !== stripos( $response->get_error_message(), 'SSL certificate problem' ) ) {
				if ( false !== stripos( $response->get_error_message(), 'expired' ) ) {
					$ssl_issue = true;
				}
				$response = wp_remote_get( $current, array_merge( $args, array( 'sslverify' => false ) ) );
			}

			if ( is_wp_error( $response ) ) {
				$error = $response->get_error_message();
				break;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( $status >= 300 && $status < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( ! $location ) {
					break;
				}
				$current    = self::resolve_url( $location, $current );
				$redirected = true;
				continue;
			}

			$body = wp_remote_retrieve_body( $response );

			// クッションページなら遷移先を追跡（200かつ転送指定がある場合）。
			if ( $status < 400 ) {
				$next = self::extract_meta_refresh_url( $body, $current );
				if ( '' !== $next && ! isset( $visited[ $next ] ) ) {
					$current    = $next;
					$redirected = true;
					$body       = '';
					continue;
				}
			}
			break;
		}

		$result = array(
			'status'     => $status,
			'final_url'  => $current,
			'redirected' => $redirected,
			'body'       => mb_substr( (string) $body, 0, 100000 ),
			'error'      => $error,
			'ssl_issue'  => $ssl_issue,
		);

		self::$http_cache[ $url ] = $result;
		return $result;
	}

	/**
	 * App StoreのアプリIDが実在するかをiTunes Lookup APIで確認（12時間キャッシュ）。
	 *
	 * @param string $app_id 数字のアプリID。
	 */
	private static function app_store_exists( $app_id ) {
		$cache_key = 'lmc_appstore_' . $app_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$response = wp_remote_get( 'https://itunes.apple.com/lookup?id=' . rawurlencode( $app_id ) . '&country=jp', array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// 確認できない場合は「実在する」扱いにして誤エラーを避ける。
			set_transient( $cache_key, '1', HOUR_IN_SECONDS );
			return true;
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$exists = is_array( $data ) && ! empty( $data['resultCount'] );

		set_transient( $cache_key, $exists ? '1' : '0', 12 * HOUR_IN_SECONDS );
		return $exists;
	}

	/**
	 * クッションページ（meta refresh / JS転送）の遷移先URLを取得。
	 * 通常ページを誤検出しないよう、JS遷移の判定は小さいページ（10KB未満）に限定する。
	 *
	 * @param string $body     ページHTML。
	 * @param string $base_url このページのURL（相対URL解決用）。
	 * @return string 遷移先の絶対URL。なければ空文字。
	 */
	public static function extract_meta_refresh_url( $body, $base_url ) {
		$body   = (string) $body;
		$target = '';

		if ( preg_match( '/<meta[^>]*refresh[^>]*url\s*=\s*[\'"]?([^\'">\s]+)/i', $body, $m ) ) {
			$target = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		} elseif ( strlen( $body ) < 10000
			&& preg_match( '/(?:location\.href\s*=|location\.replace\(|window\.open\()\s*[\'"]([^\'"]+)[\'"]/', $body, $m ) ) {
			$target = $m[1];
		}

		if ( '' === $target ) {
			return '';
		}
		return self::resolve_url( $target, $base_url );
	}

	/**
	 * 相対URLを基準URLに対して絶対URL化する。
	 */
	private static function resolve_url( $target, $base_url ) {
		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}
		$base = wp_parse_url( $base_url );
		if ( empty( $base['host'] ) || empty( $base['scheme'] ) ) {
			return '';
		}
		$origin = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
		if ( 0 === strpos( $target, '//' ) ) {
			return $base['scheme'] . ':' . $target;
		}
		if ( 0 === strpos( $target, '/' ) ) {
			return $origin . $target;
		}
		$dir = isset( $base['path'] ) ? rtrim( dirname( $base['path'] ), '/' ) : '';
		return $origin . $dir . '/' . $target;
	}

	/**
	 * 投稿1件分の本文をまとめて検査。
	 *
	 * @param string $content 本文HTML。
	 * @param array  $options check_http / check_title / ad_only。
	 * @return array{links: int, issues: array[]} issuesの各要素: url, anchor_text, heading, problems[]
	 */
	public static function check_content( $content, $options = array() ) {
		$options = wp_parse_args( $options, array(
			'ad_only' => false,
			'post_id' => 0,
		) );

		$extracted = LMC_Extractor::extract_all( $content );
		$links     = $extracted['links'];

		// JS遷移ボタン（<div id="bt414"> 等）を解決してリンクとして追加。
		$button_issues = array();
		if ( LMC_Settings::get( 'detect_js_links' ) && $extracted['buttons'] ) {
			// JSリンクらしいid（bt等）を含む記事だけ、公開ページの実出力を取得して検証する。
			$needs_page = false;
			foreach ( $extracted['buttons'] as $button ) {
				if ( self::is_js_button( $button ) ) {
					$needs_page = true;
					break;
				}
			}

			$map = LMC_JS_Links::get_map( $content, $needs_page ? (int) $options['post_id'] : 0 );

			foreach ( $extracted['buttons'] as $button ) {
				if ( isset( $map[ $button['id'] ] ) ) {
					$links[] = array(
						'url'           => $map[ $button['id'] ],
						'anchor_text'   => $button['text'] . '（JSリンク id=' . $button['id'] . '）',
						'heading'       => $button['heading'],
						'is_image_link' => false,
					);
				} elseif ( self::is_js_button( $button ) ) {
					// どこを探しても遷移先が見つからない＝リンクが飛ばない状態。
					$button_issues[] = array(
						'url'         => '#' . $button['id'],
						'anchor_text' => $button['text'],
						'heading'     => $button['heading'],
						'problems'    => array( array(
							'code'     => 'js_unresolved',
							'severity' => self::SEVERITY_ERROR,
							'message'  => 'JSリンク（id=' . $button['id'] . '）の遷移先URLが見つかりません。記事内のscript・カスタムJS欄・テーマのJS（access.js等）・公開ページの出力を確認しましたが定義がありません',
						) ),
					);
				}
			}
		}

		// 除外リストに一致するリンクはチェック対象外。
		$exclude = LMC_Settings::exclude_patterns();
		if ( $exclude ) {
			$links = array_values( array_filter( $links, function ( $link ) use ( $exclude ) {
				return ! LMC_Extractor::matches_patterns( $link['url'], $exclude );
			} ) );
		}

		if ( $options['ad_only'] ) {
			$links = LMC_Extractor::filter_ad_links( $links, LMC_Settings::ad_domains() );
		}

		// ブラウザ検証リストに一致するリンクは、アクセス系チェックをスキップして
		// URLを記録し、Macの実ブラウザによる週次死活確認に回す（静的チェックは実施）。
		$browser_patterns = LMC_Settings::browser_check_patterns();
		$browser_links    = array();
		if ( $browser_patterns ) {
			foreach ( $links as $i => $link ) {
				if ( LMC_Extractor::matches_patterns( $link['url'], $browser_patterns ) ) {
					$browser_links[] = $link;
					unset( $links[ $i ] );
				}
			}
			$links = array_values( $links );
		}

		// 同一URLは1回だけ検査（結果は各出現箇所に反映）。
		$results = array();
		$checked = array();

		foreach ( $browser_links as $link ) {
			$problems = self::check_link( $link, array_merge( $options, array( 'check_http' => false ) ) );
			if ( $problems ) {
				$results[] = array(
					'url'         => $link['url'],
					'anchor_text' => $link['anchor_text'],
					'heading'     => $link['heading'],
					'problems'    => $problems,
				);
			}
			self::record_browser_target( $link['url'], (int) $options['post_id'] );
		}

		foreach ( $links as $link ) {
			$cache_key = $link['url'] . '|' . $link['heading'];
			if ( isset( $checked[ $cache_key ] ) ) {
				$problems = $checked[ $cache_key ];
			} else {
				$problems = self::check_link( $link, $options );
				$checked[ $cache_key ] = $problems;
			}


			if ( $problems ) {
				$results[] = array(
					'url'         => $link['url'],
					'anchor_text' => $link['anchor_text'],
					'heading'     => $link['heading'],
					'problems'    => $problems,
				);
			}
		}

		// 遷移先未解決のJSボタンは巡回対象の絞り込みに関係なく常に報告する。
		$results = array_merge( $results, $button_issues );

		return array(
			'links'  => count( $links ) + count( $browser_links ) + count( $button_issues ),
			'issues' => $results,
		);
	}

	/**
	 * ブラウザ検証対象のURLを記録する（Mac側の週次死活確認が取得する）。
	 *
	 * @param string $url     対象URL。
	 * @param int    $post_id 掲載記事ID。
	 */
	public static function record_browser_target( $url, $post_id ) {
		$targets = get_option( 'lmc_browser_targets', array() );
		if ( ! is_array( $targets ) ) {
			$targets = array();
		}

		$targets[ $url ] = array(
			'post_id'    => $post_id,
			'post_title' => $post_id ? get_the_title( $post_id ) : '',
			'seen'       => time(),
		);

		// 肥大化防止: 古いものから落として最大500件。
		if ( count( $targets ) > 500 ) {
			uasort( $targets, function ( $a, $b ) {
				return ( $a['seen'] ?? 0 ) <=> ( $b['seen'] ?? 0 );
			} );
			$targets = array_slice( $targets, count( $targets ) - 500, null, true );
		}

		update_option( 'lmc_browser_targets', $targets, false );
	}

	/**
	 * JSリンク（JSで遷移する要素）とみなすかどうか。
	 * id が「接頭辞+数字」（設定: js_button_id_prefix）に一致すれば true。
	 *
	 * @param array $button id を持つ要素情報。
	 */
	private static function is_js_button( $button ) {
		$prefixes = preg_split( '/[\s,]+/', (string) LMC_Settings::get( 'js_button_id_prefix' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $prefixes as $prefix ) {
			if ( preg_match( '/^' . preg_quote( $prefix, '/' ) . '\d+$/', (string) ( $button['id'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
