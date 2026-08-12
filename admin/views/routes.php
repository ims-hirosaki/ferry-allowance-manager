<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * 航路マスタ管理画面
 * データ取得・保存は admin.js から AJAX（fa_route_*）で行う。
 * 初期一覧は faData.routes（有効のみ）ではなく、画面表示時に
 * AJAX で「無効含む/含まない」を切り替えて取得する。
 * フェリー会社はフェリー会社マスタから選択する（未設定可）。
 */
$fa_companies = FA_Company::get_list();
?>
<div class="wrap fa-wrap fa-routes">
    <h1>航路マスタ管理</h1>

    <div class="fa-msg" id="fa-route-msg" style="display:none;"></div>

    <div class="fa-routes__layout">

        <!-- 左：登録・編集フォーム -->
        <div class="fa-routes__form">
            <div class="fa-card">
                <h2 class="fa-card__title" id="fa-route-form-title">航路を登録</h2>

                <input type="hidden" id="fa-route-id" value="0">

                <div class="fa-field">
                    <label for="fa-route-no">航路番号<span class="fa-req">*</span></label><br>
                    <input type="number" id="fa-route-no" class="regular-text" min="1" step="1" placeholder="例：1">
                </div>

                <div class="fa-field">
                    <label for="fa-route-name">航路名<span class="fa-req">*</span></label><br>
                    <input type="text" id="fa-route-name" class="regular-text" maxlength="100" placeholder="例：神戸 ⇔ 新門司">
                </div>

                <div class="fa-field">
                    <label for="fa-route-company">フェリー会社</label><br>
                    <select id="fa-route-company" class="regular-text">
                        <option value="">— 未設定 —</option>
                        <?php foreach ( $fa_companies as $company ) : ?>
                            <option value="<?php echo esc_attr( $company->id ); ?>"><?php echo esc_html( $company->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="fa-field">
                    <label for="fa-route-allowance">フェリー手当（円・0円可）<span class="fa-req">*</span></label><br>
                    <input type="number" id="fa-route-allowance" class="regular-text" min="0" step="100" placeholder="例：2000">
                </div>

                <div class="fa-field">
                    <label for="fa-route-sort">表示順</label><br>
                    <input type="number" id="fa-route-sort" class="small-text" step="1" placeholder="未入力なら航路番号">
                </div>

                <div class="fa-field">
                    <label><input type="checkbox" id="fa-route-active" checked> 有効</label>
                </div>

                <p>
                    <button type="button" class="button button-primary" id="fa-route-save">登録</button>
                    <button type="button" class="button" id="fa-route-reset" style="display:none;">キャンセル</button>
                </p>
            </div>
        </div>

        <!-- 右：一覧 -->
        <div class="fa-routes__list">
            <div class="fa-card">
                <div class="fa-routes__toolbar">
                    <input type="search" id="fa-route-search" class="regular-text" placeholder="航路番号・航路名で検索">
                    <label class="fa-routes__inactive">
                        <input type="checkbox" id="fa-route-include-inactive"> 無効も表示
                    </label>
                </div>

                <table class="fa-table" id="fa-route-table">
                    <thead>
                        <tr>
                            <th style="width:5em;">番号</th>
                            <th>航路名</th>
                            <th style="width:10em;">フェリー会社</th>
                            <th style="width:8em;">手当</th>
                            <th style="width:5em;">状態</th>
                            <th style="width:13em;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="fa-route-tbody">
                        <tr><td colspan="6">読み込み中…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
