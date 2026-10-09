<?php
/**
 * 週1回の全記事リンク巡回スキャン。
 *
 * 流れ:
 *  lmc_weekly_scan（週次） → キュー作成 → lmc_scan_batch を連鎖実行
 *  → 全バッチ完了で結果保存＆チャット通知
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMC_Cron {

	const EVENT_WEEKLY = 'lmc_weekly_scan';
	const EVENT_BATCH  = 'lmc_scan_batch';
	const OPTION_QUEUE   = 'lmc_scan_queue';
	const OPTION_RESULTS = 'lmc_scan_results';

	public static function init() {
		add_action( self::EVENT_WEEKLY, array( __CLASS__, 'start_scan' ) );
		add_action( self::EVENT_BATCH, array( __CLASS__, 'process_batch' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * スケジュールの自己修復。
	 * 週次イベントが何らかの理由で消えていたら再登録し、
	 * 実行中キューが残っているのにバッチ予約がない（＝巡回が止まっている）場合は再開する。
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::EVENT_WEEKLY ) ) {
			$next = new DateTimeImmutable( 'next monday 06:00', wp_timezone() );
			wp_schedule_event( $next->getTimestamp(), 'weekly', self::EVENT_WEEKLY );
		}

		$queue = get_option( self::OPTION_QUEUE, null );
		if ( is_array( $queue ) && ! empty( $queue['ids'] ) && ! wp_next_scheduled( self::EVENT_BATCH ) ) {
			wp_schedule_single_event( time() + 10, self::EVENT_BATCH );
		}
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::EVENT_WEEKLY ) ) {
			// 次の月曜 午前6時（サイトのタイムゾーン）に初回実行し、以後毎週。
			$tz    = wp_timezone();
			$next  = new DateTimeImmutable( 'next monday 06:00', $tz );
			wp_schedule_event( $next->getTimestamp(), 'weekly', self::EVENT_WEEKLY );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::EVENT_WEEKLY );
		wp_clear_scheduled_hook( self::EVENT_BATCH );
		delete_option( self::OPTION_QUEUE );
	}

	/**
	 * スキャン開始: 対象記事IDのキューを作り、最初のバッチを予約。
	 */
	public static function start_scan() {
		// 実行中の場合は二重起動しない。
		$queue = get_option( self::OPTION_QUEUE, null );
		if ( is_array( $queue ) && ! empty( $queue['ids'] ) ) {
			return;
		}

		$post_types = (array) LMC_Settings::get( 'scan_post_types' );
		$ids        = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		update_option( self::OPTION_QUEUE, array(
			'ids'        => array_map( 'intval', $ids ),
			'total'      => count( $ids ),
			'issues'     => array(),
			'started_at' => current_time( 'mysql' ),
		), false );

		if ( ! wp_next_scheduled( self::EVENT_BATCH ) ) {
			wp_schedule_single_event( time() + 5, self::EVENT_BATCH );
		}
	}

	/**
	 * 1バッチ分の記事を処理して次バッチを予約。キューが空になったら通知。
	 */
	public static function process_batch() {
		$queue = get_option( self::OPTION_QUEUE, null );
		if ( ! is_array( $queue ) || ! isset( $queue['ids'] ) ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$batch_size = (int) LMC_Settings::get( 'batch_size' );
		$ad_only    = ( 'ad_only' === LMC_Settings::get( 'scan_scope' ) );
		$batch      = array_splice( $queue['ids'], 0, $batch_size );

		foreach ( $batch as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$result = LMC_Checker::check_content( $post->post_content, array(
				'ad_only' => $ad_only,
			) );

			foreach ( $result['issues'] as $issue ) {
				$queue['issues'][] = array(
					'post_id'    => $post_id,
					'post_title' => get_the_title( $post_id ),
					'url'        => $issue['url'],
					'heading'    => $issue['heading'],
					'problems'   => $issue['problems'],
				);
			}
		}

		if ( empty( $queue['ids'] ) ) {
			// スキャン完了: 結果を保存して通知。
			$results = array(
				'started_at'  => $queue['started_at'],
				'finished_at' => current_time( 'mysql' ),
				'total_posts' => $queue['total'],
				'issues'      => $queue['issues'],
			);
			update_option( self::OPTION_RESULTS, $results, false );
			delete_option( self::OPTION_QUEUE );

			LMC_Notifier::notify_scan_results( $results );
		} else {
			update_option( self::OPTION_QUEUE, $queue, false );
			wp_schedule_single_event( time() + 10, self::EVENT_BATCH );
		}
	}

	/**
	 * 手動スキャン開始（管理画面のボタンから）。
	 */
	public static function trigger_manual_scan() {
		delete_option( self::OPTION_QUEUE );
		self::start_scan();
	}

	/**
	 * 進行状況を返す（管理画面表示用）。
	 */
	public static function get_progress() {
		$queue = get_option( self::OPTION_QUEUE, null );
		if ( ! is_array( $queue ) || ! isset( $queue['ids'] ) ) {
			return null;
		}
		return array(
			'remaining' => count( $queue['ids'] ),
			'total'     => (int) $queue['total'],
		);
	}
}
