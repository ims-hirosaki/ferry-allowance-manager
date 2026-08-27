<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 車番と乗車社員の対応を管理する、このプラグイン専用の乗車名マスタ。
 */
class FA_Boarding_Name {

    const NONCE_ACTION = 'fa_boarding_name_nonce';

    public static function get_list( $args = array() ) {
        global $wpdb;

        $args = wp_parse_args( $args, array(
            'include_inactive' => false,
            'keyword'          => '',
        ) );
        $table  = FA_DB_Install::table_vehicles();
        $where  = array( '1=1' );
        $params = array();

        if ( ! $args['include_inactive'] ) {
            $where[] = 'is_active = 1';
        }
        if ( '' !== $args['keyword'] ) {
            $like     = '%' . $wpdb->esc_like( $args['keyword'] ) . '%';
            $where[]  = '(vehicle_code LIKE %s OR employee_code LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT * FROM `{$table}` WHERE " . implode( ' AND ', $where )
            . ' ORDER BY CAST(vehicle_code AS UNSIGNED) ASC, vehicle_code ASC';
        if ( $params ) {
            $sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $names = FA_Employee_Bridge::get_code_name_map();
        foreach ( $rows as $row ) {
            $row->employee_name = FA_Employee_Bridge::resolve_name( $row->employee_code, $row->employee_code, $names );
        }
        return $rows;
    }

    public static function get_map_for_js() {
        $map            = array();
        $valid_vehicles = array_fill_keys( array_map( 'strval', FA_Vehicle_Bridge::get_vehicle_numbers() ), true );
        $active_names   = FA_Employee_Bridge::get_code_name_map();
        foreach ( self::get_list() as $row ) {
            // 車両管理から削除された車番や、在籍社員でない紐づけは入力候補に使用しない。
            if ( ! isset( $valid_vehicles[ (string) $row->vehicle_code ], $active_names[ (string) $row->employee_code ] ) ) {
                continue;
            }
            $map[ (string) $row->vehicle_code ] = array(
                'employee_code' => (string) $row->employee_code,
                'employee_name' => (string) $row->employee_name,
            );
        }
        return $map;
    }

    public static function save( $data, $id = 0 ) {
        global $wpdb;

        $id            = (int) $id;
        $vehicle_code  = isset( $data['vehicle_code'] ) ? sanitize_text_field( $data['vehicle_code'] ) : '';
        $employee_code = isset( $data['employee_code'] ) ? sanitize_text_field( $data['employee_code'] ) : '';
        $is_active     = empty( $data['is_active'] ) ? 0 : 1;
        $table         = FA_DB_Install::table_vehicles();

        if ( '' === $vehicle_code || ! FA_Vehicle_Bridge::exists( $vehicle_code ) ) {
            return array( 'success' => false, 'message' => '車両管理に登録されている車番を選択してください。' );
        }
        if ( '' === $employee_code || ! FA_Employee_Bridge::exists_active( $employee_code ) ) {
            return array( 'success' => false, 'message' => '在籍中の社員を選択してください。' );
        }

        $duplicate_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE vehicle_code = %s AND id <> %d",
                $vehicle_code,
                $id
            )
        );
        if ( $duplicate_id > 0 ) {
            return array( 'success' => false, 'message' => 'この車番は既に乗車名マスタへ登録されています。' );
        }

        $values = array(
            'vehicle_code'  => $vehicle_code,
            'employee_code' => $employee_code,
            'is_active'     => $is_active,
            'updated_at'    => current_time( 'mysql' ),
        );
        if ( $id > 0 ) {
            $wpdb->update( $table, $values, array( 'id' => $id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );
            return array( 'success' => true, 'message' => '乗車名マスタを更新しました。', 'id' => $id );
        }

        $values['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $table, $values, array( '%s', '%s', '%d', '%s', '%s' ) );
        return array( 'success' => true, 'message' => '乗車名マスタへ登録しました。', 'id' => (int) $wpdb->insert_id );
    }

    public static function toggle( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_vehicles();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, is_active FROM `{$table}` WHERE id = %d", (int) $id ) );
        if ( ! $row ) {
            return array( 'success' => false, 'message' => '対象が見つかりません。' );
        }
        $active = (int) $row->is_active === 1 ? 0 : 1;
        $wpdb->update(
            $table,
            array( 'is_active' => $active, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $id ),
            array( '%d', '%s' ),
            array( '%d' )
        );
        return array( 'success' => true, 'message' => $active ? '有効にしました。' : '無効にしました。' );
    }

    public static function delete( $id ) {
        global $wpdb;
        $wpdb->delete( FA_DB_Install::table_vehicles(), array( 'id' => (int) $id ), array( '%d' ) );
        return array( 'success' => true, 'message' => '乗車名マスタから削除しました。' );
    }

    private static function verify() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }
    }

    public static function ajax_get_list() {
        self::verify();
        wp_send_json_success( array( 'items' => self::get_list( array(
            'include_inactive' => isset( $_POST['include_inactive'] ) && '1' === $_POST['include_inactive'],
            'keyword'          => isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '',
        ) ) ) );
    }

    public static function ajax_save() {
        self::verify();
        $result = self::save( array(
            'vehicle_code'  => isset( $_POST['vehicle_code'] ) ? wp_unslash( $_POST['vehicle_code'] ) : '',
            'employee_code' => isset( $_POST['employee_code'] ) ? wp_unslash( $_POST['employee_code'] ) : '',
            'is_active'     => isset( $_POST['is_active'] ) ? (int) $_POST['is_active'] : 1,
        ), isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result );
    }

    public static function ajax_toggle() {
        self::verify();
        $result = self::toggle( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
        if ( $result['success'] ) {
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
