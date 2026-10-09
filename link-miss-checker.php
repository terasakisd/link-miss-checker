<?php
/**
 * Plugin Name: リンクミス発見ツール
 * Plugin URI:  https://github.com/terasakisd/link-miss-checker
 * Description: 記事更新前にリンク切れ・末尾欠け・パラメータ重複・旧スラッグ・商品名不一致を自動チェックし、問題があれば更新を止めます。週1回の全記事巡回とチャット通知も行います。
 * Version:     1.16.1
 * Author:      Southerndia
 * License:     GPL-2.0-or-later
 * Text Domain: link-miss-checker
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LMC_VERSION', '1.16.1' );
define( 'LMC_PLUGIN_FILE', __FILE__ );
define( 'LMC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LMC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once LMC_PLUGIN_DIR . 'includes/class-lmc-settings.php';
require_once LMC_PLUGIN_DIR . 'includes/class-lmc-extractor.php';
require_once LMC_PLUGIN_DIR . 'includes/class-lmc-js-links.php';
require_once LMC_PLUGIN_DIR . 'includes/class-lmc-checker.php';
require_once LMC_PLUGIN_DIR . 'includes/class-lmc-rest.php';
require_once LMC_PLUGIN_DIR . 'includes/class-lmc-cron.php';
require_once LMC_PLUGIN_DIR . 'includes/class-lmc-notifier.php';
require_once LMC_PLUGIN_DIR . 'includes/class-lmc-admin.php';
require_once LMC_PLUGIN_DIR . 'includes/class-lmc-updater.php';

/**
 * プラグイン本体の起動。
 */
final class Link_Miss_Checker {

	/** @var Link_Miss_Checker|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		LMC_Settings::init();
		LMC_Rest::init();
		LMC_Cron::init();
		LMC_Admin::init();
		LMC_Updater::init();
	}
}

register_activation_hook( __FILE__, array( 'LMC_Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LMC_Cron', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Link_Miss_Checker', 'instance' ) );
