/**
 * 新規予約／予定追加パネルの「入力中の内容」を、パネル切替や「戻る」をまたいでも
 * 失わないための draft（下書き）状態。Schedule/Index.vue がこの draft を1つだけ持ち続け、
 * NewReservationPanel.vue / ScheduleBlockCreatePanel.vue へそのまま渡す（同じオブジェクトを
 * 参照させるので、子側での入力がそのまま draft に反映される）。
 *
 * クリアするのは「予約/予定の作成に成功した時」だけ（§17）。パネル切替・戻る・
 * MenuPicker・顧客検索では消さない。
 *
 * ロジックを Vue コンポーネントから切り離した純粋関数にしているのは、Vitest で
 * コンポーネントをマウントせずに直接テストできるようにするため。
 */

export interface ReservationDraft {
    customer_id: number | null;
    customer_name: string | null;
    customer_kana: string | null;
    service_id: number | null;
    staff_id: number | null;
    is_staff_requested: boolean;
    booth_id: number | null;
    booth_manually_set: boolean;
    date: string;
    starts_at: string | null;
    /** 施術後に確保する余白（分）。着替え・片付け用（§バッファ）。 */
    buffer_min: number;
    notes: string;
}

export function createEmptyReservationDraft(): ReservationDraft {
    return {
        customer_id: null,
        customer_name: null,
        customer_kana: null,
        service_id: null,
        staff_id: null,
        is_staff_requested: false,
        booth_id: null,
        booth_manually_set: false,
        date: '',
        starts_at: null,
        buffer_min: 0,
        notes: '',
    };
}

export interface ReservationPrefillLike {
    customer_id: number | null;
    service_id: number | null;
    staff_id: number | null;
    booth_id: number | null;
    date: string | null;
    time: string | null;
}

/**
 * 新しく分かった具体的な情報（空き枠クリック・再予約など）だけを draft へ上書きする。
 * 値が null の項目は「新しい情報がない」という意味なので、既存 draft をそのまま残す。
 */
export function applyReservationPrefill(draft: ReservationDraft, prefill: ReservationPrefillLike): void {
    if (prefill.customer_id !== null) {
        draft.customer_id = prefill.customer_id;
    }
    if (prefill.service_id !== null) {
        draft.service_id = prefill.service_id;
    }
    if (prefill.staff_id !== null) {
        draft.staff_id = prefill.staff_id;
    }
    if (prefill.booth_id !== null) {
        draft.booth_id = prefill.booth_id;
        draft.booth_manually_set = true;
    }
    if (prefill.date !== null) {
        draft.date = prefill.date;
    }
    if (prefill.time !== null) {
        draft.starts_at = `${prefill.date ?? draft.date} ${prefill.time}:00`;
    }
}

export function resetReservationDraft(draft: ReservationDraft): void {
    Object.assign(draft, createEmptyReservationDraft());
}

export interface BlockDraft {
    target_kind: 'staff' | 'booth';
    staff_id: number | null;
    booth_id: number | null;
    work_date: string;
    start_at: string;
    end_at: string;
    type: string;
    title: string;
    note: string;
}

export function createEmptyBlockDraft(): BlockDraft {
    return {
        target_kind: 'staff',
        staff_id: null,
        booth_id: null,
        work_date: '',
        start_at: '',
        end_at: '',
        type: 'BREAK',
        title: '',
        note: '',
    };
}

export interface BlockPrefillLike {
    staff_id: number | null;
    booth_id: number | null;
    date: string | null;
    time: string | null;
}

export function blockEndTimeFrom(time: string | null): string {
    if (time === null) {
        return '';
    }

    const [h, m] = time.split(':').map(Number);
    const total = h * 60 + m + 60;

    return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

export function applyBlockPrefill(draft: BlockDraft, prefill: BlockPrefillLike): void {
    if (prefill.staff_id !== null) {
        draft.staff_id = prefill.staff_id;
        draft.target_kind = 'staff';
    }
    if (prefill.booth_id !== null && prefill.staff_id === null) {
        draft.booth_id = prefill.booth_id;
        draft.target_kind = 'booth';
    }
    if (prefill.date !== null) {
        draft.work_date = prefill.date;
    }
    if (prefill.time !== null) {
        draft.start_at = prefill.time;
        draft.end_at = blockEndTimeFrom(prefill.time);
    }
}

export function resetBlockDraft(draft: BlockDraft): void {
    Object.assign(draft, createEmptyBlockDraft());
}
