<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_Vehicle
 * 車番⇄従業員マスタ（ferry_vehicles）の CRUD と AJAX を担当する。
 *
 * 仕様
 *  - vehicle_code は UNIQUE（1車番=1従業員）
 *  - 時期の概念なし。担当が変わったら行を書き換える運用。
 *  - employee_code は emp_master に存在する社員コードのみ許可。
 */
class FA_Vehicle {

    const NONCE_ACTION = 'fa_vehicle_nonce';

    // =====================================================
    //  READ
    // =====================================================

    /**
     * 車番マスタ一覧を取得する（氏名はライブ解決）
     *
     * @param array $args {
     *   @type bool   $include_inactive 無効も含めるか（デフォルト false）
     *   @type string $keyword          車番コード・社員コードの部分一致
     * }
     * @return array  各要素に employee_name（ライブ解決）を付与
     */
    public static function get_list( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'include_inactive' => false,
            'keyword'          => '',
        );
        $args  = wp_parse_args( $args, $defaults );
        $table = FA_DB_Install::table_vehicles();

        $where  = array( '1=1' );
        $params = array();

        if ( ! $args['include_inactive'] ) {
            $where[] = 'is_active = 1';
        }

        if ( '' !== $args['keyword'] ) {
            $like     = '%' . $wpdb->esc_like( $args['keyword'] ) . '%';
            $where[]  = '( vehicle_code LIKE %s OR employee_code LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
        $sql       = "SELECT * FROM `{$table}` {$where_sql} ORDER BY CAST(vehicle_code AS UNSIGNED) ASC, vehicle_code ASC";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore
        }

        $rows = $wpdb->get_results( $sql ); // phpcs:ignore
        if ( ! is_array( $rows ) ) {
            return array();
        }

        // 氏名を1回のマップ構築でライブ解決（N+1回避）
        $map = FA_Employee_Bridge::get_code_name_map();
        foreach ( $rows as $row ) {
            $row->employee_name = FA_Employee_Bridge::resolve_name( $row->employee_code, '', $map );
        }

        return $rows;
    }

    /**
     * ID で1件取得
     */
    public static function get_by_id( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_vehicles();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ) // phpcs:ignore
        );
    }

    /**
     * 車番コードで1件取得（有効のみ）
     */
    public static function get_by_code( $vehicle_code ) {
        global $wpdb;
        $table = FA_DB_Install::table_vehicles();
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE vehicle_code = %s AND is_active = 1", // phpcs:ignore
                (string) $vehicle_code
            )
        );
    }

    /**
     * 車番から従業員（コード・氏名）を解決する。
     * 入力フォームで車番→乗車名を確定させる際に使用。
     *
     * @param string $vehicle_code
     * @return array|null  array( 'employee_code' => .., 'employee_name' => .. ) or null
     */
    public static function resolve_employee( $vehicle_code ) {
        $row = self::get_by_code( $vehicle_code );
        if ( ! $row ) {
            return null;
        }
        return array(
            'employee_code' => $row->employee_code,
            'employee_name' => FA_Employee_Bridge::resolve_name( $row->employee_code, '' ),
        );
    }

    /**
     * 入力フォームの JS 用マップを返す。
     * array( vehicle_code => array( employee_code, employee_name ) )
     */
    public static function get_map_for_js() {
        $map    = array();
        $rows   = self::get_list( array( 'include_inactive' => false ) );
        foreach ( $rows as $row ) {
            $map[ (string) $row->vehicle_code ] = array(
                'employee_code' => (string) $row->employee_code,
                'employee_name' => (string) $row->employee_name,
            );
        }
        return $map;
    }

    // =====================================================
    //  WRITE
    // =====================================================

    /**
     * 新規登録・更新
     *
     * @param array $data  vehicle_code, employee_code, is_active
     * @param int   $id    0以外なら更新
     * @return array  array( 'success' => bool, 'message' => string, 'id' => int )
     */
    public static function save( $data, $id = 0 ) {
        global $wpdb;
        $table = FA_DB_Install::table_vehicles();

        $vehicle_code  = isset( $data['vehicle_code'] )  ? sanitize_text_field( $data['vehicle_code'] )  : '';
        $employee_code = isset( $data['employee_code'] ) ? sanitize_text_field( $data['employee_code'] ) : '';
        $is_active     = isset( $data['is_active'] )     ? (int) $data['is_active'] : 1;

        // --- バリデーション ---
        if ( '' === $vehicle_code ) {
            return array( 'success' => false, 'message' => '車番コードを入力してください。' );
        }
        if ( '' === $employee_code ) {
            return array( 'success' => false, 'message' => '従業員を選択してください。' );
        }
        // 従業員コードが emp_master に存在するか
        if ( ! FA_Employee_Bridge::get_by_code( $employee_code ) ) {
            return array( 'success' => false, 'message' => '指定された従業員コードが社員マスタに存在しません。' );
        }
        // 車番コードの重複チェック（自分自身は除外）
        $dup_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE vehicle_code = %s AND id <> %d", // phpcs:ignore
                $vehicle_code,
                (int) $id
            )
        );
        if ( $dup_id > 0 ) {
            return array( 'success' => false, 'message' => 'この車番コードは既に登録されています。' );
        }

        $now = current_time( 'mysql' );

        if ( $id > 0 ) {
            $wpdb->update(
                $table,
                array(
                    'vehicle_code'  => $vehicle_code,
                    'employee_code' => $employee_code,
                    'is_active'     => $is_active,
                    'updated_at'    => $now,
                ),
                array( 'id' => (int) $id ),
                array( '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );
            return array( 'success' => true, 'message' => '車番マスタを更新しました。', 'id' => (int) $id );
        }

        $wpdb->insert(
            $table,
            array(
                'vehicle_code'  => $vehicle_code,
                'employee_code' => $employee_code,
                'is_active'     => $is_active,
                'created_at'    => $now,
                'updated_at'    => $now,
            ),
            array( '%s', '%s', '%d', '%s', '%s' )
        );
        return array( 'success' => true, 'message' => '車番マスタを登録しました。', 'id' => (int) $wpdb->insert_id );
    }

    /**
     * 削除
     */
    public static function delete( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_vehicles();
        $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
        return array( 'success' => true, 'message' => '車番マスタを削除しました。' );
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

    public static function ajax_get_list() {
        self::verify();
        $include_inactive = isset( $_POST['include_inactive'] ) && '1' === $_POST['include_inactive'];
        $keyword          = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
        $rows = self::get_list( array(
            'include_inactive' => $include_inactive,
            'keyword'          => $keyword,
        ) );
        wp_send_json_success( array( 'items' => $rows ) );
    }

    public static function ajax_save() {
        self::verify();
        $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $data = array(
            'vehicle_code'  => isset( $_POST['vehicle_code'] )  ? wp_unslash( $_POST['vehicle_code'] )  : '',
            'employee_code' => isset( $_POST['employee_code'] ) ? wp_unslash( $_POST['employee_code'] ) : '',
            'is_active'     => isset( $_POST['is_active'] )     ? (int) $_POST['is_active'] : 1,
        );
        $result = self::save( $data, $id );
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
}
