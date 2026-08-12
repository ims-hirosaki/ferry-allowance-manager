<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_Company
 * フェリー会社マスタ（ferry_companies）の CRUD と AJAX を担当する。
 * 航路マスタの紐づけ先として使用する（航路マスタ管理画面のリストから選択）。
 */
class FA_Company {

    const NONCE_ACTION = 'fa_company_nonce';

    // =====================================================
    //  READ
    // =====================================================

    /**
     * フェリー会社マスタ一覧
     *
     * @param array $args {
     *   @type bool   $include_inactive 無効も含めるか（デフォルト false）
     *   @type string $keyword          会社名の部分一致
     * }
     * @return array
     */
    public static function get_list( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'include_inactive' => false,
            'keyword'          => '',
        );
        $args  = wp_parse_args( $args, $defaults );
        $table = FA_DB_Install::table_companies();

        $where  = array( '1=1' );
        $params = array();

        if ( ! $args['include_inactive'] ) {
            $where[] = 'is_active = 1';
        }

        if ( '' !== $args['keyword'] ) {
            $like     = '%' . $wpdb->esc_like( $args['keyword'] ) . '%';
            $where[]  = 'name LIKE %s';
            $params[] = $like;
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
        $sql       = "SELECT * FROM `{$table}` {$where_sql} ORDER BY sort_order ASC, name ASC";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore
        }

        $rows = $wpdb->get_results( $sql ); // phpcs:ignore
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * ID で1件取得
     */
    public static function get_by_id( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_companies();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ) // phpcs:ignore
        );
    }

    /**
     * id => name のマップを1回で構築する（無効含む・航路への表示解決用）
     *
     * @return array
     */
    public static function get_id_name_map() {
        $map  = array();
        $rows = self::get_list( array( 'include_inactive' => true ) );
        foreach ( $rows as $row ) {
            $map[ (int) $row->id ] = (string) $row->name;
        }
        return $map;
    }

    /**
     * 航路マスタ画面のセレクト用（有効のみ）
     * array( array( id, name ), ... )
     */
    public static function get_map_for_js() {
        $rows = self::get_list( array( 'include_inactive' => false ) );
        $out  = array();
        foreach ( $rows as $row ) {
            $out[] = array(
                'id'   => (int) $row->id,
                'name' => (string) $row->name,
            );
        }
        return $out;
    }

    // =====================================================
    //  WRITE
    // =====================================================

    /**
     * 新規登録・更新
     *
     * @param array $data  name, sort_order, is_active
     * @param int   $id
     * @return array
     */
    public static function save( $data, $id = 0 ) {
        global $wpdb;
        $table = FA_DB_Install::table_companies();

        $name       = isset( $data['name'] )       ? sanitize_text_field( $data['name'] ) : '';
        $sort_order = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0;
        $is_active  = isset( $data['is_active'] )  ? (int) $data['is_active'] : 1;

        if ( '' === $name ) {
            return array( 'success' => false, 'message' => '会社名を入力してください。' );
        }

        // 会社名の重複チェック（自分自身は除外）
        $dup_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE name = %s AND id <> %d", // phpcs:ignore
                $name,
                (int) $id
            )
        );
        if ( $dup_id > 0 ) {
            return array( 'success' => false, 'message' => 'この会社名は既に登録されています。' );
        }

        $now = current_time( 'mysql' );

        if ( $id > 0 ) {
            $wpdb->update(
                $table,
                array(
                    'name'       => $name,
                    'sort_order' => $sort_order,
                    'is_active'  => $is_active,
                    'updated_at' => $now,
                ),
                array( 'id' => (int) $id ),
                array( '%s', '%d', '%d', '%s' ),
                array( '%d' )
            );
            return array( 'success' => true, 'message' => 'フェリー会社マスタを更新しました。', 'id' => (int) $id );
        }

        $wpdb->insert(
            $table,
            array(
                'name'       => $name,
                'sort_order' => $sort_order,
                'is_active'  => $is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array( '%s', '%d', '%d', '%s', '%s' )
        );
        return array( 'success' => true, 'message' => 'フェリー会社マスタを登録しました。', 'id' => (int) $wpdb->insert_id );
    }

    /**
     * 有効フラグの切り替え
     */
    public static function toggle_active( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_companies();
        $row   = self::get_by_id( $id );
        if ( ! $row ) {
            return array( 'success' => false, 'message' => '対象が見つかりません。' );
        }
        $new = ( (int) $row->is_active === 1 ) ? 0 : 1;
        $wpdb->update(
            $table,
            array( 'is_active' => $new, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $id ),
            array( '%d', '%s' ),
            array( '%d' )
        );
        return array( 'success' => true, 'is_active' => $new, 'message' => '状態を変更しました。' );
    }

    /**
     * 削除（物理削除）
     * 航路・実績への紐づけはスナップショット／NULL許容のため過去データには影響しない。
     */
    public static function delete( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_companies();
        $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
        return array( 'success' => true, 'message' => 'フェリー会社マスタを削除しました。' );
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
            'name'       => isset( $_POST['name'] )       ? wp_unslash( $_POST['name'] ) : '',
            'sort_order' => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
            'is_active'  => isset( $_POST['is_active'] )  ? (int) $_POST['is_active'] : 1,
        );
        $result = self::save( $data, $id );
        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result );
    }

    public static function ajax_toggle() {
        self::verify();
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => '対象が指定されていません。' ) );
        }
        wp_send_json_success( self::toggle_active( $id ) );
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
