import { flushPromises, mount } from '@vue/test-utils';
import { Modal } from 'bootstrap';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import Monitoring from './index.vue';
import Base from '../../base';

const http = { get: vi.fn(), post: vi.fn(), delete: vi.fn() };

let wrapper;
let container;

async function mountMonitoring() {
    container = document.createElement('div');
    document.body.appendChild(container);
    wrapper = mount(Monitoring, {
        attachTo: container,
        global: {
            mixins: [Base, { data: () => ({ alert: {} }) }],
            mocks: { $http: http },
            stubs: { poll: true, 'router-link': { template: '<a><slot /></a>' } },
        },
    });
    wrapper.vm.loadTags();
    await flushPromises();
}

beforeEach(() => {
    vi.stubGlobal('Horizon', { basePath: '/horizon' });
    http.get.mockReset().mockResolvedValue({ data: [{ tag: 'existing', count: 0 }] });
    http.post.mockReset().mockResolvedValue({ data: {} });
    http.delete.mockReset().mockResolvedValue({ data: {} });
});

afterEach(() => {
    wrapper?.unmount();
    container?.remove();
    vi.unstubAllGlobals();
});

it('focuses the input and supports canceling and reopening the modal', async () => {
    await mountMonitoring();
    await wrapper.find('.card-header button').trigger('click');
    const input = wrapper.find('input');
    expect(document.activeElement).toBe(input.element);
    await input.setValue('canceled');
    await wrapper.findAll('.modal button')[1].trigger('click');
    expect(input.element.value).toBe('');
    expect(http.post).not.toHaveBeenCalled();
    expect(document.body.style.overflow).toBe('');

    await wrapper.find('.card-header button').trigger('click');
    await input.setValue('new tag');
    await input.trigger('keyup', { key: 'Enter' });
    await flushPromises();
    expect(wrapper.find('tbody').text()).toContain('new tag');
    expect(http.post).toHaveBeenCalledWith('/horizon/api/monitoring', { tag: 'new tag' });
    expect(document.body.classList.contains('modal-open')).toBe(false);
});

it('releases an open modal and restores scrolling when unmounted', async () => {
    await mountMonitoring();
    await wrapper.find('.card-header button').trigger('click');
    const element = wrapper.find('.modal').element;
    expect(Modal.getInstance(element)).not.toBeNull();
    wrapper.unmount();
    wrapper = null;

    expect(Modal.getInstance(element)).toBeNull();
    expect(document.body.classList.contains('modal-open')).toBe(false);
    expect(document.body.style.overflow).toBe('');
    expect(document.querySelector('.modal-backdrop')).toBeNull();
});

it('uses the submitted tag and preserves a newer draft while adding only one row', async () => {
    await mountMonitoring();
    await wrapper.find('.card-header button').trigger('click');
    await wrapper.find('input').setValue('submitted');
    const pending = Promise.withResolvers();
    http.post.mockReturnValue(pending.promise);
    const first = wrapper.vm.monitorNewTag();
    const second = wrapper.vm.monitorNewTag();
    await wrapper.find('input').setValue('next draft');

    pending.resolve({ data: {} });
    await Promise.all([first, second]);
    await flushPromises();

    expect(wrapper.vm.tags.filter(({ tag }) => tag === 'submitted')).toHaveLength(1);
    expect(wrapper.find('input').element.value).toBe('next draft');
    expect(document.body.classList.contains('modal-open')).toBe(true);
});

it('keeps tags and the draft when monitoring requests fail', async () => {
    await mountMonitoring();
    await wrapper.find('.card-header button').trigger('click');
    await wrapper.find('input').setValue('new tag');
    http.post.mockRejectedValue(new Error('Request failed'));
    await wrapper.vm.monitorNewTag();
    expect(wrapper.vm.tags.map(({ tag }) => tag)).toEqual(['existing']);
    expect(wrapper.find('input').element.value).toBe('new tag');
    expect(wrapper.vm.$root.alert.message).toContain('could not confirm');

    wrapper.vm.$root.alert = {};
    http.delete.mockRejectedValue(new Error('Request failed'));
    await wrapper.vm.stopMonitoring('existing');
    expect(wrapper.vm.tags.map(({ tag }) => tag)).toEqual(['existing']);
    expect(wrapper.vm.$root.alert.message).toContain('could not confirm');
});
