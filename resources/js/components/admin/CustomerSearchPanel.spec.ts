import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CustomerSearchPanel from './CustomerSearchPanel.vue';

// 全面検証（2026-09-30）：予約の作成権限が無い一般スタッフには「新規予約を作成」を出さない（押すと 403 になるため）。
describe('CustomerSearchPanel', () => {
    it('shows 新規予約を作成 only when the user can create reservations', () => {
        const staff = mount(CustomerSearchPanel, { props: { canSearch: true, canCreate: false } });
        expect(staff.text()).not.toContain('新規予約を作成');

        const manager = mount(CustomerSearchPanel, { props: { canSearch: true, canCreate: true } });
        expect(manager.text()).toContain('新規予約を作成');
    });
});
