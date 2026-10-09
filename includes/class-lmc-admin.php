<?php
/**
 * 管理画面: スキャン結果一覧・手動スキャン・テスト通知、エディタ用スクリプトの読み込み。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMC_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'admin_post_lmc_manual_scan', array( __CLASS__, 'handle_manual_scan' ) );
		add_action( 'admin_post_lmc_test_notify', array( __CLASS__, 'handle_test_notify' ) );
		add_action( 'admin_post_lmc_refresh_jsmap', array( __CLASS__, 'handle_refresh_jsmap' ) );
	}

	public static function add_menu() {
		add_management_page(
			'リンク巡回スキャン結果',
			'リンク巡回結果',
			'edit_posts',
			'lmc-results',
			array( __CLASS__, 'render_results_page' )
		);
	}

	/**
	 * 投稿編集画面にチェック用スクリプトを読み込む。
	 */
	public static function enqueue_editor_assets( $hook ) {
		// 設定画面はスタイルのみ。
		if ( 'settings_page_lmc-settings' === $hook ) {
			wp_enqueue_style( 'lmc-admin', LMC_PLUGIN_URL . 'assets/css/admin.css', array(), LMC_VERSION );
			return;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'lmc-admin',
			LMC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			LMC_VERSION
		);

		wp_enqueue_script(
			'lmc-editor-check',
			LMC_PLUGIN_URL . 'assets/js/editor-check.js',
			array( 'wp-data' ),
			LMC_VERSION,
			true
		);

		wp_localize_script( 'lmc-editor-check', 'lmcConfig', array(
			'restUrl'      => esc_url_raw( rest_url( 'lmc/v1/check' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'postId'       => (int) get_the_ID(),
			'blockOnError' => (bool) LMC_Settings::get( 'block_on_error' ),
		) );
	}

	/**
	 * スキャン結果一覧ページ。
	 */
	public static function render_results_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$results  = get_option( LMC_Cron::OPTION_RESULTS, null );
		$progress = LMC_Cron::get_progress();
		$notice   = isset( $_GET['lmc_notice'] ) ? sanitize_key( $_GET['lmc_notice'] ) : '';
		$tab      = ( isset( $_GET['tab'] ) && 'jsmap' === $_GET['tab'] ) ? 'jsmap' : 'results';
		$base_url = admin_url( 'tools.php?page=lmc-results' );
		?>
		<div class="wrap">
			<h1>リンク巡回</h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
				<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo 'results' === $tab ? 'nav-tab-active' : ''; ?>">スキャン結果</a>
				<a href="<?php echo esc_url( $base_url . '&tab=jsmap' ); ?>" class="nav-tab <?php echo 'jsmap' === $tab ? 'nav-tab-active' : ''; ?>">JSリンク対応表</a>
			</nav>

			<?php if ( 'jsmap' === $tab ) : ?>
				<?php self::render_jsmap_tab( $notice ); ?>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( 'scan_started' === $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p>スキャンを開始しました。バックグラウンドで順次処理されます（数分かかる場合があります）。</p></div>
			<?php elseif ( 'test_sent' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p>テスト通知を送信しました。チャットを確認してください。</p></div>
			<?php elseif ( 'test_failed' === $notice ) : ?>
				<?php $test_error = get_transient( 'lmc_test_notify_error' ); delete_transient( 'lmc_test_notify_error' ); ?>
				<div class="notice notice-error is-dismissible">
					<p>テスト通知の送信に失敗しました。設定を確認してください。</p>
					<?php if ( $test_error ) : ?>
						<p><strong>失敗理由:</strong> <?php echo esc_html( $test_error ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php self::render_cron_health(); ?>

			<?php if ( $progress ) : ?>
				<div class="notice notice-info">
					<p>スキャン実行中: 残り <?php echo esc_html( $progress['remaining'] ); ?> / <?php echo esc_html( $progress['total'] ); ?> 記事。このページを再読み込みすると進行状況が更新されます。</p>
				</div>
			<?php endif; ?>

			<p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lmc_manual_scan' ), 'lmc_manual_scan' ) ); ?>" class="button button-primary">今すぐ全記事をスキャン</a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lmc_test_notify' ), 'lmc_test_notify' ) ); ?>" class="button">テスト通知を送信</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=lmc-settings' ) ); ?>" class="button">設定</a>
			</p>

			<?php if ( ! $results ) : ?>
				<p>まだスキャン結果がありません。「今すぐ全記事をスキャン」を押すか、週次の自動スキャンをお待ちください。</p>
			<?php else : ?>
				<p>
					最終スキャン: <?php echo esc_html( $results['finished_at'] ); ?>
					（対象 <?php echo esc_html( $results['total_posts'] ); ?> 記事 /
					問題 <?php echo esc_html( count( $results['issues'] ) ); ?> 件）
				</p>

				<?php if ( empty( $results['issues'] ) ) : ?>
					<div class="notice notice-success inline"><p>✅ 問題は見つかりませんでした。</p></div>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:20%;">記事</th>
								<th style="width:30%;">リンクURL</th>
								<th style="width:15%;">見出し</th>
								<th>問題</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $results['issues'] as $issue ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $issue['post_id'] ) ); ?>">
											<?php echo esc_html( $issue['post_title'] ?: '(無題)' ); ?>
										</a>
									</td>
									<td class="lmc-url-cell">
										<a href="<?php echo esc_url( $issue['url'] ); ?>" target="_blank" rel="noopener noreferrer">
											<?php echo esc_html( mb_strimwidth( $issue['url'], 0, 80, '…' ) ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $issue['heading'] ); ?></td>
									<td>
										<?php foreach ( $issue['problems'] as $problem ) : ?>
											<span class="lmc-badge lmc-badge-<?php echo esc_attr( $problem['severity'] ); ?>">
												<?php echo 'error' === $problem['severity'] ? 'エラー' : '警告'; ?>
											</span>
											<?php echo esc_html( $problem['message'] ); ?><br>
										<?php endforeach; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * JSリンク対応表タブ。
	 * テーマJS（access.js等）から抽出した id → 遷移先URL の一覧を表示する。
	 */
	private static function render_jsmap_tab( $notice ) {
		$map = LMC_JS_Links::theme_map();
		$src = LMC_JS_Links::theme_sources();
		uksort( $map, 'strnatcasecmp' );
		?>
		<?php if ( 'jsmap_refreshed' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p>テーマのJSファイルを再スキャンしました。</p></div>
		<?php endif; ?>

		<p>
			テーマ内のJSファイルから見つかった遷移定義: <strong><?php echo esc_html( count( $map ) ); ?> 件</strong>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lmc_refresh_jsmap' ), 'lmc_refresh_jsmap' ) ); ?>" class="button" style="margin-left:8px;">再スキャン</a>
		</p>

		<?php if ( empty( $map ) ) : ?>
			<p>遷移定義が見つかりませんでした。テーマフォルダ内に access.js などのJSファイルがあるか確認してください。</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:12%;">id</th>
						<th>遷移先URL</th>
						<th style="width:28%;">定義ファイル</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $map as $id => $url ) : ?>
						<tr>
							<td><code><?php echo esc_html( $id ); ?></code></td>
							<td class="lmc-url-cell">
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $url ); ?></a>
							</td>
							<td><code><?php echo esc_html( $src[ $id ] ?? '' ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="margin-top:10px;">記事本文内の &lt;script&gt; で定義されたJSリンクはこの表には含まれません（各記事のチェック時に個別に解決されます）。</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * 対応表の再スキャン（キャッシュ破棄）。
	 */
	public static function handle_refresh_jsmap() {
		if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'lmc_refresh_jsmap' ) ) {
			wp_die( '権限がありません' );
		}
		LMC_JS_Links::flush_cache();
		wp_safe_redirect( admin_url( 'tools.php?page=lmc-results&tab=jsmap&lmc_notice=jsmap_refreshed' ) );
		exit;
	}

	/**
	 * 自動巡回の状態表示。
	 * 通常は次回実行日時を1行だけ。巡回が止まっている疑いがあるときのみ警告を出す。
	 */
	private static function render_cron_health() {
		$next_ts = wp_next_scheduled( LMC_Cron::EVENT_WEEKLY );
		$results = get_option( LMC_Cron::OPTION_RESULTS, null );

		// 前回の巡回から8日以上経過していたら警告。
		$stale = false;
		if ( is_array( $results ) && ! empty( $results['finished_at'] ) ) {
			$finished = strtotime( get_gmt_from_date( $results['finished_at'] ) . ' UTC' );
			$stale    = $finished && ( time() - $finished ) > 8 * DAY_IN_SECONDS;
		}

		if ( $stale ) {
			?>
			<div class="notice notice-warning">
				<p>⚠️ 前回の巡回完了（<?php echo esc_html( $results['finished_at'] ); ?>）から8日以上経過しています。WP-Cronが動いていない可能性があります。</p>
			</div>
			<?php
		}
		?>
		<p style="color:#646970;">
			次回の自動巡回:
			<?php echo $next_ts ? esc_html( wp_date( 'Y年n月j日 (D) H:i', $next_ts ) ) : '未登録（ページを再読み込みすると自動登録されます）'; ?>
		</p>
		<?php
	}

	public static function handle_manual_scan() {
		if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'lmc_manual_scan' ) ) {
			wp_die( '権限がありません' );
		}
		LMC_Cron::trigger_manual_scan();
		wp_safe_redirect( admin_url( 'tools.php?page=lmc-results&lmc_notice=scan_started' ) );
		exit;
	}

	public static function handle_test_notify() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lmc_test_notify' ) ) {
			wp_die( '権限がありません' );
		}
		$result = LMC_Notifier::send_test();
		if ( is_wp_error( $result ) ) {
			set_transient( 'lmc_test_notify_error', $result->get_error_message(), 120 );
			$notice = 'test_failed';
		} else {
			$notice = 'test_sent';
		}
		wp_safe_redirect( admin_url( 'tools.php?page=lmc-results&lmc_notice=' . $notice ) );
		exit;
	}
}
