/**
 * フェリー手当管理 — 管理画面共通スクリプト
 *
 * 方針：JSは差分編集で破損しやすいため、常に完全置換で更新する。
 * 各画面の初期化は faData.page で分岐する。
 */
(function ($) {
    'use strict';

    var FA = window.faData || {};

    // =====================================================
    //  共通ユーティリティ
    // =====================================================

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

    function faFormatNumber(n) {
        n = parseInt(n, 10);
        if (isNaN(n)) { return '0'; }
        return n.toLocaleString('ja-JP');
    }

    // HTMLエスケープ（一覧描画時のXSS対策）
    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    // 画面上部メッセージ表示
    function showMsg($box, text, isError) {
        $box.removeClass('is-error is-success')
            .addClass(isError ? 'is-error' : 'is-success')
            .text(text)
            .show();
        if (!isError) {
            setTimeout(function () { $box.fadeOut(300); }, 2500);
        }
    }

    // 名前空間公開
    window.FerryAllowance = {
        data: FA,
        post: faPost,
        formatNumber: faFormatNumber
    };

    // =====================================================
    //  航路マスタ管理
    // =====================================================

    function initRoutes() {

        var $msg      = $('#fa-route-msg');
        var $tbody    = $('#fa-route-tbody');
        var $id       = $('#fa-route-id');
        var $no       = $('#fa-route-no');
        var $name     = $('#fa-route-name');
        var $allow    = $('#fa-route-allowance');
        var $sort     = $('#fa-route-sort');
        var $active   = $('#fa-route-active');
        var $title    = $('#fa-route-form-title');
        var $saveBtn  = $('#fa-route-save');
        var $resetBtn = $('#fa-route-reset');
        var $search   = $('#fa-route-search');
        var $inactive = $('#fa-route-include-inactive');

        var searchTimer = null;

        // ---- 一覧取得・描画 ----
        function loadList() {
            faPost('fa_route_get_list', 'route', {
                include_inactive: $inactive.is(':checked') ? '1' : '0',
                keyword: $search.val() || ''
            }).done(function (res) {
                if (!res || !res.success) {
                    $tbody.html('<tr><td colspan="5">取得に失敗しました。</td></tr>');
                    return;
                }
                renderList(res.data.items || []);
            }).fail(function () {
                $tbody.html('<tr><td colspan="5">通信エラーが発生しました。</td></tr>');
            });
        }

        function renderList(items) {
            if (!items.length) {
                $tbody.html('<tr><td colspan="5">該当する航路がありません。</td></tr>');
                return;
            }
            var rows = items.map(function (r) {
                var isActive = parseInt(r.is_active, 10) === 1;
                var stateLabel = isActive ? '有効' : '無効';
                var toggleLabel = isActive ? '無効化' : '有効化';
                return '' +
                    '<tr class="' + (isActive ? '' : 'is-inactive') + '" data-id="' + esc(r.id) + '">' +
                        '<td>' + esc(r.route_no) + '</td>' +
                        '<td>' + esc(r.route_name) + '</td>' +
                        '<td class="fa-num">' + faFormatNumber(r.allowance) + '</td>' +
                        '<td>' + stateLabel + '</td>' +
                        '<td>' +
                            '<button type="button" class="button fa-btn-sm js-edit">編集</button> ' +
                            '<button type="button" class="button fa-btn-sm js-toggle">' + toggleLabel + '</button> ' +
                            '<button type="button" class="button fa-btn-sm js-delete">削除</button>' +
                        '</td>' +
                    '</tr>';
            }).join('');
            $tbody.html(rows);
        }

        // ---- フォーム制御 ----
        function resetForm() {
            $id.val('0');
            $no.val('');
            $name.val('');
            $allow.val('');
            $sort.val('');
            $active.prop('checked', true);
            $title.text('航路を登録');
            $saveBtn.text('登録');
            $resetBtn.hide();
        }

        function fillForm(r) {
            $id.val(r.id);
            $no.val(r.route_no);
            $name.val(r.route_name);
            $allow.val(r.allowance);
            $sort.val(r.sort_order);
            $active.prop('checked', parseInt(r.is_active, 10) === 1);
            $title.text('航路を編集（No.' + r.route_no + '）');
            $saveBtn.text('更新');
            $resetBtn.show();
            $('html, body').animate({ scrollTop: 0 }, 200);
        }

        // ---- 保存 ----
        function save() {
            var no = parseInt($no.val(), 10);
            if (isNaN(no) || no < 1) {
                showMsg($msg, '航路番号は1以上で入力してください。', true);
                $no.focus();
                return;
            }
            if (!$.trim($name.val())) {
                showMsg($msg, '航路名を入力してください。', true);
                $name.focus();
                return;
            }
            var allow = parseInt($allow.val(), 10);
            if (isNaN(allow) || allow < 0) {
                showMsg($msg, 'フェリー手当は0以上で入力してください。', true);
                $allow.focus();
                return;
            }

            $saveBtn.prop('disabled', true);

            faPost('fa_route_save', 'route', {
                id: $id.val(),
                route_no: no,
                route_name: $.trim($name.val()),
                allowance: allow,
                sort_order: $sort.val() === '' ? no : parseInt($sort.val(), 10),
                is_active: $active.is(':checked') ? 1 : 0
            }).done(function (res) {
                if (res && res.success) {
                    showMsg($msg, res.data.message || '保存しました。', false);
                    resetForm();
                    loadList();
                } else {
                    showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '保存に失敗しました。', true);
                }
            }).fail(function () {
                showMsg($msg, '通信エラーが発生しました。', true);
            }).always(function () {
                $saveBtn.prop('disabled', false);
            });
        }

        // ---- 有効切替 ----
        function toggle(id) {
            faPost('fa_route_toggle', 'route', { id: id }).done(function (res) {
                if (res && res.success) {
                    showMsg($msg, res.data.message || '状態を変更しました。', false);
                    loadList();
                } else {
                    showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '変更に失敗しました。', true);
                }
            }).fail(function () {
                showMsg($msg, '通信エラーが発生しました。', true);
            });
        }

        // ---- 削除 ----
        function del(id, name) {
            if (!window.confirm('航路「' + name + '」を削除します。よろしいですか？\n（登録済みの利用実績には影響しません）')) {
                return;
            }
            faPost('fa_route_delete', 'route', { id: id }).done(function (res) {
                if (res && res.success) {
                    showMsg($msg, res.data.message || '削除しました。', false);
                    // 編集中の行を消した場合はフォームもリセット
                    if ($id.val() === String(id)) { resetForm(); }
                    loadList();
                } else {
                    showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '削除に失敗しました。', true);
                }
            }).fail(function () {
                showMsg($msg, '通信エラーが発生しました。', true);
            });
        }

        // ---- イベント ----
        $saveBtn.on('click', save);
        $resetBtn.on('click', resetForm);

        $inactive.on('change', loadList);

        $search.on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(loadList, 300);
        });

        // 一覧内のボタン（委譲）
        $tbody.on('click', '.js-edit', function () {
            var id = $(this).closest('tr').data('id');
            faPost('fa_route_get_list', 'route', { include_inactive: '1', keyword: '' })
                .done(function (res) {
                    if (res && res.success) {
                        var found = (res.data.items || []).filter(function (x) {
                            return String(x.id) === String(id);
                        })[0];
                        if (found) { fillForm(found); }
                    }
                });
        });

        $tbody.on('click', '.js-toggle', function () {
            toggle($(this).closest('tr').data('id'));
        });

        $tbody.on('click', '.js-delete', function () {
            var $tr = $(this).closest('tr');
            del($tr.data('id'), $tr.find('td:eq(1)').text());
        });

        // 初期ロード
        resetForm();
        loadList();
    }

    // =====================================================
    //  画面分岐
    // =====================================================

    $(function () {
        switch (FA.page) {
            case 'ferry-allowance-routes':
                initRoutes();
                break;
            default:
                break;
        }
    });

})(jQuery);
