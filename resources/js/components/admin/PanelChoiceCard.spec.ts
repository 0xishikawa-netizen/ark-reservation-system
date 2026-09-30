import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PanelChoiceCard from './PanelChoiceCard.vue';

describe('PanelChoiceCard', () => {
    it('予約・予定どちらも同じ形のボタンで、タイトルと説明を表示してクリックを返す', async () => {
        for (const kind of ['reservation', 'block'] as const) {
            const card = mount(PanelChoiceCard, { props: { kind, title: `${kind}の作成`, description: '説明文' } });
            expect(card.element.tagName).toBe('BUTTON');
            expect(card.classes()).toContain('pcc');
            expect(card.classes()).toContain(`pcc--${kind}`);
            expect(card.text()).toContain(`${kind}の作成`);
            expect(card.text()).toContain('説明文');
            await card.trigger('click');
            expect(card.emitted('click')).toHaveLength(1);
            card.unmount();
        }
    });
});
