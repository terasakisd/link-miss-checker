<?php
/**
 * 設定の保存・取得と設定画面。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMC_Settings {

	const OPTION_KEY = 'lmc_settings';

	/**
	 * デフォルト設定。
	 */
	public static function defaults() {
		return array(
			'notify_service'       => 'none', // none | chatwork
			'chatwork_token'       => '',
			'chatwork_room_id'     => '',
			'ad_domains'           => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'scan_scope'           => 'ad_only', // ad_only | all（週次巡回の対象）
			'timeout'              => 10,
			'block_on_error'       => 1,
			'exclude_patterns'     => '',
			'detect_js_links'      => 1,
			'js_button_id_prefix'  => 'bt',
			'scan_post_types'      => array( 'post', 'page' ),
			'batch_size'           => 10,
		);
	}

	public static function get( $key = null ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), self::defaults() );
		if ( null === $key ) {
			return $settings;
		}
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * 広告ドメインを配列で取得。
	 */
	public static function ad_domains() {
		$raw = (string) self::get( 'ad_domains' );
		$domains = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $raw ) ) );
		return array_values( array_unique( $domains ) );
	}

	/**
	 * 除外パターンを配列で取得。
	 */
	public static function exclude_patterns() {
		$raw = (string) self::get( 'exclude_patterns' );
		$patterns = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $raw ) ) );
		return array_values( array_unique( $patterns ) );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
	}

	/**
	 * バージョン更新時の設定移行。
	 * 旧バージョンの既定値（ASPドメイン一覧）が未編集のまま保存されている場合、
	 * 新しい既定値（サイト自身のドメイン）へ置き換える。
	 */
	public static function maybe_migrate() {
		if ( get_option( 'lmc_db_version' ) === LMC_VERSION ) {
			return;
		}

		$saved = get_option( self::OPTION_KEY, null );
		if ( is_array( $saved ) && isset( $saved['ad_domains'] ) ) {
			$old_defaults = array(
				'px.a8.net', 'a8.net', 'rpx.a8.net', 'amzn.to', 'amazon.co.jp',
				'hb.afl.rakuten.co.jp', 'af.moshimo.com', 'h.accesstrade.net',
				't.felmat.net', 'link-a.net', 'link-ag.net',
				'ck.jp.ap.valuecommerce.com', 'j-a-net.jp', 't.afi-b.com', '/code/',
			);
			$lines = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $saved['ad_domains'] ) ) );

			// 全行が旧既定値由来（＝手で編集していない）なら新既定値へ。
			if ( $lines && ! array_diff( $lines, $old_defaults ) ) {
				$saved['ad_domains'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				update_option( self::OPTION_KEY, $saved );
			}
		}

		update_option( 'lmc_db_version', LMC_VERSION, false );
	}

	public static function add_menu() {
		add_options_page(
			'リンクミス発見ツール 設定',
			'リンクミス発見',
			'manage_options',
			'lmc-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting( 'lmc_settings_group', self::OPTION_KEY, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
		) );
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$out      = array();

		$out['notify_service']  = ( 'chatwork' === ( $input['notify_service'] ?? '' ) ) ? 'chatwork' : 'none';
		$out['chatwork_token']  = sanitize_text_field( $input['chatwork_token'] ?? '' );
		$out['chatwork_room_id'] = sanitize_text_field( $input['chatwork_room_id'] ?? '' );
		$out['ad_domains']      = sanitize_textarea_field( $input['ad_domains'] ?? $defaults['ad_domains'] );
		$out['scan_scope']      = in_array( $input['scan_scope'] ?? 'ad_only', array( 'ad_only', 'all' ), true ) ? $input['scan_scope'] : 'ad_only';
		$out['timeout']         = max( 3, min( 30, (int) ( $input['timeout'] ?? 10 ) ) );
		$out['block_on_error']  = empty( $input['block_on_error'] ) ? 0 : 1;
		$out['exclude_patterns'] = sanitize_textarea_field( $input['exclude_patterns'] ?? '' );
		$out['detect_js_links'] = empty( $input['detect_js_links'] ) ? 0 : 1;
		$out['js_button_id_prefix'] = sanitize_text_field( $input['js_button_id_prefix'] ?? 'bt' );

		// テーマJSの対応表キャッシュは設定変更時に作り直す。
		if ( class_exists( 'LMC_JS_Links' ) ) {
			LMC_JS_Links::flush_cache();
		}
		$post_types             = array_map( 'sanitize_key', (array) ( $input['scan_post_types'] ?? array( 'post' ) ) );
		$out['scan_post_types'] = array_values( array_intersect( $post_types, get_post_types( array( 'public' => true ) ) ) );
		if ( empty( $out['scan_post_types'] ) ) {
			$out['scan_post_types'] = array( 'post' );
		}
		$out['batch_size']      = max( 1, min( 50, (int) ( $input['batch_size'] ?? 10 ) ) );

		return $out;
	}

	/**
	 * トグルスイッチを出力。
	 */
	private static function toggle( $key, $label, $checked, $value = '1' ) {
		?>
		<span class="lmc-field-label"><?php echo esc_html( $label ); ?></span>
		<label class="lmc-toggle">
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $checked ); ?>>
			<span></span>
		</label>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::get();
		$k = self::OPTION_KEY;
		?>
		<div class="wrap lmc-settings-wrap">
			<h1>リンクミス発見ツール</h1>
			<p class="lmc-sub">記事内のリンクを自動チェックし、問題があれば更新を止めて通知します。</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'lmc_settings_group' ); ?>
				<div class="lmc-cards">

					<section class="lmc-card">
						<h2>更新前チェック</h2>
						<div class="lmc-field"><?php self::toggle( 'block_on_error', 'エラー時に更新を止める', $s['block_on_error'] ); ?></div>
						<div class="lmc-field">
							<span class="lmc-field-label">タイムアウト</span>
							<span><input type="number" min="3" max="30" name="<?php echo esc_attr( $k ); ?>[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>" class="lmc-num"> 秒</span>
						</div>
						<div class="lmc-field lmc-field-block">
							<span class="lmc-field-label">除外リスト</span>
							<textarea name="<?php echo esc_attr( $k ); ?>[exclude_patterns]" rows="4" placeholder="example.com&#10;/campaign/"><?php echo esc_textarea( $s['exclude_patterns'] ); ?></textarea>
							<span class="lmc-hint">ここに書いたリンクはチェックしません。1行1つ。example.com = ドメイン一致 ／ /path/ = URL部分一致。bot対策で403警告が出続けるサイトなどに</span>
						</div>
					</section>

					<section class="lmc-card">
						<h2>JSリンク</h2>
						<p class="lmc-card-desc">&lt;div id="bt414"&gt; のようにJSで遷移する要素の検査</p>
						<div class="lmc-field"><?php self::toggle( 'detect_js_links', 'JSリンクを検査する', $s['detect_js_links'] ); ?></div>
						<div class="lmc-field">
							<span class="lmc-field-label">idの接頭辞</span>
							<input type="text" name="<?php echo esc_attr( $k ); ?>[js_button_id_prefix]" value="<?php echo esc_attr( $s['js_button_id_prefix'] ); ?>" placeholder="bt">
							<span class="lmc-hint">bt → id="bt1" "bt27" などに一致（複数はカンマ区切り）</span>
						</div>
					</section>

					<section class="lmc-card">
						<h2>週次巡回</h2>
						<div class="lmc-field">
							<span class="lmc-field-label">巡回対象</span>
							<span class="lmc-radios">
								<label><input type="radio" name="<?php echo esc_attr( $k ); ?>[scan_scope]" value="ad_only" <?php checked( $s['scan_scope'], 'ad_only' ); ?>> 広告リンクのみ</label>
								<label><input type="radio" name="<?php echo esc_attr( $k ); ?>[scan_scope]" value="all" <?php checked( $s['scan_scope'], 'all' ); ?>> すべての外部リンク</label>
							</span>
						</div>
						<div class="lmc-field lmc-field-block">
							<span class="lmc-field-label">広告リンクのパターン</span>
							<textarea name="<?php echo esc_attr( $k ); ?>[ad_domains]" rows="6"><?php echo esc_textarea( $s['ad_domains'] ); ?></textarea>
							<span class="lmc-hint">1行1つ。example.com = ドメイン一致 ／ /code/ = URL部分一致（自ドメインのクッションページ用）</span>
						</div>
						<div class="lmc-field">
							<span class="lmc-field-label">投稿タイプ</span>
							<span class="lmc-radios">
								<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?>
									<?php if ( 'attachment' === $pt->name ) { continue; } ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $k ); ?>[scan_post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $s['scan_post_types'], true ) ); ?>>
										<?php echo esc_html( $pt->labels->name ); ?>
									</label>
								<?php endforeach; ?>
							</span>
						</div>
						<div class="lmc-field">
							<span class="lmc-field-label">一度に処理する記事数</span>
							<input type="number" min="1" max="50" name="<?php echo esc_attr( $k ); ?>[batch_size]" value="<?php echo esc_attr( $s['batch_size'] ); ?>" class="lmc-num">
						</div>
					</section>

					<section class="lmc-card">
						<h2>Chatwork通知</h2>
						<div class="lmc-field"><?php self::toggle( 'notify_service', '週次結果を通知する', 'chatwork' === $s['notify_service'], 'chatwork' ); ?></div>
						<div class="lmc-field">
							<span class="lmc-field-label">APIトークン</span>
							<input type="text" name="<?php echo esc_attr( $k ); ?>[chatwork_token]" value="<?php echo esc_attr( $s['chatwork_token'] ); ?>" placeholder="サービス連携 → APIトークン">
						</div>
						<div class="lmc-field">
							<span class="lmc-field-label">ルームID</span>
							<input type="text" name="<?php echo esc_attr( $k ); ?>[chatwork_room_id]" value="<?php echo esc_attr( $s['chatwork_room_id'] ); ?>">
							<span class="lmc-hint">チャットURLの「#!rid」の後ろの数字。送信確認は「ツール → リンク巡回結果」の<a href="<?php echo esc_url( admin_url( 'tools.php?page=lmc-results' ) ); ?>">テスト通知</a>から</span>
						</div>
					</section>

				</div>
				<?php submit_button( '設定を保存' ); ?>
			</form>
		</div>
		<?php
	}

}
