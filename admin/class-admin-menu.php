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
 *   ├── フェリー手当入力     ferry-allowance
 *   ├── 月次サマリ           ferry-allowance-summary
 *   ├── 実績一覧・編集       ferry-allowance-records
 *   ├── 航路マスタ管理       ferry-allowance-routes
 *   ├── フェリー会社マスタ   ferry-allowance-companies
 *   └── 乗車名マスタ         ferry-allowance-boarding-names
 */
class FA_Admin_Menu {

    /** このプラグインが扱うページスラッグ */
    private $pages = array(
        'ferry-allowance',
        'ferry-allowance-summary',
        'ferry-allowance-records',
        'ferry-allowance-routes',
        'ferry-allowance-companies',
        'ferry-allowance-boarding-names',
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
            'ferry-allowance', '実績一覧・編集', '実績一覧・編集',
            'manage_options', 'ferry-allowance-records',
            array( $this, 'render_records' )
        );
        add_submenu_page(
            'ferry-allowance', '航路マスタ管理', '航路マスタ管理',
            'manage_options', 'ferry-allowance-routes',
            array( $this, 'render_routes' )
        );
        add_submenu_page(
            'ferry-allowance', 'フェリー会社マスタ', 'フェリー会社マスタ',
            'manage_options', 'ferry-allowance-companies',
            array( $this, 'render_companies' )
        );
        add_submenu_page(
            'ferry-allowance', '乗車名マスタ', '乗車名マスタ',
            'manage_options', 'ferry-allowance-boarding-names',
            array( $this, 'render_boarding_names' )
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
                    'company' => wp_create_nonce( FA_Company::NONCE_ACTION ),
                    'route'   => wp_create_nonce( FA_Route::NONCE_ACTION ),
                    'record'  => class_exists( 'FA_Record' ) ? wp_create_nonce( FA_Record::NONCE_ACTION ) : '',
                    'summary' => class_exists( 'FA_Summary' ) ? wp_create_nonce( FA_Summary::NONCE_ACTION ) : '',
                    'boarding' => wp_create_nonce( FA_Boarding_Name::NONCE_ACTION ),
                ),
                // 入力フォーム（A案サジェスト／自動反映）用マスタ
                'routes'         => FA_Route::get_map_for_js(),
                // 車番の入力補完用（vehicle-manager の一連指定番号一覧）
                'vehicleNumbers' => FA_Vehicle_Bridge::get_vehicle_numbers(),
                // 乗車名マスタ（車番 → 社員）
                'vehicleEmployees' => FA_Vehicle_Bridge::get_employee_map(),
                // 例外時の乗車名選択用（在籍社員）
                'employees'      => $this->employees_for_select(),
                // 未登録時の誘導リンク
                'links'          => array(
                    'vehicle'  => admin_url( 'admin.php?page=vm-vehicle-form' ),
                    'employee' => admin_url( 'admin.php?page=employee-manager-new' ),
                ),
            )
        );
    }

    /**
     * 乗車名入力補完用の従業員データ
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
        // 乗車名マスタ
        add_action( 'wp_ajax_fa_boarding_get_list', array( 'FA_Boarding_Name', 'ajax_get_list' ) );
        add_action( 'wp_ajax_fa_boarding_save',     array( 'FA_Boarding_Name', 'ajax_save' ) );
        add_action( 'wp_ajax_fa_boarding_toggle',   array( 'FA_Boarding_Name', 'ajax_toggle' ) );
        add_action( 'wp_ajax_fa_boarding_delete',   array( 'FA_Boarding_Name', 'ajax_delete' ) );

        // フェリー会社マスタ
        add_action( 'wp_ajax_fa_company_get_list', array( 'FA_Company', 'ajax_get_list' ) );
        add_action( 'wp_ajax_fa_company_save',     array( 'FA_Company', 'ajax_save' ) );
        add_action( 'wp_ajax_fa_company_toggle',   array( 'FA_Company', 'ajax_toggle' ) );
        add_action( 'wp_ajax_fa_company_delete',   array( 'FA_Company', 'ajax_delete' ) );

        // 航路マスタ
        add_action( 'wp_ajax_fa_route_get_list', array( 'FA_Route', 'ajax_get_list' ) );
        add_action( 'wp_ajax_fa_route_save',     array( 'FA_Route', 'ajax_save' ) );
        add_action( 'wp_ajax_fa_route_toggle',   array( 'FA_Route', 'ajax_toggle' ) );
        add_action( 'wp_ajax_fa_route_delete',   array( 'FA_Route', 'ajax_delete' ) );

        // 利用実績（フェリー手当入力・実績一覧）
        if ( class_exists( 'FA_Record' ) ) {
            add_action( 'wp_ajax_fa_record_save',     array( 'FA_Record', 'ajax_save' ) );
            add_action( 'wp_ajax_fa_record_get_list', array( 'FA_Record', 'ajax_get_list' ) );
            add_action( 'wp_ajax_fa_record_update',   array( 'FA_Record', 'ajax_update' ) );
            add_action( 'wp_ajax_fa_record_delete',   array( 'FA_Record', 'ajax_delete' ) );
        }

        // 月次サマリ
        if ( class_exists( 'FA_Summary' ) ) {
            add_action( 'wp_ajax_fa_summary_get', array( 'FA_Summary', 'ajax_get' ) );
        }
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

    public function render_records() {
        $this->render_view( 'records.php', '実績一覧・編集' );
    }

    public function render_routes() {
        $this->render_view( 'routes.php', '航路マスタ管理' );
    }

    public function render_companies() {
        $this->render_view( 'companies.php', 'フェリー会社マスタ' );
    }

    public function render_boarding_names() {
        $this->render_view( 'boarding-names.php', '乗車名マスタ' );
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
