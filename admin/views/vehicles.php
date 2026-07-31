<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * 車番マスタ管理画面
 * 車番コード ⇄ 従業員 の対応表を編集する。
 * 従業員セレクトは在籍社員（emp_master）を氏名のみで表示し、値は社員コード。
 */
$fa_employees = FA_Employee_Bridge::get_active_employees();
?>
<div class="wrap fa-wrap fa-vehicles">
    <h1>車番マスタ管理</h1>

    <p class="description" style="margin:0 0 12px;">
        車番コードと従業員の対応表です。担当が変わったら該当行を編集して書き換えてください（時期の記録は保持しません）。
    </p>

    <div class="fa-msg" id="fa-vehicle-msg" style="display:none;"></div>

    <div class="fa-vehicles__layout">

        <!-- 左：登録・編集フォーム -->
        <div class="fa-vehicles__form">
            <div class="fa-card">
                <h2 class="fa-card__title" id="fa-vehicle-form-title">車番を登録</h2>

                <input type="hidden" id="fa-vehicle-id" value="0">

                <div class="fa-field">
                    <label for="fa-vehicle-code">車番コード<span class="fa-req">*</span></label><br>
                    <input type="text" id="fa-vehicle-code" class="regular-text" maxlength="20" placeholder="例：1023">
                </div>

                <div class="fa-field">
                    <label for="fa-vehicle-emp">従業員<span class="fa-req">*</span></label><br>
                    <select id="fa-vehicle-emp" class="regular-text">
                        <option value="">— 選択してください —</option>
                        <?php foreach ( $fa_employees as $emp ) : ?>
                            <?php
                            $code = isset( $emp->employee_code ) ? (string) $emp->employee_code : '';
                            $name = isset( $emp->name ) ? (string) $emp->name : '';
                            if ( '' === $code ) {
                                continue;
                            }
                            ?>
                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <p>
                    <button type="button" class="button button-primary" id="fa-vehicle-save">登録</button>
                    <button type="button" class="button" id="fa-vehicle-reset" style="display:none;">キャンセル</button>
                </p>
            </div>
        </div>

        <!-- 右：一覧 -->
        <div class="fa-vehicles__list">
            <div class="fa-card">
                <div class="fa-vehicles__toolbar">
                    <input type="search" id="fa-vehicle-search" class="regular-text" placeholder="車番コード・社員コードで検索">
                </div>

                <table class="fa-table" id="fa-vehicle-table">
                    <thead>
                        <tr>
                            <th style="width:10em;">車番コード</th>
                            <th>従業員</th>
                            <th style="width:9em;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="fa-vehicle-tbody">
                        <tr><td colspan="3">読み込み中…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
