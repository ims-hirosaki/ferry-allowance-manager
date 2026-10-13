<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_Sim_Route
 * シミュレーション航路マスタ（ferry_sim_routes）の CRUD、
 * およびシミュレーション集計（旧・手当ルールとの比較）を担当する。
 *
 * 仕様
 *  - 航路マスタの航路を選択し、シミュレーション手当額を設定する（1航路につき1件）
 *  - 実績は ferry_records.route_id で突き合わせ、手当額だけをシミュレーション額に置き換えて試算する
 *  - 給与計算（実績・月次サマリ）には影響しない
 *  - 操作権限は「運用管理者以上」＝ manage_custom_plugin_settings
 */
class FA_Sim_Route {

    const NONCE_ACTION = 'fa_sim_nonce';
    const CAPABILITY   = 'manage_custom_plugin_settings';

    // =====================================================
    //  READ
    // =====================================================

    /**
     * シミュレーション航路一覧（航路マスタ情報つき）
     */
    public static function get_list() {
        global $wpdb;
        $sim    = FA_DB_Install::table_sim_routes();
        $routes = FA_DB_Install::table_routes();

        $rows = $wpdb->get_results(
            "SELECT s.id, s.route_id, s.allowance AS sim_allowance,
                    r.route_no, r.route_name, r.allowance AS current_allowance, r.is_active
             FROM `{$sim}` s
             INNER JOIN `{$routes}` r ON r.id = s.route_id
             ORDER BY r.sort_order ASC, r.route_no ASC" // phpcs:ignore
        );
        return is_array( $rows ) ? $rows : array();
    }

    // =====================================================
    //  WRITE
    // =====================================================

