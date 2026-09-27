# ARK 管理画面のセッション方針（2026-09-27 確定）

## 方針

**ARK 管理画面は、無操作・時間経過だけを理由に自動ログアウトさせない。**

店舗スタッフは管理画面を開いたまま施術・接客・会計などの業務をする。その間に時間が経っただけで再ログインを求められないようにする。

次のものは実装しない。既存のものは廃止した。

- 15 / 30 / 60 分などの無操作ログアウト（idle timeout / inactivity timer）
- 「あと○分でセッションが切れます」表示、カウントダウンのモーダル
- 時間経過を理由にしたログイン画面への強制遷移、意図的な 401 / 419
- フロントエンドの JavaScript による自動ログアウト

## 維持するもの（認証を弱めない）

- ログイン認証（Laravel 標準 + Fortify、web guard）、2 段階認証（`EnsureStaffMfa`）
- 管理画面権限（`AdminAccess`）、Role / Permission（spatie、各ルートの `can:*`）
- CSRF 対策（`VerifyCsrfToken`。除外は Stripe webhook のみ）
- ヘッダーの「ログアウト」による明示ログアウト。セッションを破棄し、トークンを再生成する。
- アカウント無効化時の利用停止（`EnsureAccountIsActive`）。次のリクエスト（下記の維持確認を含む）で即ログアウトする。
- 機微操作の再認証（`password.confirm`、`AUTH_PASSWORD_TIMEOUT` 既定 3 時間）。ログアウトではなく、該当操作の直前にパスワードを確認する。
- サーバー側でのセッション失効（明示ログアウト、無効化、セキュリティ上の強制失効など）は、これまでどおり有効

## 実装

| 対象 | 内容 |
|---|---|
| 廃止 | `App\Http\Middleware\AdminIdleTimeout`（最終操作から 30 分で logout し `/login?expired=1` へ）を admin ルートグループから外して削除。`config/admin.php`（`ADMIN_IDLE_TIMEOUT`）と `SettingsSeeder` の `admin.idle_timeout` も削除。既存 DB に残る `admin.idle_timeout` の行はどこからも読まないので影響しない（削除していない）。 |
| セッション維持 | `GET /admin/session/keep-alive`（`admin.session.keep-alive`、204）。admin グループの middleware（`auth`・`AdminAccess`・`EnsureAccountIsActive`・`EnsureStaffMfa`）をそのまま通る。`AdminLayout` が `useSessionKeepAlive`（`resources/js/composables/sessionKeepAlive.ts`）で、開いている間は 10 分ごと、タブ復帰時（前回から 1 分以上）に触れる。これにより Laravel のセッション寿命（`SESSION_LIFETIME`、既定 120 分）が、画面を開いたままの業務中に切れない。 |
| 本当に失効した時 | 維持確認が 401 / 419（またはログイン画面へのリダイレクト）を受けた時だけ、画面上部に「ログイン状態を確認できませんでした…」と「ログイン画面へ」を表示する。自動遷移や自動ログアウトはせず、以後の確認も止める（無限リトライしない）。通信断はログアウト扱いにしない。 |
| 419（Inertia） | 本当に失効したセッションでフォーム送信した場合は、英語の「Page Expired」モーダルを出さず、ログイン画面へ日本語の案内付きで移る。戻り先は操作していた画面（POST 先 URL にはしない）。Inertia 以外の送信は従来どおり 419。 |

## 残る時間要因（運用で判断）

- `SESSION_LIFETIME`（既定 120 分）は、**管理画面を1つも開いていない、または PC がスリープしてブラウザが止まっている**間だけ効く。店舗 PC を長時間スリープさせる運用なら、本番の `SESSION_LIFETIME` を業務時間に合わせて長めにするか判断する。値の変更はアプリの変更なしで `.env` だけでできる。
- ブラウザを閉じてもセッションは破棄しない（`SESSION_EXPIRE_ON_CLOSE=false`）。
- ログイン画面の「ログイン状態を保持する」（remember）は Laravel 標準の remember token で、既存どおり。

## テスト

- `tests/Feature/Admin/AdminAccessTest.php`：長時間の無操作でもログアウトしない。admin ルートに idle / timeout 系 middleware が無く、認証・権限・アカウント有効性は付いている。維持確認は未認証 401・権限なし 403・無効アカウントは即ログアウト。明示ログアウト後は管理画面・維持確認とも不可。Inertia の 419 はログイン画面へ日本語案内。
- `resources/js/composables/sessionKeepAlive.spec.ts`：何時間経っても自分からはログアウトしない。401 で案内を出して確認を止める。通信断では失効扱いしない。管理画面のソースに idle / countdown / auto-logout の実装が無く、`/logout` はヘッダーの明示操作だけから送る。
