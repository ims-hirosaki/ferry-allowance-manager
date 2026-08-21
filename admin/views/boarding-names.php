<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$fa_vehicles  = FA_Vehicle_Bridge::get_vehicle_numbers();
$fa_employees = FA_Employee_Bridge::get_active_employees();
?>
<div class="wrap fa-wrap fa-boarding-names">
    <h1>乗車名マスタ</h1>
    <p class="description" style="margin:0 0 12px;">
        車両管理の車番と、通常乗車する社員を紐づけます。入力画面ではこの設定から乗車名が自動表示されます。変更しても登録済みの過去実績には影響しません。
    </p>
    <div class="fa-msg" id="fa-boarding-msg" style="display:none;"></div>

    <div class="fa-master-layout">
        <div class="fa-master-form">
            <div class="fa-card">
                <h2 class="fa-card__title" id="fa-boarding-form-title">乗車名を登録</h2>
                <input type="hidden" id="fa-boarding-id" value="0">
                <div class="fa-field">
                    <label for="fa-boarding-vehicle">車番<span class="fa-req">*</span></label><br>
                    <select id="fa-boarding-vehicle" class="regular-text">
                        <option value="">— 選択してください —</option>
                        <?php foreach ( $fa_vehicles as $vehicle_code ) : ?>
                            <option value="<?php echo esc_attr( $vehicle_code ); ?>"><?php echo esc_html( $vehicle_code ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fa-field">
                    <label for="fa-boarding-employee">乗車名<span class="fa-req">*</span></label><br>
                    <select id="fa-boarding-employee" class="regular-text">
                        <option value="">— 選択してください —</option>
                        <?php foreach ( $fa_employees as $employee ) : ?>
                            <?php
                            $code = isset( $employee->employee_code ) ? (string) $employee->employee_code : '';
                            $name = isset( $employee->name ) ? (string) $employee->name : '';
                            if ( '' === $code ) { continue; }
                            ?>
                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name . '（' . $code . '）' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fa-field">
                    <label><input type="checkbox" id="fa-boarding-active" checked> 有効</label>
                </div>
                <p>
                    <button type="button" class="button button-primary" id="fa-boarding-save">登録</button>
                    <button type="button" class="button" id="fa-boarding-reset" style="display:none;">キャンセル</button>
                </p>
            </div>
        </div>

        <div class="fa-master-list">
            <div class="fa-card">
                <div class="fa-master-toolbar">
                    <input type="search" id="fa-boarding-search" class="regular-text" placeholder="車番・社員コードで検索">
                    <label><input type="checkbox" id="fa-boarding-include-inactive"> 無効を含む</label>
                </div>
                <table class="fa-table">
                    <thead><tr><th style="width:10em;">車番</th><th>乗車名</th><th style="width:7em;">状態</th><th style="width:15em;">操作</th></tr></thead>
                    <tbody id="fa-boarding-tbody"><tr><td colspan="4">読み込み中…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
