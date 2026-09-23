import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PanelShell from './PanelShell.vue';

describe('PanelShell', () => {
    it('renders the given title', () => {
        const wrapper = mount(PanelShell, { props: { title: '新規予約' } });

        expect(wrapper.text()).toContain('新規予約');
    });

    it('does not render a footer when no #footer slot content is given', () => {
        const wrapper = mount(PanelShell, { props: { title: '顧客詳細' } });

        expect(wrapper.find('.panel-shell__footer').exists()).toBe(false);
    });

    it('renders a footer when #footer slot content is provided', () => {
        const wrapper = mount(PanelShell, {
            props: { title: '新規予約' },
            slots: { footer: '<button>予約を作成</button>' },
        });

        expect(wrapper.find('.panel-shell__footer').exists()).toBe(true);
        expect(wrapper.text()).toContain('予約を作成');
    });

    it('hides the back button by default and shows it when showBack is true', () => {
        const withoutBack = mount(PanelShell, { props: { title: 'x' } });
        expect(withoutBack.find('button[aria-label="戻る"]').exists()).toBe(false);

        const withBack = mount(PanelShell, { props: { title: 'x', showBack: true } });
        expect(withBack.find('button[aria-label="戻る"]').exists()).toBe(true);
    });

    it('emits back when the back button is clicked', async () => {
        const wrapper = mount(PanelShell, { props: { title: 'x', showBack: true } });

        await wrapper.find('button[aria-label="戻る"]').trigger('click');

        expect(wrapper.emitted('back')).toHaveLength(1);
    });

    it('emits close when the close button is clicked', async () => {
        const wrapper = mount(PanelShell, { props: { title: 'x' } });

        await wrapper.find('button[aria-label="パネルを閉じる"]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('renders slot body content', () => {
        const wrapper = mount(PanelShell, {
            props: { title: 'x' },
            slots: { default: '<p class="hello">本文です</p>' },
        });

        expect(wrapper.find('.hello').text()).toBe('本文です');
    });
});
