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
 *   └── マスタ管理           ferry-allowance-master（タブ切替）
 *         ├── 航路マスタ         ?tab=routes
 *         ├── フェリー会社マスタ ?tab=companies
 *         └── 乗車名マスタ       ?tab=boarding-names（manage_options のみ）
 */
class FA_Admin_Menu {

    /** このプラグインが扱うページスラッグ */
    private $pages = array(
        'ferry-allowance',
        'ferry-allowance-summary',
        'ferry-allowance-records',
        'ferry-allowance-master',
    );

    /** マスタ管理のタブ定義: tab => array( ラベル, 必要権限, ビューファイル ) */
    private function master_tabs() {
        return array(
            'routes'         => array( '航路マスタ',         'access_custom_plugins', 'routes.php' ),
            'companies'      => array( 'フェリー会社マスタ', 'access_custom_plugins', 'companies.php' ),
            'boarding-names' => array( '乗車名マスタ',       'manage_options',        'boarding-names.php' ),
        );
    }

    /** 現在のタブ（不正値・権限なしは先頭の許可タブへ） */
    private function current_tab() {
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $tabs = $this->master_tabs();
        if ( isset( $tabs[ $tab ] ) && current_user_can( $tabs[ $tab ][1] ) ) {
            return $tab;
        }
        return 'routes';
    }

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_menu' ) );
        add_action( 'admin_init',            array( $this, 'redirect_legacy_pages' ) );
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
            'access_custom_plugins',
            'ferry-allowance',
            array( $this, 'render_entry' ),
            'dashicons-sos',   // 浮き輪＝船の連想（dashiconsに船・錨が無いため）
            32                 // 有給管理(31)の直下
        );

        add_submenu_page(
            'ferry-allowance', 'フェリー手当入力', 'フェリー手当入力',
            'access_custom_plugins', 'ferry-allowance',
            array( $this, 'render_entry' )
        );
        add_submenu_page(
            'ferry-allowance', '月次サマリ', '月次サマリ',
            'access_custom_plugins', 'ferry-allowance-summary',
            array( $this, 'render_summary' )
        );
        add_submenu_page(
            'ferry-allowance', '実績一覧・編集', '実績一覧・編集',
            'access_custom_plugins', 'ferry-allowance-records',
            array( $this, 'render_records' )
        );
        add_submenu_page(
            'ferry-allowance', 'マスタ管理', 'マスタ管理',
            'access_custom_plugins', 'ferry-allowance-master',
            array( $this, 'render_master' )
        );
    }

    /**
     * 旧マスタ画面のURL（ブックマーク等）を新しいタブ付きURLへ転送する。
     */
    public function redirect_legacy_pages() {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $map  = array(
            'ferry-allowance-routes'         => 'routes',
            'ferry-allowance-companies'      => 'companies',
            'ferry-allowance-boarding-names' => 'boarding-names',
        );
        if ( isset( $map[ $page ] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ferry-allowance-master&tab=' . $map[ $page ] ) );
            exit;
        }
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
                'tab'     => $this->current_tab(),
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
                // 実績一覧の絞り込み候補（当月に実績がある乗車名・車番。画面の初期年月と同じ）
                'recordFilters'  => FA_Record::get_filter_options( array(
                    'year'  => (int) gmdate( 'Y', current_time( 'timestamp' ) ),
                    'month' => (int) gmdate( 'n', current_time( 'timestamp' ) ),
                ) ),
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

    public function render_master() {
        if ( ! current_user_can( 'access_custom_plugins' ) ) {
            wp_die( '権限がありません。', '', array( 'response' => 403 ) );
        }
        $current = $this->current_tab();
        $tabs    = $this->master_tabs();

        echo '<div class="wrap fa-wrap fa-master">';
        echo '<h1>マスタ管理</h1>';
        echo '<nav class="nav-tab-wrapper fa-master__tabs">';
        foreach ( $tabs as $key => $def ) {
            if ( ! current_user_can( $def[1] ) ) {
                continue;
            }
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url( admin_url( 'admin.php?page=ferry-allowance-master&tab=' . $key ) ),
                $key === $current ? ' nav-tab-active' : '',
                esc_html( $def[0] )
            );
        }
        echo '</nav>';
        echo '<div class="fa-master__body">';
        $this->render_view( $tabs[ $current ][2], $tabs[ $current ][0] );
        echo '</div></div>';
    }

    /**
     * ビューを読み込む。未作成なら準備中プレースホルダを表示。
     */
    private function render_view( $file, $title ) {
        if ( ! current_user_can( 'access_custom_plugins' ) ) {
            wp_die( '権限がありません。', '', array( 'response' => 403 ) );
        }
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
