# Phase 2 — マスタ（顧客 / スタッフ / サービス / ブース）

設計正本：`docs/PLAN.md`（rev.5）。baseline commit：`00dc8b8`（Phase 0+1）。本ファイルは Phase 2 のタスク分割と進行ログ。

## Phase 2 の範囲

`docs/PLAN.md` Phase 2 =「customers / staff（+ shifts）/ services / service_staff / booths + 管理 CRUD + 顧客プロフィール編集」。

- `services` + `service_staff`（メニュー/コース master、施術可能スタッフ）
- `booths`（物理リソース master）
- `staff` master 完成（Phase 1 は作成のみ → 一覧/編集/無効化）+ `staff_shifts`（勤務枠）
- `customers` の管理画面（一覧/詳細/編集）+ 顧客セルフ・プロフィール編集（マイページ）
- すべての admin CRUD 画面（Vuetify）

## Phase 2 で実装しないもの（厳守）

reservations 業務 / `reservation_resource_slots` / 空き枠算出 / 予約台帳 / 回数券 / Membership /
Stripe 実決済・Payment Element・Stripe Live / Peak Manager 実 API / SALON BOARD 実 API / Reporting /
WordPress 変更 / 本番 DB / `ark-system-proposal` 変更 / Phase 3 以降の先行実装。
`ExternalReservationGateway` は `null` のまま（変更しない）。

## 共通ルール

- 実装は Codex（毎回 `-m gpt-5.6-sol` 明示、CLI 自動アップデート禁止、最初に `AGENTS.md` / `docs/PLAN.md` / `docs/tasks/phase-02.md` を通読）。
- Claude Code：タスク分割 / 指示 / Docker・Sail・composer・npm / `git diff HEAD` レビュー / build・test / セキュリティ・設計整合レビュー。アプリコード修正は原則 Codex へ差し戻す。
- `git add` / `commit` / `push` 禁止（baseline `00dc8b8` の上に未コミットで積む）。本番接続禁止。Stripe Live 禁止。グローバルインストール禁止。
- **DB 更新は Action / Service 経由**（Controller / Vue から直接 DB 更新しない）。一覧取得の read クエリは Service or Query object に寄せる。
- 保護（変更しない）：`docs/PLAN.md`。他 docs は事実整合の追記のみ。

## パーミッション追加（rev.5 §2 から導出・確定）

`docs/PLAN.md §2`：admin=全権+設定+スタッフ管理 / manager=売上・返金・回数券・契約操作 / staff=自分の予定・来店マーク・顧客の基本参照。

| permission | 付与ロール | 用途 |
|---|---|---|
| `services.manage` | **admin のみ**（設定） | サービス/コース CRUD |
| `booths.manage` | **admin のみ**（設定） | ブース CRUD |
| `shifts.manage` | **admin のみ**（全スタッフの勤務枠。スタッフ本人の自枠編集は Phase 2 では作らない＝課題） | staff_shifts CRUD |
| `customers.view` | **admin / manager / staff**（staff は「顧客の基本参照」） | 顧客一覧/詳細の閲覧 |
| `customers.manage` | **admin のみ** — rev.5 §2 の manager remit に編集権が無いため是正 | 顧客レコードの編集（管理者側） |

`RolePermissionSeeder` を更新（冪等）。既存 8 permission は不変。`Gate::before` 全権バイパスは引き続き作らない。
顧客セルフ・プロフィール編集は permission 不要、Policy で「自分の customer レコードのみ」。

---

## タスク一覧

