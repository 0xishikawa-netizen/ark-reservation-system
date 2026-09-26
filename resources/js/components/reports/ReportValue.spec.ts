import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import EmptyValue from '@/components/ark/EmptyValue.vue';
import { formatReportDate, formatReportValue } from './format';
import ReportValue from './ReportValue.vue';

describe('Reports の値表示', () => {
    it('null・undefined は薄いグレーの「-」にし、理由を aria-label / title に残す', () => {
        const nullValue = mount(ReportValue, { props: { value: null, format: 'percent', emptyLabel: '算出不可' } });
        const empty = nullValue.get('.ark-empty-value');
        expect(empty.text()).toBe('-');
        expect(empty.attributes('aria-label')).toBe('算出不可');
        expect(empty.attributes('title')).toBe('算出不可');
        expect(mount(ReportValue, { props: { value: undefined } }).get('.ark-empty-value').attributes('aria-label')).toBe('該当なし');
    });

    it('0 は実績値として 0 / 0円 / 0.0% のまま表示する', () => {
        expect(mount(ReportValue, { props: { value: 0 } }).text()).toBe('0');
        expect(mount(ReportValue, { props: { value: 0, format: 'money' } }).text()).toBe('0円');
        expect(mount(ReportValue, { props: { value: 0, format: 'percent' } }).text()).toBe('0.0%');
        expect(mount(ReportValue, { props: { value: 0 } }).find('.ark-empty-value').exists()).toBe(false);
    });

    it('hidden（未来日など）は値があっても「-」にする', () => {
        const hidden = mount(ReportValue, { props: { value: 1200, format: 'money', hidden: true, emptyLabel: '未実績' } });
        expect(hidden.text()).toBe('-');
        expect(hidden.get('.ark-empty-value').attributes('aria-label')).toBe('未実績');
    });

    it('書式を統一する', () => {
        expect(formatReportValue(1234567, 'money')).toBe('1,234,567円');
        expect(formatReportValue(0.2857, 'percent')).toBe('28.6%');
        expect(formatReportValue(0.44, 'decimal')).toBe('0.4');
        expect(formatReportValue(11880)).toBe('11,880');
        expect(formatReportValue(null)).toBeNull();
        expect(formatReportDate('2026-09-01')).toBe('9/1（火）');
    });

    it('EmptyValue は理由がなければ「該当なし」を補う', () => {
        const empty = mount(EmptyValue);
        expect(empty.text()).toBe('-');
        expect(empty.attributes('title')).toBe('該当なし');
    });
});
