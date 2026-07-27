/**
 * フェリー手当管理 — 管理画面共通スクリプト
 * この段階では共通ユーティリティのみ。各画面のロジックは
 * 対応するビュー作成時にこのファイルへ追記する（完全置換方針）。
 */
(function ($) {
    'use strict';

    // localize されたデータ。未定義でも落ちないように保険。
    var FA = window.faData || {};

    /**
     * 共通 AJAX ヘルパー
     * @param {string} action   wp_ajax アクション名（fa_ プレフィックスは付けない側で付与済み想定）
     * @param {string} nonceKey faData.nonce のキー（'vehicle' | 'route' など）
     * @param {object} data     追加パラメータ
     * @returns {jqXHR}
     */
    function faPost(action, nonceKey, data) {
        var payload = $.extend(
            {
                action: action,
                nonce: (FA.nonce && FA.nonce[nonceKey]) ? FA.nonce[nonceKey] : ''
            },
            data || {}
        );
        return $.post(FA.ajaxurl, payload);
    }

    // 数値の桁区切り（サマリ・手当表示で使用予定）
    function faFormatNumber(n) {
        n = parseInt(n, 10);
        if (isNaN(n)) { return '0'; }
        return n.toLocaleString('ja-JP');
    }

    // 他ファイル・後続ロジックから使えるよう名前空間に公開
    window.FerryAllowance = {
        data: FA,
        post: faPost,
        formatNumber: faFormatNumber
    };

    $(function () {
        // 各画面の初期化は faData.page で分岐して追記していく。
        // 例:
        // if (FA.page === 'ferry-allowance-routes') { initRoutes(); }
    });

})(jQuery);
