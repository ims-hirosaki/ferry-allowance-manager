# 06 バックエンド・API仕様（エンジニア向け）

## 1. クラス一覧

| クラス | ファイル | 責務 |
|---|---|---|
| `FA_DB_Install` | `includes/class-db-install.php` | テーブル作成・シード・削除、テーブル名ヘルパー |
| `FA_Employee_Bridge` | `includes/class-employee-bridge.php` | employee-manager の公開関数ラッパー |
| `FA_Vehicle_Bridge` | `includes/class-vehicle-bridge.php` | vehicle-manager の公開関数ラッパー、乗車名マップ取得 |
| `FA_Boarding_Name` | `includes/class-boarding-name.php` | 乗車名マスタの CRUD・AJAX |
| `FA_Company` | `includes/class-company.php` | フェリー会社マスタの CRUD・AJAX |
| `FA_Route` | `includes/class-route.php` | 航路マスタの CRUD・AJAX |
| `FA_Record` | `includes/class-record.php` | 実績の一括保存・取得・更新・削除・AJAX |
| `FA_Summary` | `includes/class-summary.php` | 月次サマリ集計・AJAX |
| `FA_Admin_Menu` | `admin/class-admin-menu.php` | メニュー・アセット・AJAXフック・ビュー描画 |

クラスはすべて静的メソッド中心（`FA_Admin_Menu` のみインスタンス化）。読み込みは `ferry-allowance-manager.php` の `require_once`（ブリッジ→乗車名→会社→航路→実績→メニュー→サマリの順）。

## 2. AJAX アクション一覧

すべて `admin-ajax.php`（ログインユーザーのみ `wp_ajax_*`）。共通で **nonce 検証**（`check_ajax_referer(NONCE_ACTION, 'nonce')`）＋**権限チェック**を行い、失敗時は `{success:false, data:{message:'権限がありません。'}}`（nonce 不正は WordPress 既定の 403 相当）。

| アクション | ハンドラ | nonce アクション（`faData.nonce.*`） | 必要権限 | 概要 |
|---|---|---|---|---|
| `fa_record_save` | `FA_Record::ajax_save` | `fa_record_nonce`（`record`） | `edit_custom_plugins` | 実績の一括登録 |
| `fa_record_get_list` | `FA_Record::ajax_get_list` | 同上 | `access_custom_plugins` | 実績一覧＋絞り込み候補 |
| `fa_record_update` | `FA_Record::ajax_update` | 同上 | `edit_custom_plugins` | 実績1件更新 |
| `fa_record_delete` | `FA_Record::ajax_delete` | 同上 | `edit_custom_plugins` | 実績1件削除 |
| `fa_summary_get` | `FA_Summary::ajax_get` | `fa_summary_nonce`（`summary`） | `access_custom_plugins` | 月次サマリ |
| `fa_route_get_list` | `FA_Route::ajax_get_list` | `fa_route_nonce`（`route`） | `access_custom_plugins` | 航路一覧 |
| `fa_route_save` | `FA_Route::ajax_save` | 同上 | `edit_custom_plugins` | 航路の登録・更新 |
| `fa_route_toggle` | `FA_Route::ajax_toggle` | 同上 | `edit_custom_plugins` | 航路の有効/無効切替 |
| `fa_route_delete` | `FA_Route::ajax_delete` | 同上 | `edit_custom_plugins` | 航路の削除 |
| `fa_company_get_list` | `FA_Company::ajax_get_list` | `fa_company_nonce`（`company`） | `access_custom_plugins` | 会社一覧 |
| `fa_company_save` | `FA_Company::ajax_save` | 同上 | `edit_custom_plugins` | 会社の登録・更新 |
| `fa_company_toggle` | `FA_Company::ajax_toggle` | 同上 | `edit_custom_plugins` | 会社の有効/無効切替 |
| `fa_company_delete` | `FA_Company::ajax_delete` | 同上 | `edit_custom_plugins` | 会社の削除 |
| `fa_boarding_get_list` | `FA_Boarding_Name::ajax_get_list` | `fa_boarding_name_nonce`（`boarding`） | **`manage_options`** | 乗車名マスタ一覧 |
| `fa_boarding_save` | `FA_Boarding_Name::ajax_save` | 同上 | `manage_options` | 乗車名マスタの登録・更新 |
| `fa_boarding_toggle` | `FA_Boarding_Name::ajax_toggle` | 同上 | `manage_options` | 有効/無効切替 |
| `fa_boarding_delete` | `FA_Boarding_Name::ajax_delete` | 同上 | `manage_options` | 削除 |

応答の基本形: 成功 `wp_send_json_success(data)` → `{success:true, data:{…}}`／失敗 `wp_send_json_error(data)` → `{success:false, data:{message:'…', …}}`。

