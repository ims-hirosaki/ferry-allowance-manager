<?php
/**
 * プラグイン削除時の処理
 * 「無効化」では実行されず、管理画面からの「削除」時のみ実行される。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-db-install.php';

// 定数が未定義の場合に備える（uninstall.php は通常フローとは別に読み込まれるため）
if ( ! defined( 'FA_VERSION' ) ) {
    define( 'FA_VERSION', '1.2.0' );
}

FA_DB_Install::drop_tables();
