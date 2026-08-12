<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * フェリー会社マスタ管理画面
 * 航路マスタの紐づけ先となる会社名の一覧を管理する。
 * データ取得・保存は admin.js から AJAX（fa_company_*）で行う。
 */
?>
<div class="wrap fa-wrap fa-companies">
    <h1>フェリー会社マスタ</h1>

    <div class="fa-msg" id="fa-company-msg" style="display:none;"></div>

    <div class="fa-companies__layout">

        <!-- 左：登録・編集フォーム -->
        <div class="fa-companies__form">
            <div class="fa-card">
                <h2 class="fa-card__title" id="fa-company-form-title">フェリー会社を登録</h2>

                <input type="hidden" id="fa-company-id" value="0">

                <div class="fa-field">
                    <label for="fa-company-name">会社名<span class="fa-req">*</span></label><br>
                    <input type="text" id="fa-company-name" class="regular-text" maxlength="100" placeholder="例：商船三井フェリー">
                </div>

                <div class="fa-field">
                    <label for="fa-company-sort">表示順</label><br>
                    <input type="number" id="fa-company-sort" class="small-text" step="1" placeholder="0">
                </div>

                <div class="fa-field">
                    <label><input type="checkbox" id="fa-company-active" checked> 有効</label>
                </div>

                <p>
                    <button type="button" class="button button-primary" id="fa-company-save">登録</button>
                    <button type="button" class="button" id="fa-company-reset" style="display:none;">キャンセル</button>
                </p>
            </div>
        </div>

        <!-- 右：一覧 -->
        <div class="fa-companies__list">
            <div class="fa-card">
                <div class="fa-companies__toolbar">
                    <input type="search" id="fa-company-search" class="regular-text" placeholder="会社名で検索">
                    <label class="fa-companies__inactive">
                        <input type="checkbox" id="fa-company-include-inactive"> 無効も表示
                    </label>
                </div>

                <table class="fa-table" id="fa-company-table">
                    <thead>
                        <tr>
                            <th>会社名</th>
                            <th style="width:6em;">表示順</th>
                            <th style="width:5em;">状態</th>
                            <th style="width:13em;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="fa-company-tbody">
                        <tr><td colspan="4">読み込み中…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
