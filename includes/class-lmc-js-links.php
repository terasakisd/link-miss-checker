<?php
/**
 * JSで遷移するボタン（例: <div id="bt414"><span class="link-btn">詳細</span></div>）の
 * id → 遷移先URL の対応表を探索する。
 *
 * 探索場所:
 *  1. 記事本文内の <script> タグ
 *  2. 子テーマ・親テーマフォルダ内の .js ファイル（access.js など）
 *
 * 対応するJSの書き方（いずれも id の近くにURLがあれば拾う）:
 *  - document.getElementById('bt414').onclick = ... location.href = 'https://...'
 *  - $('#bt414').click(function(){ window.open('https://...'); })
 *  - { bt414: 'https://...' } のようなオブジェクト形式
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMC_JS_Links {

	const TRANSIENT_KEY = 'lmc_js_map';

	/**
	 * id => URL の対応表を取得（テーマJS + 記事内script）。
	 * 記事内の定義を優先する。
	 *
	 * @param string $content 記事本文HTML。
	 * @return array<string,string>
	 */
	public static function get_map( $content = '' ) {
		$map = self::theme_map();
		foreach ( self::content_map( $content ) as $id => $url ) {
			$map[ $id ] = $url;
		}
		return $map;
	}

	/**
	 * 記事本文内の <script> から対応表を抽出。
	 */
	public static function content_map( $html ) {
		if ( false === stripos( (string) $html, '<script' ) ) {
			return array();
		}
		preg_match_all( '/<script\b[^>]*>(.*?)<\/script>/is', (string) $html, $matches );

		$map = array();
		foreach ( $matches[1] as $code ) {
			foreach ( self::parse_js( $code ) as $id => $url ) {
				if ( ! isset( $map[ $id ] ) ) {
					$map[ $id ] = $url;
				}
			}
		}
		return $map;
	}

	/**
	 * 子テーマ・親テーマ内の .js ファイルから対応表を抽出（キャッシュつき）。
	 *
	 * @return array<string,string> id => URL
	 */
	public static function theme_map() {
		return self::theme_data()['map'];
	}

	/**
	 * 各idがどのJSファイルで定義されているか。
	 *
	 * @return array<string,string> id => テーマからの相対パス
	 */
	public static function theme_sources() {
		return self::theme_data()['src'];
	}

	/**
	 * テーマJSのスキャン本体（map/src をまとめてキャッシュ）。
	 *
	 * @return array{map: array<string,string>, src: array<string,string>}
	 */
	private static function theme_data() {
		$files = self::theme_js_files();
		$sig   = md5( wp_json_encode( $files ) );

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $sig && isset( $cached['map'], $cached['src'] ) ) {
			return array( 'map' => (array) $cached['map'], 'src' => (array) $cached['src'] );
		}

		$theme_root = trailingslashit( get_theme_root() );
		$map = array();
		$src = array();
		foreach ( array_keys( $files ) as $path ) {
			$code = @file_get_contents( $path );
			if ( false === $code ) {
				continue;
			}
			$relative = str_replace( $theme_root, '', $path );
			foreach ( self::parse_js( $code ) as $id => $url ) {
				if ( ! isset( $map[ $id ] ) ) {
					$map[ $id ] = $url;
					$src[ $id ] = $relative;
				}
			}
		}

		set_transient( self::TRANSIENT_KEY, array( 'sig' => $sig, 'map' => $map, 'src' => $src ), 12 * HOUR_IN_SECONDS );
		return array( 'map' => $map, 'src' => $src );
	}

	/**
	 * テーマ内の .js ファイル一覧（path => mtime）。子テーマ優先。
	 */
	private static function theme_js_files() {
		$dirs  = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$files = array();

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
				);
			} catch ( Exception $e ) {
				continue;
			}
			foreach ( $iterator as $file ) {
				/** @var SplFileInfo $file */
				if ( ! $file->isFile() || 'js' !== strtolower( $file->getExtension() ) ) {
					continue;
				}
				$path = $file->getPathname();
				// node_modules・1MB超は対象外。
				if ( false !== strpos( $path, 'node_modules' ) || $file->getSize() > 1048576 ) {
					continue;
				}
				if ( ! isset( $files[ $path ] ) ) {
					$files[ $path ] = $file->getMTime();
				}
			}
		}

		ksort( $files );
		return $files;
	}

	/**
	 * JSコードから id => URL の対応を抽出する。
	 *
	 * @param string $code JSソース。
	 * @return array<string,string>
	 */
	public static function parse_js( $code ) {
		$map  = array();
		$code = (string) $code;

		// --- パターン1: オブジェクト形式  bt414: 'https://...' / 'bt414': "https://..." ---
		if ( preg_match_all(
			'/[\'"]?([A-Za-z][\w\-]*)[\'"]?\s*:\s*[\'"](https?:\/\/[^\'"]+)[\'"]/',
			$code, $mm, PREG_SET_ORDER
		) ) {
			foreach ( $mm as $m ) {
				if ( ! isset( $map[ $m[1] ] ) ) {
					$map[ $m[1] ] = $m[2];
				}
			}
		}

		// --- パターン2: id参照（getElementById / $('#id') / querySelector('#id')）の後方近傍にあるURL ---
		if ( preg_match_all(
			'/getElementById\(\s*[\'"]([\w\-]+)[\'"]\s*\)|[\'"]#([\w\-]+)[\'"]/',
			$code, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		) ) {
			foreach ( $mm as $m ) {
				$id = '';
				if ( isset( $m[1] ) && '' !== $m[1][0] ) {
					$id = $m[1][0];
				} elseif ( isset( $m[2] ) && '' !== $m[2][0] ) {
					$id = $m[2][0];
				}
				if ( '' === $id || isset( $map[ $id ] ) ) {
					continue;
				}

				// id参照位置から500文字以内のURLを遷移先とみなす。
				$offset = $m[0][1] + strlen( $m[0][0] );
				$window = substr( $code, $offset, 500 );

				if ( preg_match( '/https?:\/\/[^\'"\s\\\\<>\)]+/', $window, $u ) ) {
					$map[ $id ] = rtrim( $u[0], ';,' );
				} elseif ( preg_match( '/(?:location\.href\s*=|window\.open\(|location\.assign\()\s*[\'"](\/[^\'"]*)[\'"]/', $window, $u ) ) {
					// 相対パス遷移（location.href = '/go/xxx' 等）。
					$map[ $id ] = $u[1];
				}
			}
		}

		return $map;
	}

	/**
	 * キャッシュを破棄（テーマJS更新後に手動で反映したい場合用）。
	 */
	public static function flush_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}
}
