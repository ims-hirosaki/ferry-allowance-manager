<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class FA_DB_Install
 * テーブルの作成・削除・初期データ投入を担当する
 *
 * 作成テーブル
 *   {prefix}ferry_routes    航路マスタ
 *   {prefix}ferry_vehicles  車番⇄従業員マスタ（時期の概念なし・車番をキーにした対応表）
 *   {prefix}ferry_records   フェリー利用実績
 */
class FA_DB_Install {

    /**
     * 有効化時／バージョン更新時の処理
     */
    public static function activate() {
        self::create_tables();
        self::seed_routes();
        update_option( 'fa_db_version', FA_VERSION );
    }

    // =====================================================
    //  テーブル名ヘルパー
    // =====================================================

    public static function table_routes() {
        global $wpdb;
        return $wpdb->prefix . 'ferry_routes';
    }

    public static function table_vehicles() {
        global $wpdb;
        return $wpdb->prefix . 'ferry_vehicles';
    }

    public static function table_records() {
        global $wpdb;
        return $wpdb->prefix . 'ferry_records';
    }

    // =====================================================
    //  テーブル作成
    // =====================================================

    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset  = $wpdb->get_charset_collate();
        $routes   = self::table_routes();
        $vehicles = self::table_vehicles();
        $records  = self::table_records();

        $sqls = array();