### Task 2-1 — services + service_staff
- migration `create_services_table`：`id` / `name` varchar(100) / `duration_min` unsignedSmallInteger / `price` unsignedInteger / `category` varchar(50) null / `is_online_bookable` bool default true / `requires_staff` bool default true / `color` varchar(7) default '#607d8b' / `is_active` bool default true / `sort_order` smallint default 0 / timestamps。index `(is_active, sort_order)`。
- migration `create_service_staff_table`：`service_id` FK cascade / `staff_id` FK(`staff.user_id`) cascade / 複合 PK `(service_id, staff_id)`。
- `app/Models/Service.php`（`staff()` belongsToMany via `service_staff`、`scopeActive`）。
- Action：`App\Actions\Service\{CreateService,UpdateService,SetServiceStaff,ToggleServiceActive}`（DB 更新はここだけ）。
- `App\Http\Controllers\Admin\ServiceController`（index/create/store/edit/update + activate/deactivate。`can:services.manage`。`store`/`update` は Action 呼び出しのみ）。
- FormRequest でバリデーション。一覧の read は `App\Queries\ServiceListQuery` 等に寄せる（Controller から生 `DB::`/複雑クエリを書かない）。
- Inertia：`Admin/Services/{Index,Create,Edit}.vue`（`AdminLayout`、Vuetify データテーブル/フォーム、施術可能スタッフは複数選択）。
- `RolePermissionSeeder` に `services.manage`（admin）。
- テスト（`tests/Feature/Admin/Services/`）：`can:services.manage` なし → 403 ／ admin → CRUD 一巡（作成・更新・スタッフ紐付け・無効化）／ `requires_staff` バリデーション ／ 一覧が `is_active` + `sort_order` 順。

### Task 2-2 — booths
- migration `create_booths_table`：`id` / `name` varchar(50) / `sort_order` smallint default 0 / `is_active` bool default true / timestamps。
- `app/Models/Booth.php`（`scopeActive`）。
- Action：`App\Actions\Booth\{CreateBooth,UpdateBooth,ToggleBoothActive}`。
- `Admin\BoothController`（index/create/store/edit/update + activate/deactivate。`can:booths.manage`）。FormRequest。
- Inertia：`Admin/Booths/{Index,Create,Edit}.vue`。
- `RolePermissionSeeder` に `booths.manage`（admin）。
- テスト：403（権限なし）／ admin CRUD 一巡 ／ 無効化しても行は残る（論理: `is_active=false`）。

### Task 2-3 — staff master 完成 + staff_shifts
- **staff**：Phase 1 の `StaffController` に `index`（一覧・既にあるなら整備）/ `edit` / `update`（表示名・color・is_bookable・sort_order・ロール変更）/ `deactivate`（`is_bookable=false` にする論理無効。User は消さない）を追加。ロール変更・無効化は `can:staff.manage` + `password.confirm` + `App\Actions\Staff\{UpdateStaff,DeactivateStaff}` 経由。監査ログ（`AuditLogger`）。
- migration `create_staff_shifts_table`：`id` / `staff_id` FK(`staff.user_id`) cascade / `work_date` date / `start_at` time / `end_at` time / timestamps。index `(staff_id, work_date)`。同一 staff・同一 `work_date` の重複や `start_at >= end_at` はバリデーション（DB ではなくアプリ層で十分）。
- `app/Models/StaffShift.php`。
- Action：`App\Actions\StaffShift\{CreateShift,UpdateShift,DeleteShift}`（`can:shifts.manage`）。
- `Admin\StaffShiftController`（index：staff × 日付で一覧、store/update/destroy）。FormRequest。
- Inertia：`Admin/Staff/Edit.vue`、`Admin/StaffShifts/Index.vue`（週表示は簡易でよい。予約台帳は作らない）。
- `RolePermissionSeeder` に `shifts.manage`（admin）。
- テスト：staff 編集・ロール変更（`password.confirm` 要求、非 `staff.manage` は 403、監査 1 行）／ deactivate で `is_bookable=false`・User 残存 ／ shift CRUD ／ `start_at >= end_at` で 422 ／ 同日重複で 422。

### Task 2-4 — 顧客管理（admin）+ 顧客セルフ・プロフィール編集
- **admin 側**：
  - `Admin\CustomerController`（index：氏名・カナ・電話（下記）・メール で検索、詳細、edit/update（`kana` / `gender` / `note` / `phone` / `birthday`。`email` `name` は `users` 側なので慎重に：Phase 2 では `name`/`kana`/`phone`/`birthday`/`gender`/`note` の編集に留め、email 変更は対象外＝課題）。`can:customers.view`（一覧/詳細）、`can:customers.manage`（編集）。
  - **電話検索**：`PiiHasher::phoneHmac($query)` で `customers.phone_hmac` 等価検索（平文 LIKE をしない）。部分一致は不可＝完全一致のみ（UX 上の注意を画面に表示）。
  - 編集は `App\Actions\Customer\UpdateCustomerProfile` 経由。監査ログ。
  - Inertia：`Admin/Customers/{Index,Show,Edit}.vue`（`AdminLayout`）。詳細は基本情報のみ（契約/回数券/予約履歴は Phase 4-7 で追加）。
