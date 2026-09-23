/**
 * 画面に表示するメッセージ（エラー・完了・確認・注意・空の時の表示）。
 *
 * 画面側の文言はここに集約し、各画面からは MESSAGES.<グループ>.<キー> で参照する。
 * サーバーから返すメッセージは lang/ja/messages.php に置く。
 */
export const MESSAGES = {
    /** 共通 */
    common: {
        loading: '読み込み中…',
        loadingInParens: '（読み込み中…）',
        loadFailed: '読み込めませんでした。',
        actionFailedReload: '処理できませんでした。画面を更新してもう一度お試しください。',
        checkInput: '入力内容を確認してください。',
        checkInputPolite: '入力内容をご確認ください。',
        confirmAction: 'この操作を実行してよろしいですか？',
        reauthAudited: 'この操作は再認証と監査記録の対象です。',
    },
    /** 空き時間 */
    availability: {
        loadFailed: '空き時間を取得できませんでした。',
        weekLoadFailed: '週間の空き状況を取得できませんでした。',
        noneOnDate: '選択日に予約できる時間はありません。',
        noneToShow: '表示できる予約時間がありません。',
        noneForCondition: 'この条件で空いている時間がありません。日付・担当・メニューを変えてみてください。',
    },
    /** 予約 */
    reservation: {
        notConfirmedNotEditable: 'この予約は確定状態ではないため、変更操作はできません。',
        confirmMove: '予約を変更しますか？',
        moveConflict: '他の予約が入ったため、この時間には変更できません。台帳を更新して再度お試しください。',
        noUpcoming: '今後の予約はありません。',
        noPast: '過去の予約はありません。',
        noHistory: '予約履歴はありません。',
        noVisitHistory: '来店履歴はありません。',
        notYetConfirmed: 'この予約はまだ確定していません。お支払いを完了するか、不要であればキャンセルしてください。',
        cancelReleasesSlot: 'キャンセルすると、この予約枠は解放されます。',
        notFoundByPhone: 'この電話番号に紐づく予約は見つかりませんでした。',
        pickSlotOnBoard: 'ボードで空き枠を選択してください',
        pickMenuToFixTime: 'メニューを選ぶと開始時間が確定します。',
        provisionalCustomerHint: '分かるものだけで登録できます。来店時に顧客詳細から正しい氏名・連絡先に直してください。',
        provisionalCustomerRequired: '氏名・カナ・電話番号のうち、分かるものを1つ以上入力してください。',
        provisionalCustomerInvalid: '登録できませんでした。入力内容を確認してください。',
        provisionalCustomerNetwork: '登録できませんでした。通信状況を確認してください。',
        slotChoiceQuestion: 'この枠に何を入れますか？',
    },
    /** ブッキングボードの予定 */
    schedule: {
        noVisibleBooths: '表示対象のブースはありません。',
        noBookableStaff: '表示対象の予約受付スタッフはいません。',
        confirmBlockMove: '予定を変更しますか？',
        blockMoveConflict: '他の予定・予約と重なるため、この時間には変更できません。',
        confirmBlockDelete: 'この予定を削除しますか？',
        blockNotBookable: 'この時間はスタッフの予約枠から外れ、オンライン予約でも埋まりません。',
    },
    /** メニュー */
    menu: {
        noneMatched: '該当するメニューがありません。',
        noneOnlineMenu: '現在オンライン予約できるメニューはありません。',
        noneOnlineService: '現在オンライン予約できるサービスはありません。',
    },
    /** 顧客 */
    customer: {
        emailNotEditable: 'メールアドレスはこの画面では変更できません。',
        phoneExactMatch: '電話番号はハイフンの有無を問わず完全一致で検索します。部分一致には対応していません。',
        loadFailed: '顧客情報を読み込めませんでした。',
        notFound: '該当する顧客が見つかりませんでした。',
        searchForbidden: '顧客検索を行う権限がありません。',
        viewForbidden: '顧客情報の閲覧権限がありません。',
        paymentViewForbidden: '支払い情報の閲覧権限がありません。',
        searchPanelHint: '上の検索欄から顧客を選ぶか、台帳の予約・空き枠をクリックしてください。',
    },
    /** 決済 */
    payment: {
        noHistory: '決済履歴はありません。',
        noPaymentHistory: 'お支払い履歴はありません。',
        doNotRetryUnknown: '結果が不明な状態で二重に操作しないでください。',
        refundIrreversible: '返金は取り消せません。実行にはパスワードの再入力が必要です。',
        startFailed: '決済を開始できませんでした。もう一度お試しください。',
        formLoadFailed: '決済フォームを読み込めませんでした。通信環境をご確認ください。',
        checkCard: 'カード情報をご確認のうえ、もう一度お試しください。',
        completeFailed: '決済を完了できませんでした。時間をおいて再度お試しください。',
        expired: 'お支払い期限が切れました。お手数ですが、もう一度ご予約をお取りください。',
        stripeHandlesCardConfirm: 'カード情報は Stripe が直接処理します。当店のサーバーには保存されません。 お支払いが確定した時点でご予約が完了します。',
        stripeHandlesCard: 'カード情報は Stripe が直接処理します。当店のサーバーには保存されません。',
        stripeHandlesCardShort: 'カード情報は Stripe が直接処理し、当店のサーバーには保存されません。',
        confirmedCompletesReservation: 'お支払いが確定した時点でご予約が完了します。',
        payAtStore: '当日、店舗でお支払いください',
        holdThenCard: '予約枠を一時確保した後、カード決済画面へ進みます。決済完了時に予約が確定します。',
        cardAfterReserve: '予約後にカード情報を入力します。お支払いが完了するまで、枠は一時確保されます。',
    },
    /** 回数券 */
    ticket: {
        noneGrantable: '付与できる有効な回数券商品がありません。',
        noneUsable: '利用できる回数券はありません。',
        noneSelectable: '利用可能な回数券がないため、回数券は選択できません。',
        policyNotRetroactive: 'この変更は今後作成される回数券利用予約に適用されます。既存の HOLD 済み予約には遡及適用されません。',
    },
    /** 月額プラン（利用権） */
    membership: {
        stripeCheckRequired: 'Stripe との状態確認が必要です。同期または運用手順に沿った確認を行ってください。',
        cancelNowWarning: '即時解約すると現在の利用権は直ちに予約不可になります。この操作は再認証と監査記録の対象です。',
        priceIdHint: 'Stripe のテストモードで作成した価格ID（price_ から始まる値）を入力してください。',
        cancelScheduled: '当期末で解約予定です。',
        notSubscribed: '利用権は未加入です。',
        waitOnThisScreen: 'この画面を閉じずにお待ちください。',
        nonePlans: '現在申し込める月額プランはありません。',
        suspended: 'お支払いが確認できず一時停止中です。',
        unpaidApplication: 'お申し込みのお支払いが未完了です。',
        noUsageHistory: '利用履歴はありません。',
        cancelAtPeriodEndHint: '当期末まではご利用いただけます。次回以降の更新を停止します。',
        notSelectable: '利用可能回数がないか、現在の状態では利用権を選択できません。',
        usesOnePerPeriod: '当期の利用権を1回分使用します。',
        threeDsInProgress: 'カードの本人認証を行っています。しばらくお待ちください。',
        confirmingPayment: 'お支払いの確定を確認しています。',
        cardDeclined: 'カードの承認が得られませんでした。別のお支払い方法をお試しください。',
        threeDsFailed: '本人認証を完了できませんでした。もう一度お試しください。',
        cardFormLoadFailed: 'カード入力フォームを読み込めませんでした。時間をおいて再度お試しください。',
        cardRegisterFailed: 'カードを登録できませんでした。時間をおいて再度お試しください。',
    },
    /** スタッフ */
    staff: {
        loginDisabledWarning: '保存すると、このスタッフはログインできなくなります（既にログイン中の場合は次の操作で自動的にログアウトされます）。',
        selectStaff: 'スタッフを選択してください。',
    },
    /** 勤務枠・シフト */
    shift: {
        noUpcomingExceptions: '今後の例外日はありません。',
        noIndividualShifts: '個別の勤務枠はありません。',
        noClosedDates: '休業日は登録されていません。',
    },
    /** 認証・2段階認証 */
    auth: {
        twoFactorRequired: '管理画面を利用するには、認証アプリ（TOTP）で6桁コードを設定してください。',
        twoFactorConfigured: '2段階認証は設定済みです。',
        passwordStatusFailed: 'パスワード確認状態を取得できませんでした。',
        twoFactorSetupFailed: '2段階認証の設定情報を取得できませんでした。',
        saveRecoveryCodes: 'リカバリーコードを安全な場所に保存してください。',
        setPasswordBeforeUnlink: '連携を解除するには、先にパスワードを設定してください（ログイン手段が無くなるのを防ぐため）。',
    },
    /** 外部連携 */
    integration: {
        noneActive: '現在、実動する外部予約連携はありません（Mock のみ利用可能）。',
        retryForbidden: '再送には「連携管理」権限が必要です。',
        noSyncHistory: '同期履歴はまだありません。',
    },
    /** システム */
    system: {
        noFailedJobs: '失敗ジョブはありません。',
    },
    /** 設定 */
    settings: {
        notificationSpeakerHint: '聞こえにくい場合はパソコン本体の音量も上げてください。',
        cancellationTierHint: '予約開始までの残り時間が条件以上となる最初の段階を適用します。0時間の段階は必須です。',
    },
} as const;

/** 勤務枠の削除確認。 */
export function confirmDeleteShiftMessage(label: string): string {
    return `${label} の勤務枠を削除しますか？`;
}

/** スタッフを予約受付不可にする時の確認。 */
export function confirmStaffUnbookableMessage(name: string): string {
    return `${name}を予約受付不可にしますか？`;
}

/** 指定した開始時刻が予約できない時の案内。 */
export function unavailableDesiredTimeMessage(time: string): string {
    return `${time} はこのメニュー・担当では空いていません。別の空き枠をクリックしてください。`;
}

/** メニュー別空き枠プレビューの予約不可案内。 */
export function cannotStartAtTimeMessage(serviceName: string): string {
    return `${serviceName}はこの時間から開始できません`;
}