    /**
     * 新規登録・更新（航路は1件につき1行）
     *
     * @param array $data route_id, allowance
     * @param int   $id
     */
    public static function save( $data, $id = 0 ) {
        global $wpdb;
        $table = FA_DB_Install::table_sim_routes();

        $route_id  = isset( $data['route_id'] ) ? (int) $data['route_id'] : 0;
        $raw       = isset( $data['allowance'] ) ? trim( (string) $data['allowance'] ) : '';
        $allowance = ( '' !== $raw && ctype_digit( $raw ) ) ? (int) $raw : -1;

        if ( $route_id <= 0 || ! FA_Route::get_by_id( $route_id ) ) {
            return array( 'success' => false, 'message' => '航路を選択してください。' );
        }
        if ( $allowance < 0 ) {
            return array( 'success' => false, 'message' => '手当金額は0以上の整数で入力してください。' );
        }

        $dup_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE route_id = %d AND id <> %d", // phpcs:ignore
                $route_id,
                (int) $id
            )
        );
        if ( $dup_id > 0 ) {
            return array( 'success' => false, 'message' => 'この航路は既にシミュレーション航路として登録されています。' );
        }

        $now = current_time( 'mysql' );
        if ( $id > 0 ) {
            $wpdb->update(
                $table,
                array( 'route_id' => $route_id, 'allowance' => $allowance, 'updated_at' => $now ),
                array( 'id' => (int) $id ),
                array( '%d', '%d', '%s' ),
                array( '%d' )
            );
            return array( 'success' => true, 'message' => 'シミュレーション航路を更新しました。' );
        }

        $wpdb->insert(
            $table,
            array( 'route_id' => $route_id, 'allowance' => $allowance, 'created_at' => $now, 'updated_at' => $now ),
            array( '%d', '%d', '%s', '%s' )
        );
        return array( 'success' => true, 'message' => 'シミュレーション航路を登録しました。' );
    }

    public static function delete( $id ) {
        global $wpdb;
        $wpdb->delete( FA_DB_Install::table_sim_routes(), array( 'id' => (int) $id ), array( '%d' ) );
        return array( 'success' => true, 'message' => 'シミュレーション航路を削除しました。' );
    }

    // =====================================================
    //  シミュレーション集計
    // =====================================================

    /**
     * 指定年月の実績のうちシミュレーション航路分を、社員×航路で集計する。
     *
     * @return array array(
     *   'items'  => array( array( employee_code, employee_name, route_no, route_name, count,
     *                             current_total, sim_unit, sim_total, diff ), ... ),
     *   'totals' => array( count, current_total, sim_total, diff ),
     *   'period' => 'YYYY-MM',
     * )
     */
    public static function get_month_simulation( $year, $month ) {
        global $wpdb;

        $year  = (int) $year;
        $month = (int) $month;
        $empty = array(
            'items'  => array(),
            'totals' => array( 'count' => 0, 'current_total' => 0, 'sim_total' => 0, 'diff' => 0 ),
            'period' => '',
        );
        if ( $year <= 0 || $month < 1 || $month > 12 ) {
            return $empty;
        }
        $empty['period'] = sprintf( '%04d-%02d', $year, $month );

        $rec   = FA_DB_Install::table_records();
        $sim   = FA_DB_Install::table_sim_routes();
        $start = sprintf( '%04d-%02d-01', $year, $month );
        $end   = gmdate( 'Y-m-t', strtotime( $start ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.employee_code,
                        MAX(r.employee_name) AS employee_name,
                        r.route_id,
                        MAX(r.route_no)      AS route_no,
                        MAX(r.route_name)    AS route_name,
                        COUNT(*)             AS cnt,
                        SUM(r.allowance)     AS current_total,
                        MAX(s.allowance)     AS sim_unit
                 FROM `{$rec}` r
                 INNER JOIN `{$sim}` s ON s.route_id = r.route_id
                 WHERE r.use_date BETWEEN %s AND %s
                 GROUP BY r.employee_code, r.route_id
                 ORDER BY r.employee_code ASC, route_no ASC", // phpcs:ignore
                $start,
                $end
            )
        );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return $empty;
        }

        $map    = FA_Employee_Bridge::get_code_name_map();
        $items  = array();
        $totals = $empty['totals'];

        foreach ( $rows as $r ) {
            $fallback = ( '' !== (string) $r->employee_name ) ? $r->employee_name : $r->employee_code;
            $cnt      = (int) $r->cnt;
            $current  = (int) $r->current_total;
            $unit     = (int) $r->sim_unit;
            $sim_tot  = $cnt * $unit;

            $items[] = array(
                'employee_code' => $r->employee_code,
                'employee_name' => FA_Employee_Bridge::resolve_name( $r->employee_code, $fallback, $map ),
                'route_no'      => (int) $r->route_no,
                'route_name'    => $r->route_name,
                'count'         => $cnt,
                'current_total' => $current,
                'sim_unit'      => $unit,
                'sim_total'     => $sim_tot,
                'diff'          => $sim_tot - $current,
            );
            $totals['count']         += $cnt;
            $totals['current_total'] += $current;
            $totals['sim_total']     += $sim_tot;
        }
        $totals['diff'] = $totals['sim_total'] - $totals['current_total'];

        return array( 'items' => $items, 'totals' => $totals, 'period' => $empty['period'] );
    }

    // =====================================================
    //  AJAX（すべて運用管理者以上）
    // =====================================================

    private static function verify() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }
    }

    public static function ajax_get_list() {
        self::verify();
        wp_send_json_success( array( 'items' => self::get_list() ) );
    }

    public static function ajax_save() {
        self::verify();
        $result = self::save(
            array(
                'route_id'  => isset( $_POST['route_id'] ) ? (int) $_POST['route_id'] : 0,
                'allowance' => isset( $_POST['allowance'] ) ? wp_unslash( $_POST['allowance'] ) : '',
            ),
            isset( $_POST['id'] ) ? (int) $_POST['id'] : 0
        );
        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result );
    }

    public static function ajax_delete() {
        self::verify();
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => '対象が指定されていません。' ) );
        }
        wp_send_json_success( self::delete( $id ) );
    }

    public static function ajax_get_simulation() {
        self::verify();
        $year  = isset( $_POST['year'] )  ? (int) $_POST['year']  : 0;
        $month = isset( $_POST['month'] ) ? (int) $_POST['month'] : 0;
        wp_send_json_success( self::get_month_simulation( $year, $month ) );
    }
}
