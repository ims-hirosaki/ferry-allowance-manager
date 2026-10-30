<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_REST_API
 * フェリー手当データ取得用 REST API（Excel / Power Query 連携用）
 *
 *   GET /wp-json/ferry-allowance-manager/v1/employees
 *   GET /wp-json/ferry-allowance-manager/v1/records?employee_code=XXX&year_month=YYYY-MM
 *   GET /wp-json/ferry-allowance-manager/v1/summary?year_month=YYYY-MM
 *
 * 認証は WordPress のアプリケーションパスワード（Basic認証）を想定する。
 * 権限は専用ケイパビリティ fa_export_data、または既存の access_custom_plugins。
 * 共通ルールは attendance-manager リポジトリの docs/api-guidelines.md に従う。
 *
 * キャッシュは使わない（実績テーブルへの単純な集計で軽く、氏名も社員マスタからライブ解決するため）。
 */
class FA_REST_API {

    const NAMESPACE_V1 = 'ferry-allowance-manager/v1';
    const CAP_EXPORT   = 'fa_export_data';
    const ROLE_API     = 'api_reader';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'init',          array( __CLASS__, 'ensure_role' ) );
    }

    /**
     * 「API取得」ロールを用意する（FTPデプロイでは有効化フックが走らないため init で確認）。
     * ロールは他のプラグインとも共有する想定。既に存在する場合は作り直さず、
     * このプラグイン用のケイパビリティだけを追加する。
     */
    public static function ensure_role() {
        $role = get_role( self::ROLE_API );
        if ( ! $role ) {
            add_role( self::ROLE_API, 'API取得', array( 'read' => true ) );
            $role = get_role( self::ROLE_API );
        }
        if ( $role && ! $role->has_cap( self::CAP_EXPORT ) ) {
            $role->add_cap( self::CAP_EXPORT );
        }
    }

    public static function register_routes() {
        register_rest_route( self::NAMESPACE_V1, '/employees', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_employees' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
        ) );
        register_rest_route( self::NAMESPACE_V1, '/records', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_records' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
            'args'                => array(
                'employee_code' => array( 'type' => 'string', 'required' => true ),
                'year_month'    => array(
                    'type'     => 'string',
                    'required' => true,
                    'pattern'  => '^\d{4}-(0[1-9]|1[0-2])$',
                ),
            ),
        ) );
        register_rest_route( self::NAMESPACE_V1, '/summary', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_summary' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
            'args'                => array(
                'year_month' => array(
                    'type'     => 'string',
                    'required' => true,
                    'pattern'  => '^\d{4}-(0[1-9]|1[0-2])$',
                ),
            ),
        ) );
    }

    public static function check_permission() {
        if ( current_user_can( self::CAP_EXPORT ) || current_user_can( 'access_custom_plugins' ) ) {
            return true;
        }
        return new WP_Error( 'fa_forbidden', '権限がありません。', array( 'status' => is_user_logged_in() ? 403 : 401 ) );
    }

    /* ---------------------------------------------------------------
     * 社員一覧（軽量・在籍社員）
     * ------------------------------------------------------------- */
    public static function get_employees( WP_REST_Request $request ) {
        $list = array();
        foreach ( FA_Employee_Bridge::get_active_employees() as $emp ) {
            $code = isset( $emp->employee_code ) ? (string) $emp->employee_code : '';
            if ( '' === $code ) {
                continue;
            }
            $list[] = array(
                'employee_code' => $code,
                'name'          => isset( $emp->name ) ? (string) $emp->name : '',
            );
        }
        return rest_ensure_response( $list );
    }

    /* ---------------------------------------------------------------
     * 個人別月次実績（明細・月次サマリ）
     * ------------------------------------------------------------- */
    public static function get_records( WP_REST_Request $request ) {
        $employee_code = trim( (string) $request['employee_code'] );
        $year_month    = (string) $request['year_month'];
        list( $year, $month ) = self::split_year_month( $year_month );

        $rows = FA_Record::get_records( array(
            'year'          => $year,
            'month'         => $month,
            'employee_code' => $employee_code,
        ) );

        // 実績が無い場合も、社員自体が存在しなければ 404。
        $emp = FA_Employee_Bridge::get_by_code( $employee_code );
        if ( empty( $rows ) && ! $emp ) {
            return new WP_Error( 'fa_not_found', '該当する社員が見つかりません。', array( 'status' => 404 ) );
        }

        $name  = '';
        $items = array();
        $total = 0;
        foreach ( $rows as $r ) {
            $name    = (string) $r->employee_name;
            $total  += (int) $r->allowance;
            $items[] = array(
                'id'               => (int) $r->id,
                'use_date'         => (string) $r->use_date,
                'route_no'         => (int) $r->route_no,
                'route_name'       => (string) $r->route_name,
                'company_name'     => (string) $r->company_name,
                'vehicle_code'     => (string) $r->vehicle_code,
                'transport_bureau' => (string) $r->transport_bureau,
                'allowance'        => (int) $r->allowance,
                'note'             => (string) $r->note,
            );
        }
        if ( '' === $name && $emp && ! empty( $emp->name ) ) {
            $name = (string) $emp->name;
        }

        return rest_ensure_response( array(
            'employee_code' => $employee_code,
            'name'          => $name,
            'year_month'    => $year_month,
            'records'       => $items,
            'summary'       => array(
                'count'           => count( $items ),
                'allowance_total' => $total,
            ),
            'generated_at'  => current_time( 'c' ),
        ) );
    }

    /* ---------------------------------------------------------------
     * 月次サマリ（実績のある全社員・給与ソフト入力用）
     * 1 クエリの集計で軽いため、分割取得は設けない。
     * ------------------------------------------------------------- */
    public static function get_summary( WP_REST_Request $request ) {
        $year_month = (string) $request['year_month'];
        list( $year, $month ) = self::split_year_month( $year_month );

        $data  = FA_Summary::get_month_summary( $year, $month );
        $items = array();
        foreach ( $data['items'] as $it ) {
            $items[] = array(
                'employee_code'   => (string) $it['employee_code'],
                'name'            => (string) $it['employee_name'],
                'count'           => (int) $it['count'],
                'allowance_total' => (int) $it['total'],
            );
        }

        return rest_ensure_response( array(
            'year_month'      => $year_month,
            'total_employees' => count( $items ),
            'grand_count'     => (int) $data['grand_count'],
            'grand_total'     => (int) $data['grand_total'],
            'employees'       => $items,
            'generated_at'    => current_time( 'c' ),
        ) );
    }

    /* ---------------------------------------------------------------
     * helpers
     * ------------------------------------------------------------- */
    private static function split_year_month( $year_month ) {
        return array( (int) substr( $year_month, 0, 4 ), (int) substr( $year_month, 5, 2 ) );
    }
}
