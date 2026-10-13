<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * シミュレーションCSV出力画面（運用管理者以上）
 * シミュレーション航路の実績を、シミュレーション手当で試算して表示・CSV出力する。
 */
$fa_now   = current_time( 'timestamp' );
$fa_year  = (int) gmdate( 'Y', $fa_now );
$fa_month = (int) gmdate( 'n', $fa_now );
?>
<div class="wrap fa-wrap fa-simulation">
    <h1>シミュレーションCSV出力</h1>

    <div class="fa-card">
        <p class="description" style="margin:0 0 12px;">
            マスタ管理「シミュレーション航路」に登録した航路の実績を、シミュレーション手当で試算して現行手当との差額を出力します。給与計算には影響しません。
        </p>
        <div class="fa-summary__toolbar">
            <label>対象年月：
                <select id="fa-sim-year">
                    <?php for ( $y = $fa_year + 1; $y >= $fa_year - 3; $y-- ) : ?>
                        <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $y, $fa_year ); ?>><?php echo esc_html( $y ); ?>年</option>
                    <?php endfor; ?>
                </select>
                <select id="fa-sim-month">
                    <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                        <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $m, $fa_month ); ?>><?php echo esc_html( $m ); ?>月</option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="button" class="button button-primary" id="fa-sim-show">表示</button>
            <button type="button" class="fa-summary__csv" id="fa-sim-csv">
                <span class="dashicons dashicons-download" aria-hidden="true"></span>CSV出力
            </button>
        </div>

        <div class="fa-msg" id="fa-sim-run-msg" style="display:none;"></div>

        <table class="fa-table fa-summary-table">
            <thead>
                <tr>
                    <th>氏名</th>
                    <th>航路</th>
                    <th style="width:5em;">件数</th>
                    <th style="width:9em;">現行手当</th>
                    <th style="width:9em;">シミュレーション手当</th>
                    <th style="width:9em;">差額</th>
                </tr>
            </thead>
            <tbody id="fa-sim-result-tbody">
                <tr><td colspan="6">「表示」を押してください。</td></tr>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2" style="text-align:right;">合計</th>
                    <th class="fa-num" id="fa-sim-t-count">0</th>
                    <th class="fa-num" id="fa-sim-t-current">0</th>
                    <th class="fa-num" id="fa-sim-t-sim">0</th>
                    <th class="fa-num" id="fa-sim-t-diff">0</th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
