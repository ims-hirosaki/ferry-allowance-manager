<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_Admin_Menu
 * メニュー登録・アセット読み込み・AJAXフック登録・JSへのデータ受け渡しを担当する。
 *
 * メニュー構成
 *   フェリー手当（トップ = フェリー手当入力）
 *   ├── フェリー手当入力   ferry-allowance
 *   ├── 月次サマリ         ferry-allowance-summary
 *   ├── 航路マスタ管理     ferry-allowance-routes
 *   └── 車番マスタ管理     ferry-allowance-vehicles
 */
class FA_Admin_Menu {

    /** このプラグインが扱うページスラッグ */
    private $pages = array(
        'ferry-allowance',
        'ferry-allowance-summary',
        'ferry-allowance-routes',
        'ferry-allowance-vehicles',
    );

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        $this->register_ajax_hooks();
    }

    // =====================================================
    //  メニュー登録
    // =====================================================

    public function register_menu() {
        add_menu_page(
            'フェリー手当管理',
            'フェリー手当',
            'manage_options',
            'ferry-allowance',
            array( $this, 'render_entry' ),
            'dashicons-sos',   // 浮き輪＝船の連想（dashiconsに船・錨が無いため）
            32                 // 有給管理(31)の直下
        );

        add_submenu_page(
            'ferry-allowance', 'フェリー手当入力', 'フェリー手当入力',
            'manage_options', 'ferry-allowance',
            array( $this, 'render_entry' )
        );
        add_submenu_page(
            'ferry-allowance', '月次サマリ', '月次サマリ',
            'manage_options', 'ferry-allowance-summary',
            array( $this, 'render_summary' )
        );
        add_submenu_page(
            'ferry-allowance', '航路マスタ管理', '航路マスタ管理',
            'manage_options', 'ferry-allowance-routes',
            array( $this, 'render_routes' )
        );
        add_submenu_page(
            'ferry-allowance', '車番マスタ管理', '車番マスタ管理',
            'manage_options', 'ferry-allowance-vehicles',
            array( $this, 'render_vehicles' )
        );
    }

    // =====================================================
    //  アセット読み込み
    // =====================================================

    public function enqueue_assets( $hook ) {
        // サブメニューでは $hook が不安定なため $_GET['page'] で判定する
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! in_array( $page, $this->pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'fa-admin',
            FA_PLUGIN_URL . 'admin/assets/admin.css',
            array(),
            FA_VERSION
        );
        wp_enqueue_script(
            'fa-admin',
            FA_PLUGIN_URL . 'admin/assets/admin.js',
            array( 'jquery' ),
            FA_VERSION,
            true
        );

        // JS へ渡すデータ（ajaxurl・Nonce群・各種マスタ）
        wp_localize_script(
            'fa-admin',
            'faData',
            array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'page'    => $page,
                'nonce'   => array(
                    'vehicle' => wp_create_nonce( FA_Vehicle::NONCE_ACTION ),
                    'route'   => wp_create_nonce( FA_Route::NONCE_ACTION ),
                    'record'  => class_exists( 'FA_Record' ) ? wp_create_nonce( FA_Record::NONCE_ACTION ) : '',
                ),
                // 入力フォーム（A案サジェスト／自動反映）用マスタ
                'routes'    => FA_Route::get_map_for_js(),
                'vehicles'  => FA_Vehicle::get_map_for_js(),
                // 車番マスタ画面の従業員セレクト用（在籍社員）
                'employees' => $this->employees_for_select(),
            )
        );
    }

    /**
     * 車番マスタ画面の従業員セレクト用データ
     * array( array( code, name, crew_code ), ... )
     */
    private function employees_for_select() {
        $out  = array();
        $list = FA_Employee_Bridge::get_active_employees();
        foreach ( $list as $emp ) {
            $out[] = array(
                'code'      => isset( $emp->employee_code ) ? (string) $emp->employee_code : '',
                'name'      => isset( $emp->name ) ? (string) $emp->name : '',
                'crew_code' => isset( $emp->crew_code ) ? (string) $emp->crew_code : '',
            );
        }
        return $out;
    }

    // =====================================================
    //  AJAXフック登録
    // =====================================================

    private function register_ajax_hooks() {
        // 車番マスタ
        add_action( 'wp_ajax_fa_vehicle_get_list', array( 'FA_Vehicle', 'ajax_get_list' ) );
        add_action( 'wp_ajax_fa_vehicle_save',     array( 'FA_Vehicle', 'ajax_save' ) );
        add_action( 'wp_ajax_fa_vehicle_delete',   array( 'FA_Vehicle', 'ajax_delete' ) );

        // 航路マスタ
        add_action( 'wp_ajax_fa_route_get_list', array( 'FA_Route', 'ajax_get_list' ) );
        add_action( 'wp_ajax_fa_route_save',     array( 'FA_Route', 'ajax_save' ) );
        add_action( 'wp_ajax_fa_route_toggle',   array( 'FA_Route', 'ajax_toggle' ) );
        add_action( 'wp_ajax_fa_route_delete',   array( 'FA_Route', 'ajax_delete' ) );

        // 利用実績（フェリー手当入力）
        if ( class_exists( 'FA_Record' ) ) {
            add_action( 'wp_ajax_fa_record_save',     array( 'FA_Record', 'ajax_save' ) );
            add_action( 'wp_ajax_fa_record_get_list', array( 'FA_Record', 'ajax_get_list' ) );
            add_action( 'wp_ajax_fa_record_delete',   array( 'FA_Record', 'ajax_delete' ) );
        }

        // サマリのAJAXは、FA_Summary 作成時にここへ追加する。
        // if ( class_exists( 'FA_Summary' ) ) { ... }
    }

    // =====================================================
    //  画面レンダリング
    //  ビュー未作成のページは file_exists でガードし「準備中」を表示する。
    // =====================================================

    public function render_entry() {
        $this->render_view( 'entry.php', 'フェリー手当入力' );
    }

    public function render_summary() {
        $this->render_view( 'summary.php', '月次サマリ' );
    }

    public function render_routes() {
        $this->render_view( 'routes.php', '航路マスタ管理' );
    }

    public function render_vehicles() {
        $this->render_view( 'vehicles.php', '車番マスタ管理' );
    }

    /**
     * ビューを読み込む。未作成なら準備中プレースホルダを表示。
     */
    private function render_view( $file, $title ) {
        $path = FA_PLUGIN_DIR . 'admin/views/' . $file;
        if ( file_exists( $path ) ) {
            include $path;
            return;
        }
        echo '<div class="wrap fa-wrap">';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo '<div class="notice notice-info inline"><p>この画面は準備中です。</p></div>';
        echo '</div>';
    }
}