- **顧客セルフ側**（マイページ）：
  - `Customer\ProfileController`（show/edit/update）。認証済み `customer` が**自分の** `customer` レコードのみ（`CustomerPolicy::update` = `$user->id === $customer->user_id`）。
  - 編集項目：`kana` / `phone` / `birthday` / `gender`（`name` は Fortify の updateProfileInformation に委譲可、`email` は verified 再送を伴うため Phase 2 対象外＝課題）。
  - `App\Actions\Customer\UpdateCustomerProfile` を admin と共用（actor で監査の主体を分ける）。
  - Inertia：`Customer/Profile/{Show,Edit}.vue`（`CustomerLayout`、スマホファースト）。
- `RolePermissionSeeder` に `customers.view`（admin/manager/staff）・`customers.manage`（admin のみ）。
- `CustomerPolicy`（`viewAny`/`view` は `customers.view`、`update` は `customers.manage` または customer 本人の自レコードのみ）。
- テスト：
  - staff（`customers.view` あり・`customers.manage` なし）→ 一覧 200・編集 403。
  - manager → 一覧/詳細 200・編集/更新 403。
  - 電話 `phone_hmac` 検索が正規化して一致（`090-1234-5678` と `09012345678` が同じ結果）。
  - 顧客 A が顧客 B のプロフィール編集 → 403。
  - 顧客が自分のプロフィール編集 → 200・`phone_hmac` 再計算。

### Task 2-5 — Phase 2 総合テスト / CI / セキュリティレビュー
- `migrate:fresh --seed` クリーン（新 migration + `RolePermissionSeeder` の新 permission）。
- 全テスト green / `npm run build` 型エラー 0。
- CI（`.github/workflows/ci.yml`）：新 migration / seeder / test が走ることを確認。必要なら最小修正。
- 不足テスト補完：各マスタの権限境界、Action 経由の担保（Controller に `DB::` 直呼びが無いことを静的に確認）、監査ログ、電話 HMAC 検索、顧客セルフ編集の own-record 制約、CSP/ヘッダ非退行。
- `localhost`：`/admin/services` `/admin/booths` `/admin/staff` `/admin/customers` `/mypage`(顧客) の応答（未認証 302 / 権限別）。
- 本ログに最終結果。デモ用マスタ seeder（サービス2件・ブース2件など）を作る場合は `local` 限定・`DatabaseSeeder` 既定実行に含めない。

---

## 実行ログ

（Task ごとに：日時 / Codex 変更ファイル / Claude Code 検証 / 是正 / 結果）

---

### 2026-09-08 — Task 2-1（services + service_staff）: ✅ 完了（無修正）
- migration：`create_services_table`（`name`/`duration_min`/`price`/`category`/`is_online_bookable`/`requires_staff`/`color`/`is_active`/`sort_order`、index `(is_active, sort_order)`）／`create_service_staff_table`（`service_id`×`staff_id(staff.user_id)` 複合 PK・cascade・timestamps なし）。
- `app/Models/Service.php`（`staff()` belongsToMany via `service_staff`、`scopeActive`）。
- Action（DB 更新の唯一の入口・各々 `AuditLogger` 記録）：`Service\{CreateService,UpdateService,SetServiceStaff,ToggleServiceActive}`。
- `ServiceListQuery` / `ServiceStaffOptionsQuery`（read を Controller から分離。**Controller に `DB::` 直呼びなし**を確認）。
- `Admin\ServiceController`（resource except show + active トグル、全 action `can:services.manage`）。`StoreServiceRequest`/`UpdateServiceRequest`（`requires_staff=true` で `staff_ids` 1 件以上、`staff_ids.*` exists:staff,user_id）。
- `RolePermissionSeeder`：`services.manage` を **admin のみ**に付与（tinker 確認：admin=YES / manager=no / staff=no）。冪等。
- Inertia：`Admin/Services/{Index,Create,Edit}.vue`（Vuetify データテーブル/フォーム、施術スタッフ複数選択）。`AdminLayout` に「サービス」ナビ（`auth.can.servicesManage`）、`inertia.d.ts` 更新。
- **検証**：`migrate` 2 テーブル clean ／ `seed` 冪等 ／ `npm run build` 型エラー0・警告なし ／ `artisan test` **80 passed / 399 assertions**（+8）。秘密情報・本番接続なし。baseline `00dc8b8` は不変（コミットせず）。

