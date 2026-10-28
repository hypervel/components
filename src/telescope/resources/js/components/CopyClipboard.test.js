import { mount } from '@vue/test-utils';
import copy from 'copy-to-clipboard';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import CopyClipboard from './CopyClipboard.vue';

vi.mock('copy-to-clipboard', () => ({ default: vi.fn() }));

afterEach(() => {
    vi.clearAllMocks();
    vi.useRealTimers();
});

it.each([
    ['plain text', 'plain text'],
    [{ message: 'hello' }, '{\n\t"message": "hello"\n}'],
])('copies %j without navigating and shows success', async (data, expected) => {
    vi.useFakeTimers();
    copy.mockReturnValue(true);
    const wrapper = mount(CopyClipboard, { props: { data }, slots: { default: '<pre>Content</pre>' } });
    const click = new MouseEvent('click', { bubbles: true, cancelable: true });
    wrapper.find('a').element.dispatchEvent(click);
    await wrapper.vm.$nextTick();

    expect(click.defaultPrevented).toBe(true);
    expect(copy).toHaveBeenCalledWith(expected);
    expect(wrapper.find('.copy-to-clipboard > span').exists()).toBe(true);
    expect(wrapper.find('pre').text()).toBe('Content');
    wrapper.unmount();
});

it('does not show success when the clipboard requires manual copying', async () => {
    copy.mockReturnValue(false);
    const wrapper = mount(CopyClipboard, { props: { data: 'text' } });
    await wrapper.find('a').trigger('click');

    expect(copy).toHaveBeenCalledWith('text');
    expect(wrapper.find('.copy-to-clipboard > span').exists()).toBe(false);
    expect(wrapper.find('a').exists()).toBe(true);
    wrapper.unmount();
});
