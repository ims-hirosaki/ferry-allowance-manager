<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_Route
 * 航路マスタ（ferry_routes）の CRUD と AJAX を担当する。
 *
 * 仕様
 *  - route_no は UNIQUE（航路番号・入力キー）
 *  - 削除は物理削除だが、利用実績は route_no / route_name / allowance を
 *    スナップショット保存しているため、過去実績には影響しない。
 *  - 属人化対策（A案）用に、番号・航路名の両方で検索できるマップを JS へ渡す。
 */
class FA_Route {

    const NONCE_ACTION = 'fa_route_nonce';

    // =====================================================
    //  READ
    // =====================================================

    /**
     * 航路マスタ一覧
     *
     * @param array $args {
     *   @type bool   $include_inactive 無効も含めるか（デフォルト false）
     *   @type string $keyword          航路番号・航路名の部分一致
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
        $table = FA_DB_Install::table_routes();

        $where  = array( '1=1' );
        $params = array();

        if ( ! $args['include_inactive'] ) {
            $where[] = 'is_active = 1';
        }

        if ( '' !== $args['keyword'] ) {
            $like     = '%' . $wpdb->esc_like( $args['keyword'] ) . '%';
            $where[]  = '( route_name LIKE %s OR CAST(route_no AS CHAR) LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
        $sql       = "SELECT * FROM `{$table}` {$where_sql} ORDER BY sort_order ASC, route_no ASC";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore
        }

        $rows = $wpdb->get_results( $sql ); // phpcs:ignore
        if ( ! is_array( $rows ) ) {
            return array();
        }

        // フェリー会社名を1回のマップ構築で解決（N+1回避）
        $map = FA_Company::get_id_name_map();
        foreach ( $rows as $row ) {
            $cid = (int) $row->company_id;
            $row->company_name = ( $cid > 0 && isset( $map[ $cid ] ) ) ? $map[ $cid ] : '';
        }

        return $rows;
    }

    /**
     * ID で1件取得
     */
    public static function get_by_id( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_routes();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ) // phpcs:ignore
        );
    }

    /**
     * 航路番号で1件取得（有効のみ）。入力フォームの自動反映に使用。
     */
    public static function get_by_no( $route_no ) {
        global $wpdb;
        $table = FA_DB_Install::table_routes();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE route_no = %d AND is_active = 1", // phpcs:ignore
                (int) $route_no
            )
        );
        if ( $row ) {
            $cid = (int) $row->company_id;
            $map = FA_Company::get_id_name_map();
            $row->company_name = ( $cid > 0 && isset( $map[ $cid ] ) ) ? $map[ $cid ] : '';
        }
        return $row;
    }

    /**
     * 入力フォーム（A案サジェスト）用の配列を返す。
     * array( array( id, route_no, route_name, allowance, company_id, company_name ), ... )
     * 番号・航路名の双方で絞り込めるよう、そのまま JS へ localize する。
     */
    public static function get_map_for_js() {
        $rows = self::get_list( array( 'include_inactive' => false ) );
        $out  = array();
        foreach ( $rows as $row ) {
            $out[] = array(
                'id'           => (int) $row->id,
                'route_no'     => (int) $row->route_no,
                'route_name'   => (string) $row->route_name,
                'allowance'    => (int) $row->allowance,
                'company_id'   => (int) $row->company_id,
                'company_name' => (string) $row->company_name,
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
     * @param array $data  route_no, route_name, allowance, sort_order, is_active
     * @param int   $id
     * @return array
     */
    public static function save( $data, $id = 0 ) {
        global $wpdb;
        $table = FA_DB_Install::table_routes();

        $route_no   = isset( $data['route_no'] )   ? (int) $data['route_no'] : 0;
        $route_name = isset( $data['route_name'] ) ? sanitize_text_field( $data['route_name'] ) : '';
        $company_id = isset( $data['company_id'] ) ? (int) $data['company_id'] : 0;
        $allowance  = isset( $data['allowance'] )  ? (int) $data['allowance'] : 0;
        $sort_order = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : $route_no;
        $is_active  = isset( $data['is_active'] )  ? (int) $data['is_active'] : 1;

        // --- バリデーション ---
        if ( $route_no <= 0 ) {
            return array( 'success' => false, 'message' => '航路番号は1以上で入力してください。' );
        }
        if ( '' === $route_name ) {
            return array( 'success' => false, 'message' => '航路名を入力してください。' );
        }
        if ( $allowance < 0 ) {
            return array( 'success' => false, 'message' => 'フェリー手当は0以上で入力してください。' );
        }
        if ( $company_id > 0 && ! FA_Company::get_by_id( $company_id ) ) {
            return array( 'success' => false, 'message' => '指定されたフェリー会社が見つかりません。' );
        }
        // 航路番号の重複チェック（自分自身は除外）
        $dup_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE route_no = %d AND id <> %d", // phpcs:ignore
                $route_no,
                (int) $id
            )
        );
        if ( $dup_id > 0 ) {
            return array( 'success' => false, 'message' => 'この航路番号は既に登録されています。' );
        }

        $now = current_time( 'mysql' );

        $company_id_value = $company_id > 0 ? $company_id : null;

        if ( $id > 0 ) {
            $wpdb->update(
                $table,
                array(
                    'route_no'   => $route_no,
                    'route_name' => $route_name,
                    'company_id' => $company_id_value,
                    'allowance'  => $allowance,
                    'sort_order' => $sort_order,
                    'is_active'  => $is_active,
                    'updated_at' => $now,
                ),
                array( 'id' => (int) $id ),
                array( '%d', '%s', '%d', '%d', '%d', '%d', '%s' ),
                array( '%d' )
            );
            return array( 'success' => true, 'message' => '航路マスタを更新しました。', 'id' => (int) $id );
        }

        $wpdb->insert(
            $table,
            array(
                'route_no'   => $route_no,
                'route_name' => $route_name,
                'company_id' => $company_id_value,
                'allowance'  => $allowance,
                'sort_order' => $sort_order,
                'is_active'  => $is_active,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array( '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
        );
        return array( 'success' => true, 'message' => '航路マスタを登録しました。', 'id' => (int) $wpdb->insert_id );
    }

    /**
     * 有効フラグの切り替え
     */
    public static function toggle_active( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_routes();
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
     * 実績はスナップショット保存のため過去データには影響しない。
     */
    public static function delete( $id ) {
        global $wpdb;
        $table = FA_DB_Install::table_routes();
        $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
        return array( 'success' => true, 'message' => '航路マスタを削除しました。' );
    }

    // =====================================================
    //  AJAX
    // =====================================================

    private static function verify( $capability = 'edit_custom_plugins' ) {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }
    }

    public static function ajax_get_list() {
        self::verify( 'access_custom_plugins' );
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
            'route_no'   => isset( $_POST['route_no'] )   ? (int) $_POST['route_no'] : 0,
            'route_name' => isset( $_POST['route_name'] ) ? wp_unslash( $_POST['route_name'] ) : '',
            'company_id' => isset( $_POST['company_id'] ) ? (int) $_POST['company_id'] : 0,
            'allowance'  => isset( $_POST['allowance'] )  ? (int) $_POST['allowance'] : 0,
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
