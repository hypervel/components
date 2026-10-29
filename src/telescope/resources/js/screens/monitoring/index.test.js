import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { Modal } from 'bootstrap';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import Base from '../../base';
import Monitoring from './index.vue';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

let wrapper;
let container;

async function mountMonitoring() {
    container = document.createElement('div');
    document.body.appendChild(container);
    wrapper = mount(Monitoring, {
        attachTo: container,
        global: { mixins: [Base, { data: () => ({ alert: {} }) }] },
    });
    await flushPromises();
}

beforeEach(() => {
    vi.stubGlobal('Telescope', { basePath: '/telescope' });
    axios.get.mockReset().mockResolvedValue({ data: { tags: ['existing'] } });
    axios.post.mockReset().mockResolvedValue({ data: {} });
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
    expect(axios.post).not.toHaveBeenCalled();
    expect(document.body.style.overflow).toBe('');

    await wrapper.find('.card-header button').trigger('click');
    await input.setValue('new tag');
    await input.trigger('keyup', { key: 'Enter' });
    await flushPromises();
    expect(wrapper.find('tbody').text()).toContain('new tag');
    expect(axios.post).toHaveBeenCalledWith('/telescope/telescope-api/monitored-tags', { tag: 'new tag' });
    expect(document.body.classList.contains('modal-open')).toBe(false);
});

it('does not add a second row for an already monitored tag', async () => {
    await mountMonitoring();
    await wrapper.find('.card-header button').trigger('click');
    await wrapper.find('input').setValue('existing');
    await wrapper.findAll('.modal button')[0].trigger('click');

    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    expect(axios.post).not.toHaveBeenCalled();
});

it('removes a tag only after confirmation', async () => {
    await mountMonitoring();
    await wrapper.find('tbody a').trigger('click');
    expect(wrapper.find('tbody').text()).toContain('existing');
    expect(axios.post).not.toHaveBeenCalled();

    wrapper.vm.$root.alert.confirmationProceed();
    await flushPromises();
    expect(axios.post).toHaveBeenCalledWith('/telescope/telescope-api/monitored-tags/delete', { tag: 'existing' });
    expect(wrapper.text()).toContain('No tags are currently being monitored.');
});

it('releases an open modal when unmounted', async () => {
    await mountMonitoring();
    await wrapper.find('.card-header button').trigger('click');
    const element = wrapper.find('.modal').element;
    wrapper.unmount();
    wrapper = null;

    expect(Modal.getInstance(element)).toBeNull();
    expect(document.body.classList.contains('modal-open')).toBe(false);
    expect(document.body.style.overflow).toBe('');
    expect(document.querySelector('.modal-backdrop')).toBeNull();
});

it('disables monitoring until the initial tags load and aborts that load on unmount', async () => {
    const pending = Promise.withResolvers();
    axios.get.mockReturnValue(pending.promise);
    await mountMonitoring();
    expect(wrapper.find('.card-header button').element.disabled).toBe(true);
    const signal = axios.get.mock.calls[0][1].signal;
    wrapper.unmount();
    wrapper = null;
    expect(signal.aborted).toBe(true);
    pending.reject(new Error('canceled'));
    await flushPromises();
});

it('does not add a tag before success and preserves the draft after rejection', async () => {
    await mountMonitoring();
    await wrapper.find('.card-header button').trigger('click');
    await wrapper.find('input').setValue('new tag');
    const pending = Promise.withResolvers();
    axios.post.mockReturnValue(pending.promise);
    const request = wrapper.vm.monitorNewTag();
    expect(wrapper.vm.tags).toEqual(['existing']);
    pending.reject(new Error('Request failed'));
    await request;
    expect(wrapper.vm.tags).toEqual(['existing']);
    expect(wrapper.vm.newTag).toBe('new tag');
    expect(wrapper.vm.$root.alert.message).toContain('could not confirm');
});

it('retains a monitored tag when confirmed removal fails', async () => {
    await mountMonitoring();
    await wrapper.find('tbody a').trigger('click');
    axios.post.mockRejectedValue(new Error('Request failed'));
    await wrapper.vm.$root.alert.confirmationProceed();
    expect(wrapper.vm.tags).toEqual(['existing']);
    expect(wrapper.vm.$root.alert.message).toContain('could not confirm');
});

it('deduplicates successful additions without clearing a newer draft', async () => {
    await mountMonitoring();
    await wrapper.find('.card-header button').trigger('click');
    await wrapper.find('input').setValue('submitted');
    const pending = Promise.withResolvers();
    axios.post.mockReturnValue(pending.promise);
    const first = wrapper.vm.monitorNewTag();
    const second = wrapper.vm.monitorNewTag();
    await wrapper.find('input').setValue('next draft');
    pending.resolve({ data: {} });
    await Promise.all([first, second]);
    expect(wrapper.vm.tags).toEqual(['existing', 'submitted']);
    expect(wrapper.vm.newTag).toBe('next draft');
    expect(document.body.classList.contains('modal-open')).toBe(true);
});