        // -----------------------------------------------------
        // 航路マスタ
        // -----------------------------------------------------
        $sqls[] = "CREATE TABLE {$routes} (
            id          INT UNSIGNED        NOT NULL AUTO_INCREMENT              COMMENT '内部ID（サロゲートキー）',
            route_no    SMALLINT UNSIGNED   NOT NULL                             COMMENT '航路番号（入力キー・ユニーク）',
            route_name  VARCHAR(100)        NOT NULL                             COMMENT '航路名（例：神戸 ⇔ 新門司）',
            allowance   INT                 NOT NULL DEFAULT 0                   COMMENT 'フェリー手当（円）',
            sort_order  SMALLINT            NOT NULL DEFAULT 0                   COMMENT '表示順',
            is_active   TINYINT(1)          NOT NULL DEFAULT 1                   COMMENT '有効フラグ（0=無効）',
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_route_no (route_no),
            KEY idx_sort (sort_order)
        ) $charset;";

        // -----------------------------------------------------
        // 車番⇄従業員マスタ
        //   時期の概念なし。車番をキーに現在の担当従業員を1人保持する。
        //   担当が変わったら該当行を書き換える運用。
        // -----------------------------------------------------
        $sqls[] = "CREATE TABLE {$vehicles} (
            id             INT UNSIGNED     NOT NULL AUTO_INCREMENT              COMMENT '内部ID（サロゲートキー）',
            vehicle_code   VARCHAR(20)      NOT NULL                            COMMENT '車番コード（入力キー・ユニーク）',
            employee_code  VARCHAR(20)      NOT NULL                            COMMENT '従業員コード（emp_master.employee_code）',
            is_active      TINYINT(1)       NOT NULL DEFAULT 1                  COMMENT '有効フラグ（0=無効）',
            created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_vehicle_code (vehicle_code),
            KEY idx_employee (employee_code)
        ) $charset;";

        // -----------------------------------------------------
        // フェリー利用実績
        //   航路番号・航路名・手当額・従業員コード・氏名・車番は
        //   登録時点の値をスナップショット保存する。
        //   （マスタ改定や車番担当変更が過去の給与計算に影響しないようにするため）
        //   氏名は表示時に emp_master からライブ取得し、取得不可時のみ
        //   employee_name（スナップショット）へ COALESCE でフォールバックする。
        // -----------------------------------------------------
        $sqls[] = "CREATE TABLE {$records} (
            id             INT UNSIGNED        NOT NULL AUTO_INCREMENT           COMMENT '内部ID（サロゲートキー）',
            employee_code  VARCHAR(20)         NOT NULL                          COMMENT '従業員コード（車番マスタから解決・スナップショット）',
            employee_name  VARCHAR(100)            NULL DEFAULT NULL             COMMENT '氏名スナップショット（表示フォールバック用）',
            vehicle_code   VARCHAR(20)         NOT NULL                          COMMENT '車番コード（入力値・スナップショット）',
            use_date       DATE                NOT NULL                          COMMENT '利用日',
            route_id       INT UNSIGNED        NOT NULL                          COMMENT 'FK: ferry_routes.id',
            route_no       SMALLINT UNSIGNED   NOT NULL                          COMMENT '登録時点の航路番号',
            route_name     VARCHAR(100)        NOT NULL                          COMMENT '登録時点の航路名',
            allowance      INT                 NOT NULL DEFAULT 0                COMMENT '登録時点のフェリー手当（円）',
            note           VARCHAR(255)            NULL DEFAULT NULL             COMMENT '備考',
            created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_emp_date (employee_code, use_date),
            KEY idx_use_date (use_date),
            KEY idx_route (route_id),
            KEY idx_vehicle (vehicle_code)
        ) $charset;";

        foreach ( $sqls as $sql ) {
            dbDelta( $sql );
        }
    }

    // =====================================================
    //  航路マスタ初期データ
    // =====================================================

    /**
     * 航路マスタが空のときだけ初期24件を投入する
     * （運用後の再実行でユーザー編集内容を上書きしないため）
     */
    public static function seed_routes() {
        global $wpdb;

        $routes = self::table_routes();

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$routes}`" ); // phpcs:ignore
        if ( $count > 0 ) {
            return;
        }

        foreach ( self::default_routes() as $row ) {
            $wpdb->insert(
                $routes,
                array(
                    'route_no'   => (int) $row[0],
                    'route_name' => $row[1],
                    'allowance'  => (int) $row[2],
                    'sort_order' => (int) $row[0],
                    'is_active'  => 1,
                ),
                array( '%d', '%s', '%d', '%d', '%d' )
            );
        }
    }

    /**
     * 初期航路データ（No, 航路名, フェリー手当）
     */
    public static function default_routes() {
        return array(
            array( 1,  '神戸 ⇔ 新門司',      2000 ),
            array( 2,  '大阪 ⇔ 新門司',      2000 ),
            array( 3,  '大阪 ⇔ 別府',        1000 ),
            array( 4,  '大阪 ⇔ 志布志',      4000 ),
            array( 5,  '神戸 ⇔ 宮崎',        2000 ),
            array( 6,  '横須賀 ⇔ 新門司',   10000 ),
            array( 7,  '苫小牧 ⇔ 敦賀',     10000 ),
            array( 8,  '苫小牧 ⇔ 大洗',      8000 ),
            array( 9,  '苫小牧 ⇔ 秋田',      1000 ),
            array( 10, '小樽 ⇔ 新潟',        6000 ),
            array( 11, '神戸 ⇔ 大分',        1000 ),
            array( 12, '新潟 ⇔ 敦賀',        2000 ),
            array( 13, '泉大津 ⇔ 新門司',    2000 ),
            array( 14, '徳島 ⇔ 新門司',      4000 ),
            array( 15, '苫小牧 ⇔ 仙台',      5000 ),
            array( 16, '東京 ⇔ 徳島',        8000 ),
            array( 17, '苫小牧 ⇔ 新潟',      9000 ),
            array( 18, '敦賀 ⇔ 博多',        9000 ),
            array( 19, '秋田 ⇔ 敦賀',       10000 ),
            array( 20, '小樽 ⇔ 舞鶴',       11000 ),
            array( 21, '苫小牧 ⇔ 常陸那珂', 11000 ),
            array( 22, '仙台 ⇔ 名古屋',     11000 ),
            array( 23, '鹿児島 ⇔ 那覇',     15000 ),
            array( 24, '苫小牧 ⇔ 名古屋',   29000 ),
        );
    }

    // =====================================================
    //  テーブル削除（uninstall.php から呼び出す）
    // =====================================================

    public static function drop_tables() {
        global $wpdb;

        $tables = array(
            self::table_records(),
            self::table_vehicles(),
            self::table_routes(),
        );

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore
        }

        delete_option( 'fa_db_version' );
    }
}
