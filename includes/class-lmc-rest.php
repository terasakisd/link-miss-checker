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
	}

	public static function register_routes() {
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

	public static function handle_check( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$content = (string) $request->get_param( 'content' );

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'lmc_forbidden', '権限がありません', array( 'status' => 403 ) );
		}

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

		$result = LMC_Checker::check_content( $content );

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

		return rest_ensure_response( $response );
	}
}
