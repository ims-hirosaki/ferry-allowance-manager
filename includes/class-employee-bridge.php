<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 *  Class FA_Employee_Bridge
 * employee-manager の公開API関数をラップする。
 * 本プラグインは emp 系テーブルを直接クエリせず、必ずこのブリッジ経由で参照する。
 */
class FA_Employee_Bridge {

    /**
     * employee-manager が利用可能か
     */
    public static function is_available() {
        return function_exists( 'emp_get_active_employees' );
    }

    /**
     * 在籍中の社員一覧を取得
     *
     * @param array $args
     * @return array  社員オブジェクトの配列（employee_code, name, crew_code 等を含む）
     */
    public static function get_active_employees( $args = array() ) {
        if ( function_exists( 'emp_get_active_employees' ) ) {
            $list = emp_get_active_employees( $args );
            return is_array( $list ) ? $list : array();
        }
        return array();
    }

    /**
     * 社員コードで1件取得
     *
     * @param string $code
     * @return object|null
     */
    public static function get_by_code( $code ) {
        if ( function_exists( 'emp_get_employee_by_code' ) ) {
            return emp_get_employee_by_code( $code );
        }
        return null;
    }

    /**
     * 社員IDで1件取得
     *
     * @param int $id
     * @return object|null
     */
    public static function get_by_id( $id ) {
        if ( function_exists( 'emp_get_employee_by_id' ) ) {
            return emp_get_employee_by_id( (int) $id );
        }
        return null;
    }

    /**
     * 社員コード → 氏名 のマップを1回で構築する（在籍社員）
     * N+1 を避けるため、一覧系の画面ではこれを使う。
     *
     * @return array  array( employee_code => name )
     */
    public static function get_code_name_map() {
        $map  = array();
        $list = self::get_active_employees();
        foreach ( $list as $emp ) {
            if ( isset( $emp->employee_code ) ) {
                $map[ (string) $emp->employee_code ] = isset( $emp->name ) ? $emp->name : '';
            }
        }
        return $map;
    }

    /**
     * 社員コードから氏名をライブ解決する。
     * 在籍マップにあればそれを、無ければ個別取得（退職者等）を試みる。
     * どちらも取れなければ $fallback を返す（レコードのスナップショット氏名などを想定）。
     *
     * @param string      $code
     * @param string|null $fallback
     * @param array|null  $map      事前構築済みの code=>name マップ（任意）
     * @return string
     */
    public static function resolve_name( $code, $fallback = '', $map = null ) {
        $code = (string) $code;

        if ( is_array( $map ) && isset( $map[ $code ] ) && '' !== $map[ $code ] ) {
            return $map[ $code ];
        }

        $emp = self::get_by_code( $code );
        if ( $emp && ! empty( $emp->name ) ) {
            return $emp->name;
        }

        return (string) $fallback;
    }

    /**
     * 指定した社員コードが在籍社員として存在するか
     *
     * @param string $code
     * @return bool
     */
    public static function exists_active( $code ) {
        $emp = self::get_by_code( $code );
        if ( ! $emp ) {
            return false;
        }
        // is_active プロパティがある場合はそれを尊重、無ければ存在＝OKとする
        if ( isset( $emp->is_active ) ) {
            return (int) $emp->is_active === 1;
        }
        return true;
    }
}
