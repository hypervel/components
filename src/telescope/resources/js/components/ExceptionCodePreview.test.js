import { mount } from '@vue/test-utils';
import { expect, it } from 'vite-plus/test';
import ExceptionCodePreview from './ExceptionCodePreview.vue';

it('displays source text without interpreting HTML on either kind of line', () => {
    const source = 'throw new Exception("<img src=x onerror=alert(1)>");';
    const wrapper = mount(ExceptionCodePreview, {
        props: { lines: { 10: source, 11: source }, highlightedLine: 10 },
    });

    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.findAll('p').map((line) => line.text())).toEqual(['10 ' + source, '11 ' + source]);
    expect(wrapper.find('p.highlight').find('.hljs-keyword').exists()).toBe(false);
    expect(wrapper.findAll('p')[1].find('.hljs-keyword').exists()).toBe(true);
    wrapper.unmount();
});
