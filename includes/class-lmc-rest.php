<?php
/**
 * エディタの「更新前チェック」用 REST API。
 *
 * POST /wp-json/lmc/v1/check
 *   body: { post_id: number, content: string }
 *   return: { links: number, issues: [...], has_error: bool, checked_at: string }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMC_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// REST APIがWAF等でブロックされるサイト向けのフォールバック経路。
		add_action( 'wp_ajax_lmc_check', array( __CLASS__, 'handle_ajax_check' ) );
		// 「アクセスできるのにエラーになる」ボタン: ドメインをブラウザ検証リストへ登録。
		add_action( 'wp_ajax_lmc_false_error', array( __CLASS__, 'handle_false_error' ) );
	}

	/**
	 * admin-ajax.php 経由のチェック（RESTが403等で使えないサイト用）。
	 */
	public static function handle_ajax_check() {
		check_ajax_referer( 'lmc_check', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json( array( 'error' => '権限がありません' ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$content = isset( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : '';

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json( array( 'error' => '権限がありません' ), 403 );
		}

		wp_send_json( self::run_check( $post_id, $content ) );
	}

	public static function register_routes() {
		// ブラウザ検証対象URLの一覧（Mac側の週次死活確認が取得する。
		// URLは公開記事に載っているものなので公開可）。
		register_rest_route( 'lmc/v1', '/browser-targets', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'handle_browser_targets' ),
		) );

		register_rest_route( 'lmc/v1', '/check', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_check' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'post_id' => array(
					'type'     => 'integer',
					'required' => false,
					'default'  => 0,
				),
				'content' => array(
					'type'     => 'string',
					'required' => false,
					'default'  => '',
				),
			),
		) );
	}

	/**
	 * 「アクセスできるのにエラーになる」の報告を受けて、
	 * ドメインをブラウザ検証リストに登録し、URLを週次死活確認の対象として記録する。
	 */
	public static function handle_false_error() {
		check_ajax_referer( 'lmc_false_error', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json( array( 'error' => '権限がありません' ), 403 );
		}

		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			wp_send_json( array( 'error' => 'URLが不正です' ), 400 );
		}

		$patterns = LMC_Settings::browser_check_patterns();
		if ( ! in_array( $host, $patterns, true ) ) {
			$settings = get_option( LMC_Settings::OPTION_KEY, array() );
			$current  = trim( (string) ( $settings['browser_check_patterns'] ?? '' ) );
			$settings['browser_check_patterns'] = trim( $current . "\n" . $host );
			update_option( LMC_Settings::OPTION_KEY, $settings );
		}

		LMC_Checker::record_browser_target( $url, $post_id );

		wp_send_json( array( 'ok' => true, 'host' => $host ) );
	}

	/**
	 * ブラウザ検証対象URLの一覧を返す。
	 */
	public static function handle_browser_targets() {
		$targets = get_option( 'lmc_browser_targets', array() );
		$list    = array();
		if ( is_array( $targets ) ) {
			foreach ( $targets as $url => $info ) {
				$list[] = array(
					'url'        => $url,
					'post_title' => (string) ( $info['post_title'] ?? '' ),
				);
			}
		}
		return rest_ensure_response( array(
			'site'    => get_bloginfo( 'name' ),
			'targets' => $list,
		) );
	}

	public static function handle_check( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$content = (string) $request->get_param( 'content' );

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'lmc_forbidden', '権限がありません', array( 'status' => 403 ) );
		}

		return rest_ensure_response( self::run_check( $post_id, $content ) );
	}

	/**
	 * チェック本体（RESTとadmin-ajaxで共通）。
	 *
	 * @param int    $post_id 投稿ID。
	 * @param string $content 本文HTML（空なら保存済み本文を使用）。
	 * @return array links / issues / has_error / checked_at
	 */
	public static function run_check( $post_id, $content ) {
		// 本文が送られてこなければ保存済みの本文を使う。
		if ( '' === $content && $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$content = $post->post_content;
			}
		}

		// チェック中のタイムアウト対策で実行時間を拡張。
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$result = LMC_Checker::check_content( $content, array( 'post_id' => $post_id ) );

		$has_error = false;
		foreach ( $result['issues'] as $issue ) {
			foreach ( $issue['problems'] as $problem ) {
				if ( LMC_Checker::SEVERITY_ERROR === $problem['severity'] ) {
					$has_error = true;
					break 2;
				}
			}
		}

		$response = array(
			'links'      => $result['links'],
			'issues'     => $result['issues'],
			'has_error'  => $has_error,
			'truncated'  => ! empty( $result['truncated'] ),
			'checked_at' => current_time( 'mysql' ),
		);

		// 最終チェック結果を投稿メタに記録（監査用）。
		if ( $post_id ) {
			update_post_meta( $post_id, '_lmc_last_check', array(
				'checked_at'   => $response['checked_at'],
				'content_hash' => md5( $content ),
				'links'        => $result['links'],
				'issue_count'  => count( $result['issues'] ),
				'has_error'    => $has_error,
			) );
		}

		return $response;
	}
}
