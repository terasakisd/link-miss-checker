<?php
/**
 * チャット通知（Slack / Discord / Chatwork / 汎用Webhook）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMC_Notifier {

	/**
	 * パトロール報告の冒頭あいさつ。
	 */
	private static function intro() {
		return "サイトでリンクが正常かパトロールしてきたにゃん！8-)(*)\n結果はこちらにゃ！:^)\n";
	}

	/**
	 * 週次スキャン結果を通知。
	 *
	 * @param array $results LMC_Cron が保存する結果配列。
	 */
	public static function notify_scan_results( $results ) {
		$service = LMC_Settings::get( 'notify_service' );
		if ( 'none' === $service ) {
			return;
		}

		$site  = get_bloginfo( 'name' );
		$count = count( $results['issues'] );
		$admin_url = admin_url( 'tools.php?page=lmc-results' );

		if ( 0 === $count ) {
			$body = self::intro()
				. '[info][title]✅ [' . $site . '] [/title]'
				. "リンクパトロール完了にゃ！(y)(whew)\n"
				. sprintf( '対象 %d 記事、問題は見つからなかったにゃ。', $results['total_posts'] )
				. '[/info]';
			self::send( $body );
			return;
		}

		{
			// 記事ごとにまとめる。1リンク = 「原因ラベル + URL」の2行。最大15件。
			$by_post = array();
			$shown   = 0;
			foreach ( $results['issues'] as $issue ) {
				if ( $shown >= 15 ) {
					break;
				}
				$post_id = $issue['post_id'];
				if ( ! isset( $by_post[ $post_id ] ) ) {
					$by_post[ $post_id ] = array( 'title' => $issue['post_title'], 'lines' => array() );
				}

				$labels   = array();
				$is_error = false;
				foreach ( $issue['problems'] as $problem ) {
					$labels[] = self::short_label( $problem );
					if ( 'error' === $problem['severity'] ) {
						$is_error = true;
					}
				}
				$mark = $is_error ? '❌' : '⚠️';
				$by_post[ $post_id ]['lines'][] = $mark . ' ' . implode( '・', array_unique( $labels ) ) . "\n　" . $issue['url'];
				$shown++;
			}

			$blocks = array();
			foreach ( $by_post as $post ) {
				$blocks[] = '■ ' . $post['title'] . "\n" . implode( "\n", $post['lines'] );
			}

			$footer = '';
			if ( $count > $shown ) {
				$footer .= sprintf( "…ほか %d件\n", $count - $shown );
			}
			$footer .= '詳細: ' . $admin_url;

			$body = self::intro()
				. '[info][title]🚨 [' . $site . '] リンクの問題 ' . $count . '件[/title]' . "\n"
				. implode( "\n\n", $blocks )
				. "\n\n" . $footer
				. '[/info]';
		}

		self::send( $body );
	}

	/**
	 * 通知用の短い原因ラベル。詳細な説明文は管理画面で確認する前提。
	 *
	 * @param array $problem code / severity / message を持つ問題1件。
	 */
	private static function short_label( $problem ) {
		$message = (string) ( $problem['message'] ?? '' );

		switch ( $problem['code'] ?? '' ) {
			case 'http_error':
				if ( preg_match( '/HTTP (\d+)/', $message, $m ) ) {
					return 'リンク切れ（' . $m[1] . '）';
				}
				return 'アクセス不可';
			case 'dup_param':
				if ( preg_match( '/: (.+)$/u', $message, $m ) ) {
					return 'パラメータ重複（' . $m[1] . '）';
				}
				return 'パラメータ重複';
			case 'truncated':
				return 'URL欠けの疑い';
			case 'invalid_url':
				return 'URL形式が不正';
			case 'old_slug':
				return '旧スラッグ';
			case 'internal_not_public':
				return 'リンク先が非公開';
			case 'redirect_to_top':
				return 'トップページへ転送（削除の可能性）';
			case 'http_blocked':
				return 'アクセス拒否（bot対策の可能性）';
			case 'js_unresolved':
				return 'JSリンクの遷移先なし';
		}

		return $message;
	}

	/**
	 * 整形済み本文（Chatwork記法を含む）をそのまま送信。
	 *
	 * @param string $body 送信する本文。
	 * @return true|WP_Error
	 */
	public static function send( $body ) {
		if ( 'chatwork' !== LMC_Settings::get( 'notify_service' ) ) {
			return true;
		}

		$room_id = rawurlencode( LMC_Settings::get( 'chatwork_room_id' ) );

		$response = wp_remote_post( "https://api.chatwork.com/v2/rooms/{$room_id}/messages", array(
			'timeout' => (int) LMC_Settings::get( 'timeout' ),
			'headers' => array( 'X-ChatWorkToken' => LMC_Settings::get( 'chatwork_token' ) ),
			'body'    => array( 'body' => $body ),
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[link-miss-checker] 通知送信に失敗: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$err = new WP_Error( 'lmc_notify_failed', '通知先がエラーを返しました（HTTP ' . $code . '）' );
			error_log( '[link-miss-checker] ' . $err->get_error_message() );
			return $err;
		}

		return true;
	}

	/**
	 * テスト通知。
	 */
	public static function send_test() {
		$body = '[info][title]🔔 [' . get_bloginfo( 'name' ) . '] テスト通知[/title]' . "\n"
			. 'テスト通知にゃ！この通知が届いていれば設定は正常にゃ。(y)[/info]';
		return self::send( $body );
	}
}
