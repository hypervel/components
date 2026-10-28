import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { Modal } from 'bootstrap';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
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

it('aborts a pending load and releases an open modal when unmounted', async () => {
    const pending = Promise.withResolvers();
    axios.get.mockReturnValue(pending.promise);
    await mountMonitoring();
    await wrapper.find('.card-header button').trigger('click');
    const element = wrapper.find('.modal').element;
    const signal = axios.get.mock.calls[0][1].signal;
    wrapper.unmount();
    wrapper = null;

    expect(signal.aborted).toBe(true);
    expect(Modal.getInstance(element)).toBeNull();
    expect(document.body.classList.contains('modal-open')).toBe(false);
    expect(document.body.style.overflow).toBe('');
    expect(document.querySelector('.modal-backdrop')).toBeNull();
    pending.reject(new Error('canceled'));
    await flushPromises();
});
