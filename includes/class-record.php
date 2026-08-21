<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_Record
 * フェリー利用実績（ferry_records）の保存・取得・削除・AJAX を担当する。
 *
 * 保存時の方針
 *  - 車番 → vehicle-manager（車両管理ツール）に実在するかを確認（一連指定番号）
 *  - 乗車名 → 通常・例外選択とも社員コードを検証し、氏名とともにスナップショット保存
 *  - 航路番号 → 航路マスタ → 航路名・フェリー会社・手当額をスナップショット保存
 *  - 同一社員＋同一日＋同一航路の完全重複は「警告のみ」で登録する（要件どおり）
 */
class FA_Record {

    const NONCE_ACTION = 'fa_record_nonce';

    // =====================================================
    //  一括保存
    // =====================================================

    /**
     * 複数行の一括登録
     * 1行でもハードエラー（必須・実在チェック違反）があれば、何も登録せず全体を差し戻す。
     * ハードエラーが無ければ全行を登録し、重複は警告として返す（登録は行う）。
     *
     * @param array $rows  各要素: use_date, route_no, vehicle_code, employee_code, note
     * @return array
     */
    public static function save_bulk( $rows ) {
        global $wpdb;

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return array( 'success' => false, 'message' => '登録するデータがありません。' );
        }

        $prepared = array();
        $errors   = array();

        foreach ( $rows as $i => $row ) {
            $line         = $i + 1;
            $use_date     = isset( $row['use_date'] )     ? trim( (string) $row['use_date'] ) : '';
            $route_no     = isset( $row['route_no'] )     ? (int) $row['route_no'] : 0;
            $vehicle_code = isset( $row['vehicle_code'] ) ? trim( (string) $row['vehicle_code'] ) : '';
            $emp_code     = isset( $row['employee_code'] ) ? trim( (string) $row['employee_code'] ) : '';
            $note         = isset( $row['note'] )         ? sanitize_text_field( (string) $row['note'] ) : '';

            if ( '' === $use_date || ! self::valid_date( $use_date ) ) {
                $errors[] = $line . '行目：乗車月日が正しくありません（YYYY-MM-DD）。';
                continue;
            }
            if ( $route_no <= 0 ) {
                $errors[] = $line . '行目：航路番号を入力してください。';
                continue;
            }
            $route = FA_Route::get_by_no( $route_no );
            if ( ! $route ) {
                $errors[] = $line . '行目：航路番号 ' . $route_no . ' は航路マスタに見つかりません。';
                continue;
            }
            if ( '' === $vehicle_code ) {
                $errors[] = $line . '行目：車番を入力してください。';
                continue;
            }
            if ( ! FA_Vehicle_Bridge::exists( $vehicle_code ) ) {
                $errors[] = $line . '行目：車番 ' . $vehicle_code . ' は車両管理ツールに登録されていません。車両管理画面から登録してください。';
                continue;
            }
            if ( '' === $emp_code ) {
                $errors[] = $line . '行目：乗車名を入力してください。';
                continue;
            }
            $emp = FA_Employee_Bridge::get_by_code( $emp_code );
            if ( ! $emp ) {
                $errors[] = $line . '行目：乗車名（社員コード ' . $emp_code . '）が社員情報に見つかりません。';
                continue;
            }
            $emp_name = FA_Employee_Bridge::resolve_name( $emp_code, '' );

            $prepared[] = array(
                'use_date'      => $use_date,
                'route'         => $route,
                'vehicle_code'  => $vehicle_code,
                'employee_code' => $emp_code,
                'employee_name' => $emp_name,
                'note'          => $note,
            );
        }

        if ( ! empty( $errors ) ) {
            return array(
                'success' => false,
                'message' => '入力内容にエラーがあります。修正してください。',
                'errors'  => $errors,
            );
        }

        $table    = FA_DB_Install::table_records();
        $now      = current_time( 'mysql' );
        $inserted = 0;
        $warnings = array();

