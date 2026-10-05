<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * 実績一覧・編集・削除画面
 * 年月で絞り込み、登録済みのフェリー利用実績を一覧・編集・削除する。
 * 編集は上部フォームで行い、乗車名は乗車名マスタから自動反映し、例外時のみ社員一覧から選択する。
 */
$fa_now   = current_time( 'timestamp' );
$fa_year  = (int) gmdate( 'Y', $fa_now );
$fa_month = (int) gmdate( 'n', $fa_now );
?>
<div class="wrap fa-wrap fa-records">
    <h1>実績一覧・編集</h1>

    <div class="fa-msg" id="fa-records-msg" style="display:none;"></div>

    <!-- 編集フォーム（初期は非表示） -->
    <div class="fa-card fa-records__editcard" id="fa-records-editcard" style="display:none;">
        <h2 class="fa-card__title" id="fa-records-edit-title">実績を編集</h2>
        <input type="hidden" id="fa-records-edit-id" value="0">
        <div class="fa-records__editgrid">
            <div class="fa-field">
                <label>乗車月日<span class="fa-req">*</span></label><br>
                <input type="date" id="fa-records-edit-date">
            </div>
            <div class="fa-field">
                <label>航路名検索<span class="fa-req">*</span></label><br>
                <input type="text" id="fa-records-edit-routeinput" list="fa-route-datalist" placeholder="番号 or 航路名">
            </div>
            <div class="fa-field">
                <label>フェリー会社</label><br>
                <input type="text" id="fa-records-edit-company" readonly tabindex="-1">
            </div>
            <div class="fa-field">
                <label>航路</label><br>
                <input type="text" id="fa-records-edit-routename" readonly tabindex="-1">
            </div>
            <div class="fa-field">
                <label>車番<span class="fa-req">*</span></label><br>
                <input type="text" id="fa-records-edit-vehicle" list="fa-vehicle-datalist" placeholder="車番">
            </div>
            <div class="fa-field">
                <label>乗車名<span class="fa-req">*</span></label><br>
                <span class="fa-employee-control">
                    <input type="text" id="fa-records-edit-empname" placeholder="車番から自動表示" readonly tabindex="-1">
                    <button type="button" class="button fa-btn-sm" id="fa-records-edit-manual">手動選択</button>
                </span>
            </div>
            <div class="fa-field">
                <label>フェリー手当</label><br>
                <input type="text" id="fa-records-edit-allow" class="fa-num" readonly tabindex="-1">
            </div>
        </div>
        <p>
            <button type="button" class="button button-primary" id="fa-records-update">更新</button>
            <button type="button" class="button" id="fa-records-edit-cancel">キャンセル</button>
        </p>
    </div>

    <div class="fa-card">
        <div class="fa-records__toolbar">
            <label>対象年月：
                <select id="fa-records-year">
                    <?php for ( $y = $fa_year + 1; $y >= $fa_year - 3; $y-- ) : ?>
                        <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $y, $fa_year ); ?>><?php echo esc_html( $y ); ?>年</option>
                    <?php endfor; ?>
                </select>
                <select id="fa-records-month">
                    <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                        <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $m, $fa_month ); ?>><?php echo esc_html( $m ); ?>月</option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="button" class="button button-primary" id="fa-records-show">表示</button>
        </div>

        <div class="fa-records__toolbar fa-records__filters">
            <label>乗車月日：
                <input type="date" id="fa-records-filter-from">
                〜
                <input type="date" id="fa-records-filter-to">
            </label>
            <label>乗車名：
                <select id="fa-records-filter-emp">
                    <option value="">すべて</option>
                </select>
            </label>
            <label>車番：
                <input type="text" id="fa-records-filter-vehicle" list="fa-vehicle-datalist" placeholder="車番" style="width:8em;">
            </label>
            <button type="button" class="button" id="fa-records-filter-clear">条件クリア</button>
            <span class="description">※乗車月日を指定した場合は、対象年月より優先されます。</span>
        </div>

        <table class="fa-table fa-records-table">
            <thead>
                <tr>
                    <th class="fa-sortable" data-sort="use_date" style="width:8em;">乗車月日<span class="fa-sort-ind"></span></th>
                    <th class="fa-sortable" data-sort="route_no" style="width:4em;">航路番号<span class="fa-sort-ind"></span></th>
                    <th class="fa-sortable" data-sort="route_name">航路<span class="fa-sort-ind"></span></th>
                    <th class="fa-sortable" data-sort="company_name" style="width:10em;">フェリー会社<span class="fa-sort-ind"></span></th>
                    <th class="fa-sortable" data-sort="transport_bureau" style="width:8em;">運輸支局<span class="fa-sort-ind"></span></th>
                    <th class="fa-sortable" data-sort="vehicle_code" style="width:6em;">車番<span class="fa-sort-ind"></span></th>
                    <th class="fa-sortable" data-sort="employee_name" style="width:10em;">乗車名<span class="fa-sort-ind"></span></th>
                    <th class="fa-sortable" data-sort="allowance" style="width:8em;">手当<span class="fa-sort-ind"></span></th>
                    <th style="width:9em;">操作</th>
                </tr>
            </thead>
            <tbody id="fa-records-tbody">
                <tr><td colspan="9">「表示」を押してください。</td></tr>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="7" style="text-align:right;">合計</th>
                    <th class="fa-num" id="fa-records-total">0</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- サジェスト用データリスト（admin.jsで流し込む） -->
    <datalist id="fa-route-datalist"></datalist>
    <datalist id="fa-vehicle-datalist"></datalist>
</div>
