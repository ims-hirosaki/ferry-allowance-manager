<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * 月次サマリ画面
 * 年月を選択して「表示」→ 実績のある社員のみを集計表示（氏名／手当（月）／件数）。
 */
$fa_now   = current_time( 'timestamp' );
$fa_year  = (int) gmdate( 'Y', $fa_now );
$fa_month = (int) gmdate( 'n', $fa_now );
?>
<div class="wrap fa-wrap fa-summary">
    <h1>月次サマリ</h1>

    <div class="fa-card">
        <div class="fa-summary__toolbar">
            <label>対象年月：
                <select id="fa-summary-year">
                    <?php for ( $y = $fa_year + 1; $y >= $fa_year - 3; $y-- ) : ?>
                        <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $y, $fa_year ); ?>><?php echo esc_html( $y ); ?>年</option>
                    <?php endfor; ?>
                </select>
                <select id="fa-summary-month">
                    <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                        <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $m, $fa_month ); ?>><?php echo esc_html( $m ); ?>月</option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="button" class="button button-primary" id="fa-summary-show">表示</button>
        </div>

        <div class="fa-msg" id="fa-summary-msg" style="display:none;"></div>

        <table class="fa-table fa-summary-table">
            <thead>
                <tr>
                    <th>氏名</th>
                    <th style="width:12em;">フェリー手当（月）</th>
                    <th style="width:6em;">件数</th>
                </tr>
            </thead>
            <tbody id="fa-summary-tbody">
                <tr><td colspan="3">「表示」を押してください。</td></tr>
            </tbody>
            <tfoot>
                <tr>
                    <th style="text-align:right;">合計</th>
                    <th class="fa-num" id="fa-summary-total">0</th>
                    <th class="fa-num" id="fa-summary-count">0</th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