> 補足: `*_toggle` / `*_delete` の AJAX 成功応答は `wp_send_json_success( self::toggle_active(...) )` のため、内部結果（`success:false` など）が `data` の中に入る形になる場合があります（例: 対象が見つからないとき `{success:true, data:{success:false, message:'対象が見つかりません。'}}`）。フロントは `res.data.message` を表示します。

## 3. リクエスト／レスポンス詳細

### 3-1. `fa_record_save`
- 入力: `rows_json`（JSON文字列）。各要素 `{use_date, route_no, vehicle_code, employee_code, note?}`。
- JSONが配列でなければ「送信データの形式が不正です。」
- 成功: `{success:true, inserted:N, warnings:[…], message:'N件を登録しました。'}`
- 失敗: `{success:false, message:'入力内容にエラーがあります。修正してください。', errors:['1行目：…', …]}`（行番号は送信配列の位置 +1）
- 処理: 検証（`04` 3-2）→ 全件OKなら1行ずつ重複確認→INSERT。`created_at`/`updated_at` は `current_time('mysql')`。**トランザクションは使っていない**（全件検証後に連続INSERTする方式）。

### 3-2. `fa_record_get_list`
- 入力: `year`, `month`, `date_from`, `date_to`, `employee_code`, `vehicle_code`
- 期間: `date_from`/`date_to` が有効な `YYYY-MM-DD` なら年月より優先（片側のみも可）。年月は `year>0 && month>0` のとき月初〜月末。**どちらも無い場合は期間条件なし（全件）**。
- 応答: `{items:[実績…], filters:{employees:[{code,name}], vehicles:[車番…]}}`
- 実績は `use_date ASC, id ASC`。氏名は `resolve_name(社員コード, 保存氏名, 在籍マップ)`、運輸支局は vehicle-manager の最新（無ければ保存値）で上書きして返す。
- `filters` は**絞り込み条件（乗車名・車番）を含めず、期間だけ**で算出（選択中の条件に関わらず候補が出る）。

### 3-3. `fa_record_update`
- 入力: `id`, `use_date`, `route_no`, `vehicle_code`, `employee_code`, `note`（`note` を送らないと空文字で上書き）
- 検証は登録時と同じ（単一メッセージ）。対象IDが無ければ「対象の実績が見つかりません。」
- 成功: `{success:true, message:'実績を更新しました。', warning?:'…'}`
- 更新される列: employee_code, employee_name, vehicle_code, transport_bureau, use_date, route_id, route_no, route_name, company_id, company_name, allowance, note, updated_at（スナップショット再取得）

### 3-4. `fa_record_delete`
- 入力: `id`（0以下は「対象が指定されていません。」）。物理削除。成功「実績を削除しました。」（存在しないIDでも成功扱い）。

### 3-5. `fa_summary_get`
- 入力: `year`, `month`。応答は `04` 4章参照。

### 3-6. 航路 `fa_route_*`
- `get_list` 入力: `include_inactive`（`'1'`で無効含む）、`keyword`（航路名 or 番号の部分一致。番号は `CAST(route_no AS CHAR) LIKE`）。応答: `{items:[行+company_name]}`。並び: `sort_order ASC, route_no ASC`。
- `save` 入力: `id`(0=新規), `route_no`, `route_name`, `company_id`, `allowance`, `sort_order`, `is_active`。
  検証順: 航路番号≦0→「航路番号は1以上で入力してください。」／名称空→「航路名を入力してください。」／手当<0→「フェリー手当は0以上で入力してください。」／会社ID>0かつ存在しない→「指定されたフェリー会社が見つかりません。」／番号重複→「この航路番号は既に登録されています。」
  成功: 「航路マスタを登録しました。」／「航路マスタを更新しました。」（`id` を返す）。`sort_order` 未指定時は `route_no`。名称は `sanitize_text_field`。`company_id`≦0 は NULL 保存。
- `toggle`: `is_active` を反転。成功「状態を変更しました。」、無ければ「対象が見つかりません。」
- `delete`: 物理削除「航路マスタを削除しました。」

### 3-7. フェリー会社 `fa_company_*`
- `get_list`: `include_inactive`, `keyword`（会社名部分一致）。並び: `sort_order ASC, name ASC`。
- `save`: `id`, `name`, `sort_order`, `is_active`。名称空→「会社名を入力してください。」／重複→「この会社名は既に登録されています。」。成功「フェリー会社マスタを登録/更新しました。」
- `toggle`: 「状態を変更しました。」、`delete`: 「フェリー会社マスタを削除しました。」（航路・実績の参照は更新しない）。

### 3-8. 乗車名マスタ `fa_boarding_*`
- `get_list`: `include_inactive`, `keyword`（車番 or 社員コード部分一致）。並び: `CAST(vehicle_code AS UNSIGNED), vehicle_code`。各行に `employee_name`（最新氏名、無ければ社員コード）を付与。
- `save`: `id`, `vehicle_code`, `employee_code`, `is_active`。検証:
  - 車番が空／vehicle-manager に無い→「車両管理に登録されている車番を選択してください。」
  - 社員コードが空／在籍でない（`exists_active`）→「在籍中の社員を選択してください。」
  - 同じ車番が他IDに存在→「この車番は既に乗車名マスタへ登録されています。」
  成功: 「乗車名マスタへ登録しました。」／「乗車名マスタを更新しました。」
