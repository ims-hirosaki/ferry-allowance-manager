<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * シミュレーション航路マスタ管理画面（運用管理者以上）
 * 旧・手当ルールとの比較試算に使う航路と手当金額を登録する。
 * 給与計算・月次サマリには影響しない。
 */
$fa_sim_routes = FA_Route::get_list( array( 'include_inactive' => true ) );
?>
<div class="fa-sim-routes">
    <p class="description" style="margin:0 0 12px;">
        旧・手当ルールとのギャップを試算するための航路と手当金額を登録します。ここでの設定は実際の給与計算・月次サマリには影響しません。
    </p>
    <div class="fa-msg" id="fa-sim-msg" style="display:none;"></div>

    <div class="fa-master-layout">
        <div class="fa-master-form">
            <div class="fa-card">
                <h2 class="fa-card__title" id="fa-sim-form-title">シミュレーション航路を登録</h2>
                <input type="hidden" id="fa-sim-id" value="0">
                <div class="fa-field">
                    <label for="fa-sim-route">航路<span class="fa-req">*</span></label><br>
                    <select id="fa-sim-route" class="regular-text">
                        <option value="">— 選択してください —</option>
                        <?php foreach ( $fa_sim_routes as $route ) : ?>
                            <option value="<?php echo esc_attr( $route->id ); ?>">
                                <?php echo esc_html( $route->route_no . ' ' . $route->route_name . ( (int) $route->is_active ? '' : '（無効）' ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fa-field">
                    <label for="fa-sim-allowance">手当金額（円）<span class="fa-req">*</span></label><br>
                    <input type="number" id="fa-sim-allowance" class="regular-text" min="0" step="100" placeholder="例：10000">
                </div>
                <p>
                    <button type="button" class="button button-primary" id="fa-sim-save">登録</button>
                    <button type="button" class="button" id="fa-sim-reset" style="display:none;">キャンセル</button>
                </p>
            </div>
        </div>

        <div class="fa-master-list">
            <div class="fa-card">
                <table class="fa-table" id="fa-sim-table">
                    <thead>
                        <tr>
                            <th style="width:5em;">番号</th>
                            <th>航路名</th>
                            <th style="width:8em;">現行手当</th>
                            <th style="width:9em;">シミュレーション手当</th>
                            <th style="width:10em;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="fa-sim-tbody">
                        <tr><td colspan="5">読み込み中…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