### 2026-09-08 — Task 2-2（booths）: ✅ 完了（無修正）
- `create_booths_table`（`name` varchar(50) / `sort_order` / `is_active`、index `(is_active, sort_order)`）。
- `Booth` model（`scopeActive`）／`Actions\Booth\{CreateBooth,UpdateBooth,ToggleBoothActive}`（監査つき）／`BoothListQuery`／`Admin\BoothController`（`can:booths.manage`・**`DB::` 直呼びなし**）／`{Store,Update}BoothRequest`。
- Inertia `Admin/Booths/{Index,Create,Edit}.vue` + ナビ（`auth.can.boothsManage`）+ `inertia.d.ts`。
- `RolePermissionSeeder` に `booths.manage`（admin のみ・確認：admin=YES / manager,staff=no）。authz テスト更新。
- 無効化は `is_active=false`（物理削除なし）。
- **検証**：`migrate` clean ／ `seed` 冪等 ／ `npm run build` 型エラー0 ／ `artisan test` **89 passed / 477 assertions**（+9）。秘密情報・本番接続なし。baseline 不変。

### 2026-09-08 — Task 2-3（staff master 完成 + staff_shifts）: ✅ 完了（無修正）
- **staff**：`StaffController` に index（`StaffListQuery`）/ edit / update / deactivate 追加（`can:staff.manage` + `password.confirm`、`Actions\Staff\{UpdateStaff,DeactivateStaff}` 経由、監査 `staff.updated`/`staff.role_changed`/`staff.deactivated`）。
  **ロックアウト防止**：`UpdateStaff` は transaction 内で admin ロール行を `lockForUpdate` し、降格後に admin が 0 になるなら `ValidationException`（422「最後の管理者を降格することはできません」）。
  `deactivate` は `is_bookable=false` のみ（User・role 残存）。
- **staff_shifts**：`create_staff_shifts_table`（`staff_id(staff.user_id)` cascade / `work_date` / `start_at` / `end_at`、index `(staff_id, work_date)`）／`StaffShift` model ／`Actions\StaffShift\{CreateShift,UpdateShift,DeleteShift}`（監査）。
  バリデーション：`start_at < end_at`（FormRequest `after`）+ **同一 staff・同一日で時間帯が重なるシフトを拒否**（分割シフトは許可、Update は自身除外）。
- `Admin\StaffShiftController`（`can:shifts.manage`、`?staff_id=&from=&to=` で `StaffShiftListQuery`、既定は今週）。**`DB::` 直呼びなし**。
- `RolePermissionSeeder`：`shifts.manage`（admin のみ・確認 admin=YES / manager,staff=no）。authz テスト更新。
- Inertia：`Admin/Staff/{Index,Edit}.vue`、`Admin/StaffShifts/Index.vue`（簡易表・予約台帳ではない）。ナビ + `auth.can.shiftsManage` + `inertia.d.ts`。
- **検証**：`migrate` clean ／ `seed` 冪等 ／ `npm run build` 型エラー0 ／ `artisan test` **104 passed / 579 assertions**（+15）。秘密情報・本番接続なし。baseline 不変。

