<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * フェリー手当入力画面
 * 複数行を1枚のシートで直接入力し、一括登録する。
 *  - 航路名検索：番号でも航路名でも引けるサジェスト（A案）。フェリー会社・航路・手当を自動反映。
 *  - 車番：vehicle-manager（車両管理ツール）の登録車番から入力補完。未登録は登録画面へ誘導。
 *  - 乗車名：社員一覧から入力補完。未登録は登録画面へ誘導。
 * データ授受は admin.js（faData.routes / faData.vehicleNumbers / faData.employees）と AJAX（fa_record_save）。
 */
?>
<div class="wrap fa-wrap fa-entry">
    <h1>フェリー手当入力</h1>

    <p class="description" style="margin:0 0 12px;">
        1行につき1乗船分を入力してください（往復した場合は2行）。航路は番号でも航路名でも検索できます。
    </p>

    <div class="fa-msg" id="fa-entry-msg" style="display:none;"></div>

    <div class="fa-card">
        <table class="fa-table fa-entry-table" id="fa-entry-table">
            <thead>
                <tr>
                    <th style="width:9.5em;">乗車月日<span class="fa-req">*</span></th>
                    <th style="width:16em;">航路名検索<span class="fa-req">*</span></th>
                    <th style="width:10em;">フェリー会社</th>
                    <th>航路</th>
                    <th style="width:8em;">車番<span class="fa-req">*</span></th>
                    <th style="width:12em;">乗車名<span class="fa-req">*</span></th>
                    <th style="width:8em;">フェリー手当</th>
                    <th style="width:4em;">操作</th>
                </tr>
            </thead>
            <tbody id="fa-entry-tbody">
                <!-- 行は admin.js で生成 -->
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="6" style="text-align:right;">合計</th>
                    <th class="fa-num" id="fa-entry-total">0</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>

        <p style="margin-top:12px;">
            <button type="button" class="button" id="fa-entry-add-row">＋ 行を追加</button>
            <button type="button" class="button button-primary" id="fa-entry-save">登録</button>
        </p>
    </div>

    <!-- サジェスト用データリスト（中身は admin.js で流し込む） -->
    <datalist id="fa-route-datalist"></datalist>
    <datalist id="fa-vehicle-datalist"></datalist>
    <datalist id="fa-employee-datalist"></datalist>
</div>