        foreach ( $prepared as $p ) {
            // 重複チェック（社員＋日付＋航路）→ 警告のみ・登録は継続
            $dup = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE employee_code = %s AND use_date = %s AND route_id = %d", // phpcs:ignore
                    $p['employee_code'],
                    $p['use_date'],
                    (int) $p['route']->id
                )
            );
            if ( $dup > 0 ) {
                $warnings[] = $p['employee_name'] . '／' . $p['use_date'] . '／' . $p['route']->route_name
                    . '：同じ実績が既にあります（重複登録しました）。';
            }

            $company_id = (int) $p['route']->company_id;

            $wpdb->insert(
                $table,
                array(
                    'employee_code' => $p['employee_code'],
                    'employee_name' => $p['employee_name'],
                    'vehicle_code'  => $p['vehicle_code'],
                    'use_date'      => $p['use_date'],
                    'route_id'      => (int) $p['route']->id,
                    'route_no'      => (int) $p['route']->route_no,
                    'route_name'    => $p['route']->route_name,
                    'company_id'    => $company_id > 0 ? $company_id : null,
                    'company_name'  => $p['route']->company_name,
                    'allowance'     => (int) $p['route']->allowance,
                    'note'          => $p['note'],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ),
                array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
            );
            $inserted++;
        }

        return array(
            'success'  => true,
            'inserted' => $inserted,
            'warnings' => $warnings,
            'message'  => $inserted . '件を登録しました。',
        );
    }

    // =====================================================
    //  取得
    // =====================================================

    /**
     * 対象年月の利用実績を取得（氏名はライブ解決）
     *
     * @param array $args  year, month, employee_code(任意), vehicle_code(任意)
     * @return array
     */
    public static function get_records( $args = array() ) {
        global $wpdb;

        $year  = isset( $args['year'] )  ? (int) $args['year']  : 0;
        $month = isset( $args['month'] ) ? (int) $args['month'] : 0;
        $table = FA_DB_Install::table_records();

        $where  = array( '1=1' );
        $params = array();

        if ( $year > 0 && $month > 0 ) {
            $start = sprintf( '%04d-%02d-01', $year, $month );
            $end   = gmdate( 'Y-m-t', strtotime( $start ) );
            $where[]  = 'use_date BETWEEN %s AND %s';
            $params[] = $start;
            $params[] = $end;
        }
        if ( ! empty( $args['employee_code'] ) ) {
            $where[]  = 'employee_code = %s';
            $params[] = (string) $args['employee_code'];
        }
        if ( ! empty( $args['vehicle_code'] ) ) {
            $where[]  = 'vehicle_code = %s';
            $params[] = (string) $args['vehicle_code'];
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
        $sql       = "SELECT * FROM `{$table}` {$where_sql} ORDER BY use_date ASC, id ASC";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore
        }

        $rows = $wpdb->get_results( $sql ); // phpcs:ignore
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $map = FA_Employee_Bridge::get_code_name_map();
        foreach ( $rows as $row ) {
            // 氏名はライブ優先、取れなければスナップショットにフォールバック
            $row->employee_name = FA_Employee_Bridge::resolve_name( $row->employee_code, $row->employee_name, $map );
        }
        return $rows;
    }

    public static function get_by_id( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_records();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ) // phpcs:ignore
        );
    }

    /**
     * 1件更新（日付・航路・車番を編集）
     * 保存時と同じく航路・従業員のスナップショットを取り直す。
     * 同一社員＋同一日＋同一航路の重複は警告のみ（自分自身は除外）。
     *
     * @param int   $id
     * @param array $data  use_date, route_no, vehicle_code, employee_code, note
     * @return array
     */
    public static function update( $id, $data ) {
        global $wpdb;

        $id = (int) $id;
        if ( $id <= 0 || ! self::get_by_id( $id ) ) {
            return array( 'success' => false, 'message' => '対象の実績が見つかりません。' );
        }

        $use_date     = isset( $data['use_date'] )     ? trim( (string) $data['use_date'] ) : '';
        $route_no     = isset( $data['route_no'] )     ? (int) $data['route_no'] : 0;
        $vehicle_code = isset( $data['vehicle_code'] ) ? trim( (string) $data['vehicle_code'] ) : '';
        $emp_code     = isset( $data['employee_code'] ) ? trim( (string) $data['employee_code'] ) : '';
        $note         = isset( $data['note'] )         ? sanitize_text_field( (string) $data['note'] ) : '';

        if ( '' === $use_date || ! self::valid_date( $use_date ) ) {
            return array( 'success' => false, 'message' => '乗車月日が正しくありません（YYYY-MM-DD）。' );
        }
        if ( $route_no <= 0 ) {
            return array( 'success' => false, 'message' => '航路番号を入力してください。' );
        }
        $route = FA_Route::get_by_no( $route_no );
        if ( ! $route ) {
            return array( 'success' => false, 'message' => '航路番号 ' . $route_no . ' は航路マスタに見つかりません。' );
        }
        if ( '' === $vehicle_code ) {
            return array( 'success' => false, 'message' => '車番を入力してください。' );
        }
        if ( ! FA_Vehicle_Bridge::exists( $vehicle_code ) ) {
            return array( 'success' => false, 'message' => '車番 ' . $vehicle_code . ' は車両管理ツールに登録されていません。車両管理画面から登録してください。' );
        }
        if ( '' === $emp_code ) {
            return array( 'success' => false, 'message' => '乗車名を入力してください。' );
        }
        $emp = FA_Employee_Bridge::get_by_code( $emp_code );
        if ( ! $emp ) {
            return array( 'success' => false, 'message' => '乗車名（社員コード ' . $emp_code . '）が社員情報に見つかりません。' );
        }
        $emp_name = FA_Employee_Bridge::resolve_name( $emp_code, '' );

        $table = FA_DB_Install::table_records();

        // 重複チェック（自分自身は除外）
        $dup = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE employee_code = %s AND use_date = %s AND route_id = %d AND id <> %d", // phpcs:ignore
                $emp_code,
                $use_date,
                (int) $route->id,
                $id
            )
        );

        $company_id = (int) $route->company_id;

        $wpdb->update(
            $table,
            array(
                'employee_code' => $emp_code,
                'employee_name' => $emp_name,
                'vehicle_code'  => $vehicle_code,
                'use_date'      => $use_date,
                'route_id'      => (int) $route->id,
                'route_no'      => (int) $route->route_no,
                'route_name'    => $route->route_name,
                'company_id'    => $company_id > 0 ? $company_id : null,
                'company_name'  => $route->company_name,
                'allowance'     => (int) $route->allowance,
                'note'          => $note,
                'updated_at'    => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );

        $result = array( 'success' => true, 'message' => '実績を更新しました。' );
        if ( $dup > 0 ) {
            $result['warning'] = '同じ社員・日付・航路の実績が他にもあります（更新は行いました）。';
        }
        return $result;
    }

    public static function delete( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_records();
        $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
        return array( 'success' => true, 'message' => '実績を削除しました。' );
    }

    // =====================================================
    //  ヘルパー
    // =====================================================

    /**
     * YYYY-MM-DD として妥当な日付か
     */
    public static function valid_date( $s ) {
        $d = DateTime::createFromFormat( 'Y-m-d', $s );
        return $d && $d->format( 'Y-m-d' ) === $s;
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

    public static function ajax_save() {
        self::verify();

        $raw  = isset( $_POST['rows_json'] ) ? wp_unslash( $_POST['rows_json'] ) : '';
        $rows = json_decode( $raw, true );

        if ( ! is_array( $rows ) ) {
            wp_send_json_error( array( 'message' => '送信データの形式が不正です。' ) );
        }

        $result = self::save_bulk( $rows );
        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result );
    }

    public static function ajax_get_list() {
        self::verify();
        $rows = self::get_records( array(
            'year'          => isset( $_POST['year'] )  ? (int) $_POST['year']  : 0,
            'month'         => isset( $_POST['month'] ) ? (int) $_POST['month'] : 0,
            'employee_code' => isset( $_POST['employee_code'] ) ? sanitize_text_field( wp_unslash( $_POST['employee_code'] ) ) : '',
            'vehicle_code'  => isset( $_POST['vehicle_code'] )  ? sanitize_text_field( wp_unslash( $_POST['vehicle_code'] ) )  : '',
        ) );
        wp_send_json_success( array( 'items' => $rows ) );
    }

    public static function ajax_update() {
        self::verify();
        $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $data = array(
            'use_date'      => isset( $_POST['use_date'] )      ? wp_unslash( $_POST['use_date'] )      : '',
            'route_no'      => isset( $_POST['route_no'] )      ? (int) $_POST['route_no'] : 0,
            'vehicle_code'  => isset( $_POST['vehicle_code'] )  ? wp_unslash( $_POST['vehicle_code'] )  : '',
            'employee_code' => isset( $_POST['employee_code'] ) ? wp_unslash( $_POST['employee_code'] ) : '',
            'note'          => isset( $_POST['note'] )          ? wp_unslash( $_POST['note'] )          : '',
        );
        $result = self::update( $id, $data );
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