- `toggle`: 有効→「無効にしました。」／無効→「有効にしました。」、無ければ「対象が見つかりません。」
- `delete`: 「乗車名マスタから削除しました。」

## 4. 主な内部メソッド（FA_Record）

| メソッド | 役割 |
|---|---|
| `save_bulk($rows)` | 一括登録（検証→登録→警告収集） |
| `get_records($args)` | 期間・社員・車番で取得し、氏名・運輸支局をライブ解決 |
| `period_where($args)` | 期間条件の組立（日付範囲 > 年月） |
| `get_filter_options($args)` | 期間内に実績のある乗車名・車番の候補 |
| `get_by_id($id)` | 1件取得 |
| `update($id,$data)` | 1件更新（スナップショット再取得・重複警告） |
| `delete($id)` | 物理削除 |
| `valid_date($s)` | `Y-m-d` の厳密検証（`DateTime::createFromFormat` で再フォーマット一致） |

## 5. ブリッジ（FA_Employee_Bridge / FA_Vehicle_Bridge）

| メソッド | 呼び出す外部関数 | 外部関数が無いときの戻り値 |
|---|---|---|
| `FA_Employee_Bridge::is_available` | `emp_get_active_employees` の存在 | false |
| `get_active_employees($args)` | `emp_get_active_employees($args)` | `[]` |
| `get_by_code($code)` | `emp_get_employee_by_code($code)` | `null` |
| `get_by_id($id)` | `emp_get_employee_by_id($id)` | `null` |
| `get_code_name_map()` | 上記一覧から `社員コード=>氏名` | `[]` |
| `resolve_name($code,$fallback,$map)` | マップ→`get_by_code`→`$fallback` の順 | `$fallback` |
| `exists_active($code)` | `get_by_code`＋`is_active` プロパティ（無ければ存在=OK） | false |
| `FA_Vehicle_Bridge::is_available` | `vm_get_vehicle_numbers` の存在 | false |
| `get_vehicle_numbers()` | `vm_get_vehicle_numbers()` | `[]` |
| `exists($code)` | `vm_vehicle_exists($code)` | **false**（＝車番の登録が一切できない） |
| `get_transport_bureau_map()` | `vm_get_transport_bureau_map()` | `[]` |
| `get_transport_bureau($code)` | `vm_get_transport_bureau($code)` | `''` |
| `get_employee_map()` | `FA_Boarding_Name::get_map_for_js()` | `[]` |

連携先の社員オブジェクトで使うプロパティ: `employee_code`, `name`, `crew_code`, `is_active`。

> `FA_Record` が社員の存在確認に使う `get_by_code` は、在籍かどうかを判定しません（`exists_active` は乗車名マスタ保存時のみ使用）。退職者でも `emp_get_employee_by_code` が返せば登録できる可能性があります。【推測・要確認：emp 側の仕様】

## 6. セキュリティ実装の要点

| 観点 | 実装 |
|---|---|
| CSRF | すべての AJAX で nonce 検証 |
| 認可 | `access_custom_plugins`（参照）／`edit_custom_plugins`（更新系）／`manage_options`（乗車名マスタ）。メニュー・ビューにも権限ガード |
| SQL インジェクション | `$wpdb->prepare`、`esc_like`。テーブル名は内部生成 |
| XSS | PHP側 `esc_html`/`esc_attr`/`esc_url`、JS側 `esc()` |
| 入力サニタイズ | `sanitize_text_field`（会社名・航路名・備考・乗車名マスタの車番/社員コード等）、整数は `(int)` キャスト |
| CSV | 数式インジェクション対策（先頭 `'` 付与） |
| 直接アクセス | 各PHPの先頭に `ABSPATH` ガード、`uninstall.php` は `WP_UNINSTALL_PLUGIN` ガード |

## 7. 拡張・改修時の注意（コードから読み取れる設計意図）

- JS は「差分編集で破損しやすいため、常に完全置換で更新する」方針がファイル先頭コメントにある（`admin.js`）。
- 航路・車番・社員は**ブリッジ／マスタ経由**でのみ参照し、他プラグインのテーブルを直接クエリしない。
- 実績テーブルへ列を追加する場合は、`create_tables()` の `CREATE TABLE` を更新し、`FA_VERSION` を上げる（`fa_db_version` との差で dbDelta が走る）。
- 実績の `INSERT`/`UPDATE` のフォーマット配列（`%s/%d`）は列数と順序に厳密に対応させる必要がある（`save_bulk` と `update` の2箇所）。
