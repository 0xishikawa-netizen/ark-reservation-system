/** 予定（スタッフ予定）の種類。画面で選べるのはこの6つ。 */
export const BLOCK_TYPES = [
    { value: 'BREAK', title: '休憩' },
    { value: 'MEETING', title: 'MTG' },
    { value: 'WORK', title: '業務' },
    { value: 'TRAINING', title: '研修' },
    { value: 'OUT', title: '外出' },
    { value: 'OTHER', title: 'その他' },
];

/** 以前に作った予定の種類（今は選べないが、既存の予定の表示・編集のために名前だけ残す）。 */
export const LEGACY_BLOCK_TYPES = [
    { value: 'ADMIN', title: '事務' },
    { value: 'CLEANING', title: '清掃' },
];