### 2026-09-08 — Task 2-4（顧客管理 admin + 顧客セルフ・プロフィール編集）: ✅ 完了（無修正）
- 共有 `App\Actions\Customer\UpdateCustomerProfile`（`DB::transaction` で `users.name` + `customers.{kana,phone,birthday,gender,note}`。`phone` 変更で `phone_hmac` 自動再計算。**email 不変**。監査 `customer.profile_updated`＝要約に PII 値・email を載せない）。
- **admin**：`Admin\CustomerController`（`CustomerPolicy` で `viewAny/view`=`customers.view`、`update`=`customers.manage`、いずれも「本人なら可」分岐あり）。`CustomerListQuery`（read。**電話検索は `PiiHasher::normalizePhone()`→10-11桁なら `phone_hmac` 等価一致のみ**、それ以外は name/kana/email の LIKE。平文 phone LIKE なし）。`Admin/Customers/{Index,Show,Edit}.vue`（詳細は基本情報のみ）。
- **顧客セルフ**：`Customer\ProfileController`（`/mypage/profile` show/edit/update、`authorize('view'|'update', $request->user()->customer)`＝own-record のみ、customer レコード無し=403）。`Customer/Profile/{Show,Edit}.vue`（`CustomerLayout` スマホ）。
- `app/Http/Controllers/Controller.php` に `AuthorizesRequests` trait 追加（`$this->authorize()` 用）。`AuthServiceProvider` に `CustomerPolicy` 登録。
- `RolePermissionSeeder`：`customers.view`（admin/manager/staff）・`customers.manage`（admin のみ）。確認：admin=Y/Y、manager=Y/n、staff=Y/n、customer=n/n。authz テスト更新。
- Inertia 共有 `auth.can.customersView/customersManage` + `inertia.d.ts` + `AdminLayout` 顧客ナビ + `CustomerLayout` プロフィール導線。
- テスト：`CustomerManagementTest`（staff/manager は一覧・詳細 200、編集/更新 403、admin 編集+監査、電話正規化検索、`phone_hmac` 再計算）／`ProfileTest`（本人編集・staff 403・未認証 redirect）／`CustomerPolicyTest`（permission + own-record 分岐 Unit）。
- **検証**：`seed` 冪等 ／ `npm run build` 型エラー0 ／ `artisan test` **115 passed / 786 assertions**（+11）。秘密情報・本番接続なし。baseline 不変。

### 2026-09-08 — Task 2-5（総合仕上げ：デモ用マスタ seeder）: Codex 実装完了・Claude Code 検証待ち
- `DemoMasterSeeder`：local 環境限定の早期ガード、admin 1名（対応 staff レコードあり）・staff ロール 2名・services 2件・booths 2件・今週の staff_shifts 4件・customers 2名を冪等投入。サービスには staff ロール2名を紐付け、Customer の model event を有効なまま通して `phone_hmac` を生成。
- デモ admin は `admin@ark.local` / `password`。2FA は秘密が利用者に伝わらない自動確認済み状態を避け、`two_factor_confirmed_at=null` のまま初回ログイン時に Fortify の設定画面へ誘導する。
- `DatabaseSeeder` は `RolePermissionSeeder` → `SettingsSeeder` のみに整理し、浮いた `test@example.com` ユーザーを削除。`DemoMasterSeeder` は既定実行に含めない。
- `Makefile` に `demo` ターゲットと help を追加。`DemoMasterSeederTest` で local の作成件数・2回実行時の冪等性・testing 環境での全スキップを検証。
- **Claude Code 実測欄**：`migrate:fresh --seed` / 全テスト / `npm run build` は未実施。レビュー時に追記する。

### 2026-09-08 — Task 2-5（総合仕上げ：デモ seeder + DatabaseSeeder 整理 + make demo）: ✅ 完了（無修正）
- `database/seeders/DemoMasterSeeder.php`（**local 専用**・非 local は早期 return・冪等 `firstOrCreate`/`updateOrCreate`/`sync`）：admin 1（`admin@ark.local` / `password`、2FA は `two_factor_confirmed_at=null`＝初回ログインで設定画面へ）+ staff 2 + services 2（施術スタッフ紐付け）+ booths 2 + staff_shifts 4 + customers 2（`Customer` の `saving` フックで `phone_hmac` 生成）。
- `DatabaseSeeder`：`RolePermissionSeeder` → `SettingsSeeder` のみに縮小。浮いていた `test@example.com` factory ユーザーを削除。`WithoutModelEvents` も除去。デモは `db:seed --class=DemoMasterSeeder`（local）。
- `Makefile`：`make demo`（`migrate:fresh --seed` → `DemoMasterSeeder`）+ `help` 追記。
- `tests/Feature/Seeders/DemoMasterSeederTest.php`：local で実行 → 各件数生成・2 回実行で不変（冪等）／非 local で実行 → 何も作らない。
- **検証**：`migrate:fresh --seed` clean（14 migration + 2 seeder）／`artisan test` **117 passed / 795 assertions**（+2）／`npm run build` 型エラー0 ／`db:seed --class=DemoMasterSeeder` で services=2 / booths=2 / staff=3(admin 含む) / customers=2 / shifts=4 / admin=1。秘密情報・本番接続なし。

