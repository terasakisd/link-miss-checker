<?php
/**
 * GitHubリリースからのプラグイン自動更新。
 *
 * 仕組み:
 *  - GitHubの最新リリース（tag = vX.Y.Z）を6時間ごとに確認
 *  - 現在のバージョンより新しければ、WordPressの更新一覧に表示
 *  - リリースに添付された link-miss-checker.zip を更新パッケージとして使用
 *
 * リリース手順（開発側）:
 *  1. link-miss-checker.php の Version を上げる
 *  2. link-miss-checker/ フォルダをzip化
 *  3. gh release create vX.Y.Z link-miss-checker.zip --notes "変更内容"
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMC_Updater {

	const REPO          = 'terasakisd/link-miss-checker';
	const ASSET_NAME    = 'link-miss-checker.zip';
	const TRANSIENT_KEY = 'lmc_github_release';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
	}

	/**
	 * GitHubの最新リリース情報を取得（6時間キャッシュ）。
	 *
	 * @return array{version: string, package: string, url: string, body: string}|null
	 */
	public static function get_latest_release() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}

		$response = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'link-miss-checker/' . LMC_VERSION,
			),
		) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// 失敗時は1時間だけ空キャッシュして連続アクセスを防ぐ。
			set_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}

		// 添付アセット（link-miss-checker.zip）を探す。
		$package = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			set_transient( self::TRANSIENT_KEY, array(), HOUR_IN_SECONDS );
			return null;
		}

		$release = array(
			'version' => ltrim( (string) $data['tag_name'], 'vV' ),
			'package' => $package,
			'url'     => (string) ( $data['html_url'] ?? 'https://github.com/' . self::REPO ),
			'body'    => (string) ( $data['body'] ?? '' ),
		);

		set_transient( self::TRANSIENT_KEY, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * WordPressの更新チェックに新バージョンを注入。
	 */
	public static function check_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::get_latest_release();
		if ( ! $release || ! version_compare( $release['version'], LMC_VERSION, '>' ) ) {
			return $transient;
		}

		$plugin_file = plugin_basename( LMC_PLUGIN_FILE );

		$transient->response[ $plugin_file ] = (object) array(
			'slug'        => 'link-miss-checker',
			'plugin'      => $plugin_file,
			'new_version' => $release['version'],
			'package'     => $release['package'],
			'url'         => $release['url'],
		);

		return $transient;
	}

	/**
	 * 「詳細を表示」ポップアップの情報。
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'link-miss-checker' !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = self::get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'リンクミス発見ツール',
			'slug'          => 'link-miss-checker',
			'version'       => $release['version'],
			'author'        => 'Southerndia',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '記事更新前にリンク切れ・末尾欠け・パラメータ重複・旧スラッグ・商品名不一致を自動チェックし、問題があれば更新を止めます。週1回の全記事巡回とChatwork通知も行います。',
				'changelog'   => nl2br( esc_html( $release['body'] ) ),
			),
		);
	}
}
