<?php
/**
 * Plugin Name: フェリー手当管理
 * Plugin URI:  https://example.com/ferry-allowance-manager
 * Description: フェリー利用実績を登録し、航路別のフェリー手当を算出・月次集計するプラグイン。employee-manager と連携して動作します。
 * Version:     1.0.0
 * Author:      有限会社たんぽぽ運送
 * License:     GPL-2.0+
 * Text Domain: ferry-allowance-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ===== 定数定義 =====
define( 'FA_VERSION',     '1.0.0' );
define( 'FA_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'FA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'FA_PLUGIN_FILE', __FILE__ );

// ===== 依存ファイルの読み込み =====
require_once FA_PLUGIN_DIR . 'includes/class-db-install.php';
require_once FA_PLUGIN_DIR . 'includes/class-employee-bridge.php';
require_once FA_PLUGIN_DIR . 'includes/class-vehicle.php';
require_once FA_PLUGIN_DIR . 'includes/class-route.php';
require_once FA_PLUGIN_DIR . 'includes/class-record.php';
require_once FA_PLUGIN_DIR . 'admin/class-admin-menu.php';   // 追加
require_once FA_PLUGIN_DIR . 'includes/class-summary.php';   // 追加
// ===== 有効化フック =====
register_activation_hook( __FILE__, 'fa_activate' );

/**
 * 有効化処理
 * employee-manager が有効でない場合は自身を無効化して中断する
 */
function fa_activate() {
    if ( ! function_exists( 'emp_get_active_employees' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            '<p><strong>フェリー手当管理</strong> を有効化するには、先に <strong>employee-manager（社員情報管理システム）</strong> プラグインを有効化してください。</p>',
            'プラグインの有効化エラー',
            array( 'back_link' => true )
        );
    }

    FA_DB_Install::activate();
}

// ===== プラグイン初期化 =====
add_action( 'plugins_loaded', 'fa_init' );

function fa_init() {
    // 依存プラグインが無効化された場合は通知のみ出して機能を止める
    if ( ! function_exists( 'emp_get_active_employees' ) ) {
        add_action( 'admin_notices', 'fa_missing_dependency_notice' );
        return;
    }

    // バージョンチェック：DBマイグレーションが必要な場合に対応
    if ( get_option( 'fa_db_version' ) !== FA_VERSION ) {
        FA_DB_Install::activate();
    }

        // 管理画面メニュー
    if ( is_admin() ) {
        new FA_Admin_Menu();   // 追加
    }
}

function fa_missing_dependency_notice() {
    echo '<div class="notice notice-error"><p>'
        . '<strong>フェリー手当管理:</strong> '
        . '<strong>employee-manager（社員情報管理システム）</strong> プラグインが必要です。先に有効化してください。'
        . '</p></div>';
}