---

## ✅ Phase 2 完了 — 2026-09-08

### 実装（Task 2-1〜2-5）
- **services / service_staff**：CRUD（`can:services.manage`=admin）、施術可能スタッフ、`requires_staff` バリデーション。
- **booths**：CRUD（`can:booths.manage`=admin）、論理無効化。
- **staff master**：一覧/編集/ロール変更/無効化（`can:staff.manage`=admin + `password.confirm`、**最後の admin 降格を transaction+lock で 422 防止**）。
- **staff_shifts**：CRUD（`can:shifts.manage`=admin）、`start<end` + 時間帯重複バリデーション（分割シフト可）。
- **顧客管理（admin）**：一覧（`customers.view`=admin/manager/staff）/ 詳細 / 編集（`customers.manage`=admin のみ）。**電話検索は `phone_hmac` 等価のみ**（平文 LIKE なし）。
- **顧客セルフ・プロフィール**：`/mypage/profile`（`CustomerPolicy` own-record、staff は 403）。
- 共有 `UpdateCustomerProfile` Action（transaction・監査に PII 値/email を載せない・email 不変）。
- 全 write は Action 経由（Admin Controller に `DB::` 直呼びゼロ）、read は Query クラス。
- パーミッション 8 → **13**（+`services.manage`/`booths.manage`/`shifts.manage`/`customers.view`/`customers.manage`）。`Gate::before` バイパスなし。
- デモ seeder（local 専用）+ `make demo`。

### 最終検証
- `migrate:fresh --seed`：14 migration + 2 seeder クリーン
- `artisan test`：**117 passed / 795 assertions / 0 failed**
- `npm run build`：型エラー 0（`vite-plugin-vuetify` 継続、警告なし）
- `composer audit` / `npm audit`：脆弱性 0
- 全ツリー秘密情報（実キー形式）・本番/外部実 API 文字列：**混入なし**
- CI ガード ローカル擬似実行：誤検知なし
- localhost：`/` 200 ／ `/login` 200 ／ `/admin/{services,booths,staff,customers,staff-shifts}` `/mypage/profile` → 302（未認証）
- git：baseline `00dc8b8` **不変**。Phase 2 の変更 ~60 ファイルは**未コミット**（`git diff HEAD` でレビュー可能）。`add`/`commit`/`push` 未実行。

### 残課題（Phase 3 以降・非ブロッカー）
1. お名前.com サーバー実測・共有 vs VPS 判断（Phase 0 残・`OPEN_QUESTIONS` #1-3）。
2. CI は git remote 未接続のため実走なし（ローカル擬似実行で確認）。
3. スタッフ本人による自分の勤務枠編集は未実装（`shifts.manage`=admin のみ）。
4. 顧客/スタッフの **email 変更** は未実装（verified 再送を伴うため Phase 2 対象外）。
5. `PII_LOOKUP_KEY` ローテーション時の `customers.phone_hmac` 一括再計算コマンドは未実装（rotation 時に必要）。
6. 顧客詳細に契約/回数券/予約履歴は未表示（Phase 4-7 で追加）。
7. デモ admin は 2FA 未設定状態（初回ログインで設定画面へ誘導）。

### Phase 3 開始条件
マスタ（サービス/スタッフ/シフト/ブース/顧客）が揃い、Phase 3（自作予約：reservations + state machine + `reservation_resource_slots` + `ReservationService` + 予約台帳 + 仮予約失効）に着手可能。
`RESERVATION_AUTHORITY=local` / `EXTERNAL_RESERVATION_GATEWAY=null` / Stripe 未接続で進行。
**ユーザーの明示許可を待つ。**
