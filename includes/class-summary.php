<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_Summary
 * 月次サマリ（給与ソフト入力用）の集計を担当する。
 * 実績のある社員のみを対象に、当月のフェリー手当合計と件数を返す。
 */
class FA_Summary {

    const NONCE_ACTION = 'fa_summary_nonce';

    /**
     * 指定年月のサマリを返す
     *
     * @param int $year
     * @param int $month
     * @return array  array(
     *   'items'       => array( array( employee_code, employee_name, total, count ), ... ),
     *   'grand_total' => int,
     *   'grand_count' => int,
     *   'period'      => 'YYYY-MM',
     * )
     */
    public static function get_month_summary( $year, $month ) {
        global $wpdb;

        $year  = (int) $year;
        $month = (int) $month;

        $empty = array( 'items' => array(), 'grand_total' => 0, 'grand_count' => 0, 'period' => '' );

        if ( $year <= 0 || $month < 1 || $month > 12 ) {
            return $empty;
        }

        $table = FA_DB_Install::table_records();
        $start = sprintf( '%04d-%02d-01', $year, $month );
        $end   = gmdate( 'Y-m-t', strtotime( $start ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT employee_code,
                        MAX(employee_name) AS employee_name,
                        SUM(allowance) AS total,
                        COUNT(*) AS cnt
                 FROM `{$table}`
                 WHERE use_date BETWEEN %s AND %s
                 GROUP BY employee_code
                 ORDER BY employee_code ASC", // phpcs:ignore
                $start,
                $end
            )
        );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return array( 'items' => array(), 'grand_total' => 0, 'grand_count' => 0, 'period' => sprintf( '%04d-%02d', $year, $month ) );
        }

        $map         = FA_Employee_Bridge::get_code_name_map();
        $items       = array();
        $grand_total = 0;
        $grand_count = 0;

        foreach ( $rows as $r ) {
            $fallback = ( '' !== (string) $r->employee_name ) ? $r->employee_name : $r->employee_code;
            $name     = FA_Employee_Bridge::resolve_name( $r->employee_code, $fallback, $map );

            $total = (int) $r->total;
            $cnt   = (int) $r->cnt;

            $items[] = array(
                'employee_code' => $r->employee_code,
                'employee_name' => $name,
                'total'         => $total,
                'count'         => $cnt,
            );
            $grand_total += $total;
            $grand_count += $cnt;
        }

        return array(
            'items'       => $items,
            'grand_total' => $grand_total,
            'grand_count' => $grand_count,
            'period'      => sprintf( '%04d-%02d', $year, $month ),
        );
    }

    // =====================================================
    //  AJAX
    // =====================================================

    private static function verify() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }
    }

    public static function ajax_get() {
        self::verify();
        $year  = isset( $_POST['year'] )  ? (int) $_POST['year']  : 0;
        $month = isset( $_POST['month'] ) ? (int) $_POST['month'] : 0;
        wp_send_json_success( self::get_month_summary( $year, $month ) );
    }
}
