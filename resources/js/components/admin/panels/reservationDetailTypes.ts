// 予約詳細パネル（ReservationDetailPanel.vue）がサーバーから受け取るデータの型。

export interface HistoryRow {
    id: number;
    date: string;
    starts_at: string;
    service_id: number;
    service_name: string;
    staff_id: number | null;
    staff_name: string | null;
    status: string;
    status_label: string;
}

export interface PanelData {
    /** edit_customer は顧客メモの編集（customers.manage）。旧レスポンスとの互換のため任意。 */
    can: { manage: boolean; view_customer: boolean; edit_customer?: boolean };
    reservation: {
        id: number;
        customer_id: number;
        date: string;
        starts_at: string;
        ends_at: string;
        buffer_min?: number;
        service_id: number;
        service_name: string;
        staff_id: number | null;
        staff_name: string | null;
        is_staff_requested: boolean;
        staff_gender_preference?: 'male' | 'female' | null;
        booth_name: string | null;
        status: string;
        status_label: string;
        source_label: string;
        payment_method_label: string;
        amount: number;
        notes: string | null;
        cancel_reason: string | null;
        version: number;
        edit_url: string;
        payment: {
            id: number;
            amount: number;
            status_label: string;
            url: string;
        } | null;
        can_complete: boolean;
        visit_entry_url: string | null;
        can_cancel: boolean;
        can_no_show: boolean;
        can_extend?: boolean;
        extension_services?: { id: number; name: string }[];
    } | null;
    today_reservation_id: number | null;
    customer: {
        user_id: number;
        member_no: string;
        name: string;
        kana: string | null;
        gender: string | null;
        phone: string | null;
        note: string | null;
        visit_count: number;
        first_visit_at: string | null;
        last_visit_at: string | null;
        detail_url: string;
    } | null;
    tickets: {
        product_name: string;
        available: number;
        held: number;
        total: number;
        expires_at: string;
    }[];
    membership: {
        plan_name: string;
        status_label: string;
        available: number;
        usage_count_per_period: number;
        current_period_end: string | null;
    } | null;
    upcoming: HistoryRow[];
    history: { items: HistoryRow[]; total: number; has_more: boolean };
}
