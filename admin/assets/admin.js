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

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function showMsg($box, text, isError) {
        $box.removeClass('is-error is-success')
            .addClass(isError ? 'is-error' : 'is-success')
            .html(text)
            .show();
        if (!isError) {
            setTimeout(function () { $box.fadeOut(300); }, 3000);
        }
    }

    // 航路マップ（route_no => {name, allowance}）を faData から構築
    function buildRouteMap() {
        var m = {};
        (FA.routes || []).forEach(function (r) {
            m[String(r.route_no)] = { name: r.route_name, allowance: parseInt(r.allowance, 10) || 0 };
        });
        return m;
    }

    // 航路データリストのHTML（番号でも航路名でも引ける）
    function routeDatalistHtml() {
        return (FA.routes || []).map(function (r) {
            return '<option value="' + esc(r.route_no + ' ' + r.route_name) + '"></option>';
        }).join('');
    }

    // 車番データリストのHTML
    function vehicleDatalistHtml() {
        var v = FA.vehicles || {};
        return Object.keys(v).map(function (code) {
            return '<option value="' + esc(code) + '">' + esc(v[code].employee_name) + '</option>';
        }).join('');
    }

    // 航路番号入力から先頭の数値を取り出す
    function parseRouteNo(val) {
        var m = String(val).match(/\d+/);
        return m ? m[0] : '';
    }

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

        function resetForm() {
            $id.val('0'); $no.val(''); $name.val(''); $allow.val(''); $sort.val('');
            $active.prop('checked', true);
            $title.text('航路を登録'); $saveBtn.text('登録'); $resetBtn.hide();
        }

        function fillForm(r) {
            $id.val(r.id); $no.val(r.route_no); $name.val(r.route_name);
            $allow.val(r.allowance); $sort.val(r.sort_order);
            $active.prop('checked', parseInt(r.is_active, 10) === 1);
            $title.text('航路を編集（No.' + r.route_no + '）');
            $saveBtn.text('更新'); $resetBtn.show();
            $('html, body').animate({ scrollTop: 0 }, 200);
        }

        function save() {
            var no = parseInt($no.val(), 10);
            if (isNaN(no) || no < 1) { showMsg($msg, '航路番号は1以上で入力してください。', true); $no.focus(); return; }
            if (!$.trim($name.val())) { showMsg($msg, '航路名を入力してください。', true); $name.focus(); return; }
            var allow = parseInt($allow.val(), 10);
            if (isNaN(allow) || allow < 0) { showMsg($msg, 'フェリー手当は0以上で入力してください。', true); $allow.focus(); return; }

            $saveBtn.prop('disabled', true);
            faPost('fa_route_save', 'route', {
                id: $id.val(), route_no: no, route_name: $.trim($name.val()), allowance: allow,
                sort_order: $sort.val() === '' ? no : parseInt($sort.val(), 10),
                is_active: $active.is(':checked') ? 1 : 0
            }).done(function (res) {
                if (res && res.success) { showMsg($msg, res.data.message || '保存しました。', false); resetForm(); loadList(); }
                else { showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '保存に失敗しました。', true); }
            }).fail(function () { showMsg($msg, '通信エラーが発生しました。', true); })
              .always(function () { $saveBtn.prop('disabled', false); });
        }

        function toggle(id) {
            faPost('fa_route_toggle', 'route', { id: id }).done(function (res) {
                if (res && res.success) { showMsg($msg, res.data.message || '状態を変更しました。', false); loadList(); }
                else { showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '変更に失敗しました。', true); }
            }).fail(function () { showMsg($msg, '通信エラーが発生しました。', true); });
        }

        function del(id, name) {
            if (!window.confirm('航路「' + name + '」を削除します。よろしいですか？\n（登録済みの利用実績には影響しません）')) { return; }
            faPost('fa_route_delete', 'route', { id: id }).done(function (res) {
                if (res && res.success) { showMsg($msg, res.data.message || '削除しました。', false); if ($id.val() === String(id)) { resetForm(); } loadList(); }
                else { showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '削除に失敗しました。', true); }
            }).fail(function () { showMsg($msg, '通信エラーが発生しました。', true); });
        }

        $saveBtn.on('click', save);
        $resetBtn.on('click', resetForm);
        $inactive.on('change', loadList);
        $search.on('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(loadList, 300); });

        $tbody.on('click', '.js-edit', function () {
            var id = $(this).closest('tr').data('id');
            faPost('fa_route_get_list', 'route', { include_inactive: '1', keyword: '' }).done(function (res) {
                if (res && res.success) {
                    var found = (res.data.items || []).filter(function (x) { return String(x.id) === String(id); })[0];
                    if (found) { fillForm(found); }
                }
            });
        });
        $tbody.on('click', '.js-toggle', function () { toggle($(this).closest('tr').data('id')); });
        $tbody.on('click', '.js-delete', function () {
            var $tr = $(this).closest('tr'); del($tr.data('id'), $tr.find('td:eq(1)').text());
        });

        resetForm();
        loadList();
    }

    // =====================================================
    //  車番マスタ管理
    // =====================================================

    function initVehicles() {

        var $msg      = $('#fa-vehicle-msg');
        var $tbody    = $('#fa-vehicle-tbody');
        var $id       = $('#fa-vehicle-id');
        var $code     = $('#fa-vehicle-code');
        var $emp      = $('#fa-vehicle-emp');
        var $title    = $('#fa-vehicle-form-title');
        var $saveBtn  = $('#fa-vehicle-save');
        var $resetBtn = $('#fa-vehicle-reset');
        var $search   = $('#fa-vehicle-search');

        var searchTimer = null;

        function loadList() {
            faPost('fa_vehicle_get_list', 'vehicle', {
                include_inactive: '0', keyword: $search.val() || ''
            }).done(function (res) {
                if (!res || !res.success) { $tbody.html('<tr><td colspan="3">取得に失敗しました。</td></tr>'); return; }
                renderList(res.data.items || []);
            }).fail(function () { $tbody.html('<tr><td colspan="3">通信エラーが発生しました。</td></tr>'); });
        }

        function renderList(items) {
            if (!items.length) { $tbody.html('<tr><td colspan="3">車番が登録されていません。</td></tr>'); return; }
            var rows = items.map(function (r) {
                var empLabel = r.employee_name ? (esc(r.employee_name) + '（' + esc(r.employee_code) + '）') : esc(r.employee_code);
                return '' +
                    '<tr data-id="' + esc(r.id) + '" data-code="' + esc(r.vehicle_code) + '"' +
                        ' data-emp="' + esc(r.employee_code) + '" data-empname="' + esc(r.employee_name) + '">' +
                        '<td>' + esc(r.vehicle_code) + '</td>' +
                        '<td>' + empLabel + '</td>' +
                        '<td>' +
                            '<button type="button" class="button fa-btn-sm js-edit">編集</button> ' +
                            '<button type="button" class="button fa-btn-sm js-delete">削除</button>' +
                        '</td>' +
                    '</tr>';
            }).join('');
            $tbody.html(rows);
        }

        function resetForm() {
            $id.val('0'); $code.val(''); $emp.val('');
            $title.text('車番を登録'); $saveBtn.text('登録'); $resetBtn.hide();
        }

        function fillForm(data) {
            $id.val(data.id); $code.val(data.code);
            if (data.emp && $emp.find('option[value="' + data.emp + '"]').length === 0) {
                var label = data.empname ? data.empname : data.emp;
                $emp.append($('<option>').val(data.emp).text(label + '（現在は対象外）'));
            }
            $emp.val(data.emp);
            $title.text('車番を編集（' + data.code + '）');
            $saveBtn.text('更新'); $resetBtn.show();
            $('html, body').animate({ scrollTop: 0 }, 200);
        }

        function save() {
            var code = $.trim($code.val());
            if (!code) { showMsg($msg, '車番コードを入力してください。', true); $code.focus(); return; }
            if (!$emp.val()) { showMsg($msg, '従業員を選択してください。', true); $emp.focus(); return; }

            $saveBtn.prop('disabled', true);
            faPost('fa_vehicle_save', 'vehicle', {
                id: $id.val(), vehicle_code: code, employee_code: $emp.val(), is_active: 1
            }).done(function (res) {
                if (res && res.success) { showMsg($msg, res.data.message || '保存しました。', false); resetForm(); loadList(); }
                else { showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '保存に失敗しました。', true); }
            }).fail(function () { showMsg($msg, '通信エラーが発生しました。', true); })
              .always(function () { $saveBtn.prop('disabled', false); });
        }

        function del(id, code) {
            if (!window.confirm('車番「' + code + '」を削除します。よろしいですか？\n（登録済みの利用実績には影響しません）')) { return; }
            faPost('fa_vehicle_delete', 'vehicle', { id: id }).done(function (res) {
                if (res && res.success) { showMsg($msg, res.data.message || '削除しました。', false); if ($id.val() === String(id)) { resetForm(); } loadList(); }
                else { showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '削除に失敗しました。', true); }
            }).fail(function () { showMsg($msg, '通信エラーが発生しました。', true); });
        }

        $saveBtn.on('click', save);
        $resetBtn.on('click', resetForm);
        $search.on('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(loadList, 300); });
        $tbody.on('click', '.js-edit', function () {
            var $tr = $(this).closest('tr');
            fillForm({ id: $tr.data('id'), code: $tr.data('code'), emp: String($tr.data('emp')), empname: $tr.data('empname') });
        });
        $tbody.on('click', '.js-delete', function () {
            var $tr = $(this).closest('tr'); del($tr.data('id'), $tr.data('code'));
        });

        resetForm();
        loadList();
    }

    // =====================================================
    //  フェリー手当入力
    // =====================================================

    function initEntry() {

        var $msg    = $('#fa-entry-msg');
        var $tbody  = $('#fa-entry-tbody');
        var $total  = $('#fa-entry-total');
        var $addBtn = $('#fa-entry-add-row');
        var $saveBtn = $('#fa-entry-save');

        var routeByNo = buildRouteMap();
        var vehicles = FA.vehicles || {};

        $('#fa-route-datalist').html(routeDatalistHtml());
        $('#fa-vehicle-datalist').html(vehicleDatalistHtml());

        function rowHtml() {
            return '' +
                '<tr class="fa-entry-row">' +
                    '<td><input type="date" class="fa-e-date"></td>' +
                    '<td><input type="text" class="fa-e-routeinput" list="fa-route-datalist" placeholder="番号 or 航路名"></td>' +
                    '<td><input type="text" class="fa-e-routename" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="fa-e-vehicle" list="fa-vehicle-datalist" placeholder="車番"></td>' +
                    '<td><input type="text" class="fa-e-empname" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="fa-e-allow fa-num" readonly tabindex="-1"></td>' +
                    '<td><button type="button" class="button fa-btn-sm js-row-del">削除</button></td>' +
                '</tr>';
        }

        function addRow(n) {
            n = n || 1;
            var html = '';
            for (var i = 0; i < n; i++) { html += rowHtml(); }
            $tbody.append(html);
        }

        function ensureTrailingBlankRow() {
            var $last = $tbody.find('tr.fa-entry-row').last();
            if (!$last.length) { addRow(1); return; }
            var hasInput = $last.find('.fa-e-date').val() ||
                           $last.find('.fa-e-routeinput').val() ||
                           $last.find('.fa-e-vehicle').val();
            if (hasInput) { addRow(1); }
        }

        function recalcTotal() {
            var sum = 0;
            $tbody.find('tr.fa-entry-row').each(function () {
                var a = parseInt($(this).find('.fa-e-allow').val().replace(/[^\d-]/g, ''), 10);
                if (!isNaN(a)) { sum += a; }
            });
            $total.text(faFormatNumber(sum));
        }

        $tbody.on('input change', '.fa-e-routeinput', function () {
            var $row = $(this).closest('tr');
            var no = parseRouteNo($(this).val());
            var $name = $row.find('.fa-e-routename');
            var $allow = $row.find('.fa-e-allow');
            if (no && routeByNo[no]) {
                $name.val(routeByNo[no].name);
                $allow.val(faFormatNumber(routeByNo[no].allowance));
                $(this).removeClass('is-invalid');
            } else {
                $name.val(''); $allow.val('');
                if ($(this).val()) { $(this).addClass('is-invalid'); } else { $(this).removeClass('is-invalid'); }
            }
            recalcTotal();
            ensureTrailingBlankRow();
        });

        $tbody.on('input change', '.fa-e-vehicle', function () {
            var $row = $(this).closest('tr');
            var code = $.trim($(this).val());
            var $emp = $row.find('.fa-e-empname');
            if (code && vehicles[code]) { $emp.val(vehicles[code].employee_name); $(this).removeClass('is-invalid'); }
            else { $emp.val(''); if (code) { $(this).addClass('is-invalid'); } else { $(this).removeClass('is-invalid'); } }
            ensureTrailingBlankRow();
        });

        $tbody.on('input change', '.fa-e-date', function () { ensureTrailingBlankRow(); });

        $tbody.on('click', '.js-row-del', function () {
            $(this).closest('tr').remove();
            if (!$tbody.find('tr.fa-entry-row').length) { addRow(1); }
            recalcTotal();
        });

        $addBtn.on('click', function () { addRow(1); });

        $saveBtn.on('click', function () {
            $msg.hide();
            var rows = [];
            var clientErrors = [];

            $tbody.find('tr.fa-entry-row').each(function (idx) {
                var $r = $(this);
                var date = $.trim($r.find('.fa-e-date').val());
                var routeRaw = $.trim($r.find('.fa-e-routeinput').val());
                var vehicle = $.trim($r.find('.fa-e-vehicle').val());
                if (!date && !routeRaw && !vehicle) { return; }

                var lineNo = idx + 1;
                var no = parseRouteNo(routeRaw);
                if (!date) { clientErrors.push(lineNo + '行目：日付を入力してください。'); }
                if (!no || !routeByNo[no]) { clientErrors.push(lineNo + '行目：航路番号が正しくありません。'); }
                if (!vehicle) { clientErrors.push(lineNo + '行目：車番を入力してください。'); }
                else if (!vehicles[vehicle]) { clientErrors.push(lineNo + '行目：車番「' + vehicle + '」は車番マスタにありません。'); }

                rows.push({ use_date: date, route_no: no, vehicle_code: vehicle });
            });

            if (!rows.length) { showMsg($msg, '入力された行がありません。', true); return; }
            if (clientErrors.length) { showMsg($msg, '入力内容にエラーがあります。<br>' + clientErrors.map(esc).join('<br>'), true); return; }

            $saveBtn.prop('disabled', true);
            faPost('fa_record_save', 'record', { rows_json: JSON.stringify(rows) }).done(function (res) {
                if (res && res.success) {
                    var msg = (res.data.message || '登録しました。');
                    if (res.data.warnings && res.data.warnings.length) {
                        msg += '<br><span class="fa-warn">【重複警告】<br>' + res.data.warnings.map(esc).join('<br>') + '</span>';
                    }
                    showMsg($msg, msg, false);
                    $tbody.empty(); addRow(3); recalcTotal();
                    $('html, body').animate({ scrollTop: 0 }, 200);
                } else {
                    var em = (res && res.data && res.data.message) ? res.data.message : '登録に失敗しました。';
                    if (res && res.data && res.data.errors && res.data.errors.length) { em += '<br>' + res.data.errors.map(esc).join('<br>'); }
                    showMsg($msg, em, true);
                }
            }).fail(function () { showMsg($msg, '通信エラーが発生しました。', true); })
              .always(function () { $saveBtn.prop('disabled', false); });
        });

        addRow(3);
        recalcTotal();
    }

    // =====================================================
    //  月次サマリ
    // =====================================================

    function initSummary() {

        var $msg   = $('#fa-summary-msg');
        var $year  = $('#fa-summary-year');
        var $month = $('#fa-summary-month');
        var $show  = $('#fa-summary-show');
        var $tbody = $('#fa-summary-tbody');
        var $total = $('#fa-summary-total');
        var $count = $('#fa-summary-count');

        function load() {
            $tbody.html('<tr><td colspan="3">読み込み中…</td></tr>');
            faPost('fa_summary_get', 'summary', {
                year: $year.val(), month: $month.val()
            }).done(function (res) {
                if (!res || !res.success) { $tbody.html('<tr><td colspan="3">取得に失敗しました。</td></tr>'); return; }
                render(res.data);
            }).fail(function () { $tbody.html('<tr><td colspan="3">通信エラーが発生しました。</td></tr>'); });
        }

        function render(data) {
            var items = data.items || [];
            if (!items.length) {
                $tbody.html('<tr><td colspan="3">この月の実績はありません。</td></tr>');
                $total.text('0'); $count.text('0');
                return;
            }
            var rows = items.map(function (r) {
                return '' +
                    '<tr>' +
                        '<td>' + esc(r.employee_name) + '</td>' +
                        '<td class="fa-num">' + faFormatNumber(r.total) + '</td>' +
                        '<td class="fa-num">' + esc(r.count) + '</td>' +
                    '</tr>';
            }).join('');
            $tbody.html(rows);
            $total.text(faFormatNumber(data.grand_total || 0));
            $count.text(faFormatNumber(data.grand_count || 0));
        }

        $show.on('click', load);
    }

    // =====================================================
    //  実績一覧・編集
    // =====================================================

    function initRecords() {

        var $msg   = $('#fa-records-msg');
        var $year  = $('#fa-records-year');
        var $month = $('#fa-records-month');
        var $show  = $('#fa-records-show');
        var $tbody = $('#fa-records-tbody');
        var $total = $('#fa-records-total');

        // 編集フォーム
        var $editCard = $('#fa-records-editcard');
        var $eId    = $('#fa-records-edit-id');
        var $eDate  = $('#fa-records-edit-date');
        var $eRoute = $('#fa-records-edit-routeinput');
        var $eRname = $('#fa-records-edit-routename');
        var $eVeh   = $('#fa-records-edit-vehicle');
        var $eEmp   = $('#fa-records-edit-empname');
        var $eAllow = $('#fa-records-edit-allow');
        var $updateBtn = $('#fa-records-update');
        var $cancelBtn = $('#fa-records-edit-cancel');

        var routeByNo = buildRouteMap();
        var vehicles = FA.vehicles || {};

        $('#fa-route-datalist').html(routeDatalistHtml());
        $('#fa-vehicle-datalist').html(vehicleDatalistHtml());

        // 一覧
        function load() {
            $tbody.html('<tr><td colspan="7">読み込み中…</td></tr>');
            faPost('fa_record_get_list', 'record', {
                year: $year.val(), month: $month.val()
            }).done(function (res) {
                if (!res || !res.success) { $tbody.html('<tr><td colspan="7">取得に失敗しました。</td></tr>'); return; }
                render(res.data.items || []);
            }).fail(function () { $tbody.html('<tr><td colspan="7">通信エラーが発生しました。</td></tr>'); });
        }

        function render(items) {
            if (!items.length) {
                $tbody.html('<tr><td colspan="7">この月の実績はありません。</td></tr>');
                $total.text('0');
                return;
            }
            var sum = 0;
            var rows = items.map(function (r) {
                sum += parseInt(r.allowance, 10) || 0;
                return '' +
                    '<tr data-id="' + esc(r.id) + '"' +
                        ' data-date="' + esc(r.use_date) + '"' +
                        ' data-routeno="' + esc(r.route_no) + '"' +
                        ' data-vehicle="' + esc(r.vehicle_code) + '">' +
                        '<td>' + esc(r.use_date) + '</td>' +
                        '<td>' + esc(r.route_no) + '</td>' +
                        '<td>' + esc(r.route_name) + '</td>' +
                        '<td>' + esc(r.vehicle_code) + '</td>' +
                        '<td>' + esc(r.employee_name) + '</td>' +
                        '<td class="fa-num">' + faFormatNumber(r.allowance) + '</td>' +
                        '<td>' +
                            '<button type="button" class="button fa-btn-sm js-rec-edit">編集</button> ' +
                            '<button type="button" class="button fa-btn-sm js-rec-del">削除</button>' +
                        '</td>' +
                    '</tr>';
            }).join('');
            $tbody.html(rows);
            $total.text(faFormatNumber(sum));
        }

        // 編集フォームの航路・車番の自動反映
        function reflectRoute() {
            var no = parseRouteNo($eRoute.val());
            if (no && routeByNo[no]) {
                $eRname.val(routeByNo[no].name);
                $eAllow.val(faFormatNumber(routeByNo[no].allowance));
                $eRoute.removeClass('is-invalid');
            } else {
                $eRname.val(''); $eAllow.val('');
                if ($eRoute.val()) { $eRoute.addClass('is-invalid'); } else { $eRoute.removeClass('is-invalid'); }
            }
        }
        function reflectVehicle() {
            var code = $.trim($eVeh.val());
            if (code && vehicles[code]) { $eEmp.val(vehicles[code].employee_name); $eVeh.removeClass('is-invalid'); }
            else { $eEmp.val(''); if (code) { $eVeh.addClass('is-invalid'); } else { $eVeh.removeClass('is-invalid'); } }
        }

        $eRoute.on('input change', reflectRoute);
        $eVeh.on('input change', reflectVehicle);

        function openEdit(d) {
            $eId.val(d.id);
            $eDate.val(d.date);
            // 航路番号欄には「番号 航路名」を復元
            var no = String(d.routeno);
            var rname = routeByNo[no] ? routeByNo[no].name : '';
            $eRoute.val(rname ? (no + ' ' + rname) : no);
            $eVeh.val(d.vehicle);
            reflectRoute();
            reflectVehicle();
            $editCard.show();
            $('html, body').animate({ scrollTop: 0 }, 200);
        }

        function closeEdit() {
            $eId.val('0'); $eDate.val(''); $eRoute.val(''); $eRname.val('');
            $eVeh.val(''); $eEmp.val(''); $eAllow.val('');
            $eRoute.removeClass('is-invalid'); $eVeh.removeClass('is-invalid');
            $editCard.hide();
        }

        function update() {
            var date = $.trim($eDate.val());
            var no = parseRouteNo($eRoute.val());
            var vehicle = $.trim($eVeh.val());

            if (!date) { showMsg($msg, '日付を入力してください。', true); return; }
            if (!no || !routeByNo[no]) { showMsg($msg, '航路番号が正しくありません。', true); return; }
            if (!vehicle || !vehicles[vehicle]) { showMsg($msg, '車番が正しくありません。', true); return; }

            $updateBtn.prop('disabled', true);
            faPost('fa_record_update', 'record', {
                id: $eId.val(), use_date: date, route_no: no, vehicle_code: vehicle
            }).done(function (res) {
                if (res && res.success) {
                    var m = res.data.message || '更新しました。';
                    if (res.data.warning) { m += '<br><span class="fa-warn">' + esc(res.data.warning) + '</span>'; }
                    showMsg($msg, m, false);
                    closeEdit();
                    load();
                } else {
                    showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '更新に失敗しました。', true);
                }
            }).fail(function () { showMsg($msg, '通信エラーが発生しました。', true); })
              .always(function () { $updateBtn.prop('disabled', false); });
        }

        function del(id) {
            if (!window.confirm('この実績を削除します。よろしいですか？')) { return; }
            faPost('fa_record_delete', 'record', { id: id }).done(function (res) {
                if (res && res.success) {
                    showMsg($msg, res.data.message || '削除しました。', false);
                    if ($eId.val() === String(id)) { closeEdit(); }
                    load();
                } else {
                    showMsg($msg, (res && res.data && res.data.message) ? res.data.message : '削除に失敗しました。', true);
                }
            }).fail(function () { showMsg($msg, '通信エラーが発生しました。', true); });
        }

        $show.on('click', load);
        $updateBtn.on('click', update);
        $cancelBtn.on('click', closeEdit);

        $tbody.on('click', '.js-rec-edit', function () {
            var $tr = $(this).closest('tr');
            openEdit({ id: $tr.data('id'), date: $tr.data('date'), routeno: $tr.data('routeno'), vehicle: $tr.data('vehicle') });
        });
        $tbody.on('click', '.js-rec-del', function () {
            del($(this).closest('tr').data('id'));
        });
    }

    // =====================================================
    //  画面分岐
    // =====================================================

    $(function () {
        switch (FA.page) {
            case 'ferry-allowance':            initEntry();    break;
            case 'ferry-allowance-summary':    initSummary();  break;
            case 'ferry-allowance-records':    initRecords();  break;
            case 'ferry-allowance-routes':     initRoutes();   break;
            case 'ferry-allowance-vehicles':   initVehicles(); break;
            default: break;
        }
    });

})(jQuery);
