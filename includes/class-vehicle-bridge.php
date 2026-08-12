<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_Vehicle_Bridge
 * vehicle-manager（車両管理ツール）の公開API関数をラップする。
 * 本プラグインは vehicle_manager テーブルを直接クエリせず、必ずこのブリッジ経由で参照する。
 * 「車番」は vehicle-manager の一連指定番号（serial_number）を用いる。
 */
class FA_Vehicle_Bridge {

    /**
     * vehicle-manager が利用可能か
     */
    public static function is_available() {
        return function_exists( 'vm_get_vehicle_numbers' );
    }

    /**
     * 登録済み車番（一連指定番号）一覧を取得する
     *
     * @return array
     */
    public static function get_vehicle_numbers() {
        if ( function_exists( 'vm_get_vehicle_numbers' ) ) {
            $list = vm_get_vehicle_numbers();
            return is_array( $list ) ? $list : array();
        }
        return array();
    }

    /**
     * 指定した車番が vehicle-manager に登録されているか
     *
     * @param string $vehicle_code
     * @return bool
     */
    public static function exists( $vehicle_code ) {
        if ( function_exists( 'vm_vehicle_exists' ) ) {
            return (bool) vm_vehicle_exists( $vehicle_code );
        }
        return false;
    }
}
