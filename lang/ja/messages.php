<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 画面に表示するメッセージ（エラー・完了・案内）
|--------------------------------------------------------------------------
|
| サーバーから利用者・スタッフに返す文言はここに集約する。コードからは
| __('messages.<グループ>.<キー>') で参照する（直接文字列を書かない）。
| 画面側（Vue）の文言は resources/js/constants/messages.ts に置く。
*/

return [
    'staff_utilization' => [
        'attendance_invalid' => '出退勤時刻と営業日を確認してください。退勤は出勤後36時間以内にしてください。',
        'break_invalid' => '休憩時刻は出勤から退勤までの範囲内で指定してください。',
        'attendance_saved' => '実勤怠を保存しました。',
    ],
    // 共通
    'common' => [
        'reason_required' => '理由は必須です。',
        'too_many_attempts' => '試行回数が多すぎます。しばらくしてからお試しください。',
        'session_expired' => 'セッションの有効期限が切れました。もう一度お試しください。',
        'invalid_policy_value' => '不正なポリシー値です。',
        'end_after_start' => '終了時刻は開始時刻より後にしてください。',
        'retry_later_payment_start' => 'ただいま決済を開始できませんでした。時間をおいて再度お試しください。',
    ],

    // 認証・アカウント
    'auth' => [
        'email_already_registered' => 'このメールアドレスは登録済みです。ログインして予約してください。',
        'account_disabled' => 'このアカウントは無効化されています。管理者にお問い合わせください。',
        'invalid_credentials' => 'メールアドレスまたはパスワードが正しくありません。',
        'staff_totp_required' => '業務用アカウントは二段階認証を無効化できません。',
        'staff_totp_reset_hint' => '端末を変更する場合は、認証アプリで新しい QR コードを読み込んで再設定してください。',
        'member_upgrade_completed' => '会員登録が完了しました。メールアドレスの確認をお願いします。',
    ],

    // SMS 認証コード
    'otp' => [
        'invalid_phone' => '電話番号の形式が正しくありません。',
        'not_found' => '認証コードが見つかりません。もう一度送信してください。',
        'expired' => '認証コードの有効期限が切れました。もう一度送信してください。',
        'too_many_attempts' => '認証コードの試行回数が上限に達しました。もう一度送信してください。',
        'mismatch' => '認証コードが正しくありません。',
        'send_limit' => '認証コードの送信回数が上限に達しました。時間をおいてお試しください。',
        'sent' => '認証コードを送信しました。',
        'sent_enter_code' => '認証コードを送信しました。届いた6桁のコードを入力してください。',
        'no_pending_phone' => '確認中の電話番号がありません。もう一度お試しください。',
        'phone_verified' => '電話番号を確認しました。SMS を予備の認証手段として利用できます。',
        'staff_not_found' => 'スタッフ情報が見つかりません。',
    ],

    // Google ログイン・連携
    'google' => [
        'login_failed' => 'Google ログインに失敗しました。時間をおいて再度お試しください。',
        'profile_unavailable' => 'Google アカウント情報を取得できませんでした。',
        'email_unverified' => 'Google 側でメールアドレスが未確認のため利用できません。',
        'link_expired' => '連携手続きの有効期限が切れました。最初からやり直してください。',
        'email_mismatch' => 'Google アカウントのメールアドレスと一致しません。',
        'link_after_login' => 'このアカウントでは、ログイン後に設定画面から Google 連携を行ってください。',
        'not_linked' => 'Google 連携はされていません。',
        'cannot_unlink_without_password' => 'パスワードが未設定のため Google 連携を解除できません。',
        'set_password_first' => '先にパスワードを設定してください。',
        'unlinked' => 'Google 連携を解除しました。',
        'email_used_by_admin' => 'このメールアドレスは管理者アカウントに使用されています。',
        'link_from_settings' => 'ID とパスワードでログインし、設定画面から Google 連携を行ってください。',
        'login_busy' => 'ログイン処理が混み合っています。もう一度お試しください。',
        'link_session_invalid' => '連携セッションが無効です。もう一度お試しください。',
        'already_linked' => 'この Google アカウントは既に連携済みです。',
        'linked_to_other_account' => 'この Google アカウントは別の ARK アカウントに連携済みです。',
        'other_google_linked' => '既に別の Google アカウントが連携されています。',
        'unlink_current_first' => '先に現在の連携を解除してください。',
        'linked' => 'Google アカウントを連携しました。',
    ],

    // 予約
    'reservation' => [
        'slot_taken' => '指定の時間帯は既に予約されています',
        'stale' => '予約が他で更新されました。画面を更新してください',
        'only_confirmed_editable' => '確定済みの予約のみ変更できます。',
        'started_not_editable' => '開始済みの予約は変更できません。',
        'started_not_cancelable' => '開始済みの予約はキャンセルできません。',
        'service_unavailable' => 'このサービスは現在利用できません。',
        'service_not_online_bookable' => 'このサービスはオンライン予約できません。',
        'service_not_online_target' => 'このサービスはオンライン予約の対象ではありません。',
        'staff_required_for_service' => 'このサービスには担当スタッフの指定が必要です。',
        'staff_required' => 'このサービスには担当スタッフが必要です。',
        'resource_required' => '担当スタッフまたはブースを指定してください。',
        'staff_not_assigned' => 'このスタッフはサービスを担当できません。',
        'staff_not_assigned_to_selected' => 'このスタッフは選択したサービスを担当できません。',
        'staff_not_assigned_to_reserved' => 'このスタッフは予約サービスを担当できません。',
        'staff_not_bookable' => 'このスタッフは現在予約できません。',
        'outside_shift' => '指定時間はスタッフの勤務時間外です。',
        'staff_block_overlap' => 'この時間はスタッフの予定（休憩・他業務等）と重なっています。',
        'booth_unavailable' => 'このブースは現在利用できません。',
        'booth_block_overlap' => 'この時間はブースの予定（清掃・メンテナンス等）と重なっています。',
        'past_datetime' => '過去の日時は予約できません。',
        'closed_date' => '選択した日は休業日のため予約できません。',
        'outside_calendar_hours' => '選択した時間は店舗の特別営業時間外です。',
        'non_boundary_start' => '開始時刻を予約枠の境界に合わせてください。',
        'invalid_transition' => 'この予約はその操作を実行できません。',
        'customer_search_required' => '氏名・カナ・電話番号のうち、分かるものを1つ以上入力してください。',
        'created' => '予約を作成しました。',
        'updated' => '予約を更新しました。',
        'canceled' => '予約をキャンセルしました。',
        'completed' => '予約を完了にしました。',
        'no_show' => '予約を無断キャンセルにしました。',
        'moved' => '予約を変更しました。',
        'rescheduled' => '予約日時を変更しました。',
        'board_move_confirmed_only' => '確定済みの予約だけ台帳上で移動できます。',
        'board_move_started' => '開始済みの予約は台帳上で移動できません。',
        'confirmed' => '予約が確定しました。',
        'slot_held_pay_next' => '予約枠を確保しました。続けてカード決済を完了してください。',
        'pay_to_confirm' => 'お支払いを完了すると予約が確定します。',
        'policy_zero_tier_required' => '開始時刻まで0時間の段階を必ず含めてください。',
        'policy_too_large' => '返金段階の設定量が上限を超えています。',
        'policy_updated' => '予約キャンセルポリシーを更新しました。',
    ],

    'visit_completion' => [
        'invalid_status' => '確定済みの予約だけを来店完了にできます。',
        'legacy_completed_without_visit' => 'この完了済み予約には来店実績がないため、再完了できません。',
        'visit_conflict' => 'この予約には競合する来店実績があります。内容を確認してください。',
        'voided_checkout' => '取消済み会計があるため、来店完了できません。',
    ],

    // 予定ブロック（休憩・清掃など）
    'schedule_block' => [
        'resource_required' => 'スタッフまたはブースのいずれかを指定してください。',
        'ten_minute_unit' => '開始・終了時刻は10分単位で指定してください。',
        'title_required_for_other' => '「その他」を選択した場合はタイトルを入力してください。',
        'staff_not_found' => '指定されたスタッフが見つかりません。',
        'booth_not_found' => '指定されたブースが見つかりません。',
        'reservation_overlap' => 'この時間帯には既に予約が入っています。',
        'block_overlap' => 'この時間帯には既に別の予定が入っています。',
        'created' => '予定を追加しました。',
        'updated' => '予定を変更しました。',
        'deleted' => '予定を削除しました。',
    ],

    // 勤務枠・シフト
    'shift' => [
        'overlap' => '同じスタッフの勤務枠と時間帯が重複しています。',
        'template_overlap' => '同じ曜日の時間帯が重複しています。',
        'created' => '勤務枠を追加しました。',
        'updated' => '勤務枠を更新しました。',
        'deleted' => '勤務枠を削除しました。',
        'templates_saved' => '基本シフトを保存しました。',
        'exception_set' => '例外日を設定しました。',
        'exception_cleared' => '例外日を解除しました。',
        'booking_settings_saved' => '予約受付設定を保存しました。',
    ],

    // スタッフ
    'staff' => [
        'not_found_in_list' => '存在しないスタッフが含まれています。',
        'cannot_demote_last_admin' => '最後の管理者を降格することはできません。',
        'cannot_disable_self' => '自分自身のログインを無効化することはできません。',
        'cannot_disable_last_admin' => '最後の有効な管理者のログインを無効化することはできません。',
        'created' => 'スタッフを作成し、パスワード設定メールを送信しました。',
        'updated' => 'スタッフを更新しました。',
        'unbookable' => 'スタッフを予約受付不可にしました。',
    ],

    // メニュー（サービス）
    'service' => [
        'staff_required' => 'スタッフが必要なサービスには、施術可能スタッフを1名以上指定してください。',
        'created' => 'サービスを作成しました。',
        'updated' => 'サービスを更新しました。',
    ],

    // Phase 11 商品・業務マスタ
    'product' => [
        'created' => '商品を作成しました。',
        'updated' => '商品を更新しました。',
        'activated' => '商品を有効にしました。',
        'deactivated' => '商品を無効にしました。',
    ],
    'business' => [
        'karte_master_saved' => 'カルテ選択肢を保存しました。',
        'analysis_category_saved' => '分析カテゴリを保存しました。',
        'tax_category_saved' => '税区分を保存しました。',
        'tax_rate_saved' => '税率期間を保存しました。',
        'tax_rate_end_after_start' => '終了日は開始日より後にしてください。',
        'tax_rate_overlap' => '同じ税区分の適用期間が重複しています。',
        'payment_method_saved' => '決済方法を保存しました。',
        'calendar_saved' => '店舗カレンダーを保存しました。',
        'calendar_cleared' => '店舗カレンダーを通常営業に戻しました。',
        'calendar_invalid_status' => '店舗カレンダーの状態が不正です。',
        'calendar_hours_invalid' => '特別営業時間は開店時刻より閉店時刻を後にしてください。',
        'sales_target_saved' => '売上目標を保存しました。',
        'sales_target_cleared' => '月別売上目標を削除しました。',
        'employment_type_saved' => '雇用形態を保存しました。',
        'employment_period_overlap' => '雇用形態の適用期間が重複します。',
    ],

    'reporting' => [
        'course_target_saved' => 'コース目標を保存しました。',
        'course_missing' => '対象のコースが見つかりません。',
        'monthly_invalid' => '月計の指定条件が不正です。',
        'invalid_month' => '対象月が不正です。',
        'daily_note_saved' => '日報を保存しました。',
    ],

    // ブース
    'booth' => [
        'created' => 'ブースを作成しました。',
        'updated' => 'ブースを更新しました。',
    ],

    // 顧客
    'customer' => [
        'karte_updated' => 'カルテ項目を更新しました。',
        'referrer_self' => '本人を紹介者に指定できません。',
        'note_updated' => 'メモを更新しました。',
        'profile_updated' => '顧客プロフィールを更新しました。',
        'own_profile_updated' => 'プロフィールを更新しました。',
    ],

    // 回数券
    'ticket' => [
        'insufficient_balance' => '残数を超える操作です。',
        'grant_positive' => '付与回数は1以上で指定してください。',
        'revoke_positive' => '取消回数は1以上で指定してください。',
        'adjust_nonzero' => '調整数は0以外で指定してください。',
        'none_available' => '利用可能な回数券がありません',
        'none_available_sentence' => '利用可能な回数券がありません。',
        'granted' => '回数券を付与しました。',
        'revoked' => '回数券を取り消しました。',
        'adjusted' => '回数券残数を調整しました。',
        'policy_updated' => '回数券運用設定を更新しました。',
        'product_created' => '回数券商品を作成しました。',
        'product_updated' => '回数券商品を更新しました。',
    ],

    // 月額プラン（利用権）
    'membership' => [
        'over_period_limit' => '当期の利用可能回数を超える操作です。',
        'adjust_nonzero' => '調整数は 0 以外で指定してください。',
        'operation_key_required' => '操作キーは必須です。',
        'none_available' => '利用可能な利用権がありません',
        'none_available_sentence' => '利用可能な利用権がありません。',
        'expired' => '利用権の有効期間が終了しています',
        'plan_unavailable' => '選択されたプランは現在申し込めません。',
        'already_active' => 'すでに有効な利用権があります。',
        'adjusted' => '利用権残数を調整しました。',
        'canceled_now' => '利用権を即時解約しました。',
        'stripe_synced' => 'Stripe の現在状態を同期しました。',
        'plan_created' => '月額プランを作成しました。',
        'plan_updated' => '月額プランを更新しました。',
        'apply_pending' => 'お申し込みの確認に時間がかかっています。しばらくして状態をご確認ください。',
        'applied' => '利用権のお申し込みが完了しました。',
        'apply_accepted' => 'お申し込みを受け付けました。確定処理の完了までしばらくお待ちください。',
        'payment_pending' => 'お支払いの確認に時間がかかっています。確定次第ご利用に反映されます。',
        'payment_completed' => 'お支払いが完了し、利用権が有効になりました。',
        'payment_processing' => 'お支払いの確認処理を行っています。完了までしばらくお待ちください。',
        'cancel_at_period_end' => '当期末で停止します。期末までは利用できます。',
        'cancel_reverted' => '次回更新での解約を取り消しました。',
        'payment_method_updated' => '支払い方法を更新しました。',
    ],

    // 決済
    'payment' => [
        'only_pending_can_create_intent' => 'pending 状態の決済だけが PaymentIntent を作成できます。',
        'only_authorized_can_capture' => 'authorized 状態の決済だけを capture できます。',
        'only_pending_or_authorized_can_void' => 'pending または authorized 状態の決済だけを取消できます。',
        'only_captured_refundable' => 'capture 済みの決済だけを返金できます。',
        'only_captured_refundable_hint' => 'capture 済みの決済だけを返金できます。与信のみの決済は取消を使用してください。',
        'refund_exceeds_amount' => '決済金額を超える返金はできません。',
        'void_reason_required' => '取消または返金の理由は必須です。',
        'cannot_void_or_refund' => 'この決済状態では取消または返金を実行できません。',
        'final_amount_non_negative' => '最終施術金額は0円以上で入力してください。',
        'addon_only_confirmed_or_completed' => '確定または完了済みの予約だけが差額支払いを発行できます。',
        'checkout_not_allowed' => 'この予約は支払い手続きを開始できる状態ではありません。',
        'checkout_expired' => 'お支払い期限が切れています。もう一度予約をお取りください。',
        'not_card_reservation' => 'この予約はカード決済の対象ではありません。',
        'intent_not_created' => 'Stripe PaymentIntent がまだ作成されていません。',
        'refund_declined' => '返金がStripeで承認されませんでした。',
        'refunded' => '返金を実行しました。',
        'sync_failed' => 'Stripeと同期できませんでした。時間をおいて再度お試しください。',
        'synced' => 'Stripeの現在状態と同期しました。',
        'card_declined' => 'カードの承認が得られませんでした。別のお支払い方法をお試しください。',
        'confirmation_delayed' => 'お支払いの確認に時間がかかっています。確定次第ご予約に反映されます。',
        'completed_reservation_confirmed' => 'お支払いが完了し、ご予約が確定しました。',
        'reservation_held_processing' => 'ご予約を確保しました。決済の確定処理を行っています。',
        'addon_start_failed' => 'ただいま差額のお支払いを開始できません。時間をおいて再度お試しください。',
        'addon_delayed' => '差額のお支払い確認に時間がかかっています。確定次第反映されます。',
        'addon_processing' => '差額のお支払いを確認中です。確定次第反映されます。',
        'addon_completed' => '差額のお支払いが完了しました。',
    ],

    // 設定
    'settings' => [
        'notifications_updated' => '通知設定を更新しました。',
        'roles_updated' => '権限設定を更新しました。',
    ],

    // 外部連携
    'integration' => [
        'outbox_requeued' => 'Outbox を再投入しました。',
    ],

    // 来店・会計入力（Task 11-19）
    'checkout_entry' => [
        'saved' => '来店・会計を保存しました。',
        'completed' => '来店を完了しました。',
        'finalized' => '会計を確定しました。',
        'voided' => '会計を取り消しました。',
        'opened' => '来店・会計を開きました。',
        'amount_invalid' => '数量・金額が不正です。',
        'tax_rate_missing' => '売上日の税率が設定されていません。業務マスタで税率期間を登録してください。',
        'tax_category_required' => '税区分を選択してください。',
        'item_type_invalid' => '明細の種別が不正です。',
        'item_name_required' => '明細名を入力してください。',
        'customer_required_for_ticket' => '回数券・月額の販売には顧客が必要です。',
        'ticket_product_missing' => '回数券商品が見つかりません。',
        'ticket_grant_reason' => '店頭会計 #:id の回数券購入',
        'treatment_required' => '施術を1件以上入力してください。',
        'treatment_missing' => '明細に対応する施術が見つかりません。',
        'minutes_required' => '施術時間を1分以上で入力してください。',
        'staff_minutes_mismatch' => '担当スタッフの時間の合計が施術時間と一致しません。',
        'staff_duplicated' => '同じ施術に同じスタッフが重複しています。',
        'visit_locked' => 'この来店は変更できません。',
        'checkout_locked' => '確定済み会計がある来店の施術は変更できません。取消してから入力し直してください。',
        'visit_checkout_elsewhere' => '来店に紐づく会計は来店・会計画面で編集してください。',
        'complete_visit_first' => '先に来店を完了してください。',
        'tender_allocation_invalid' => '支払の配分が不正です。',
        'tender_allocation_mismatch' => '支払ごとの施術・物販の配分合計が支払額と一致しません。',
        'tender_allocation_category_mismatch' => '施術・物販それぞれの支払配分合計が明細の税込額と一致しません。',
        'tender_allocation_required' => '施術と物販がある会計で支払が複数の場合は、各支払の物販分を入力してください。',
    ],
];
