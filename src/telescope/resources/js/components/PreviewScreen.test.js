import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { h } from 'vue';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import Base from '../base';
import ExceptionPreview from '../screens/exceptions/preview.vue';
import SchedulePreview from '../screens/schedule/preview.vue';
import PreviewScreen from './PreviewScreen.vue';

vi.mock('axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }));

let wrapper;

const entry = (id, status = 'pending') => ({
    id,
    created_at: '2026-10-28T00:00:00Z',
    tags: [],
    content: { status, hostname: 'localhost' },
});
const response = (value, batch = []) => ({ data: { entry: value, batch } });

async function mountPreview(path = '/jobs/first') {
    const Parent = {
        data: () => ({ entry: null, batch: [] }),
        render() {
            return h(PreviewScreen, { resource: 'jobs', title: 'Job', id: this.$route.params.id }, {
                'after-attributes-card': ({ entry }) => h('p', { class: 'entry-content' }, entry.id),
            });
        },
    };
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/jobs/:id', name: 'job-preview', component: Parent },
            { path: '/exceptions/:id', component: ExceptionPreview },
            { path: '/exceptions', name: 'exceptions', component: { render: () => null } },
            { path: '/schedule/:id', component: SchedulePreview },
            { path: '/schedule', name: 'schedule', component: { render: () => null } },
            { path: '/other', component: { render: () => null } },
        ],
    });
    await router.push(path);
    wrapper = mount({
        data: () => ({ alert: {} }),
        render: () => h(RouterView),
    }, {
        global: {
            plugins: [router],
            mixins: [Base],
            components: { 'preview-screen': PreviewScreen },
            stubs: { 'vue-json-pretty': true, 'copy-clipboard': true },
        },
    });
    wrapper.vm.$root.alert = wrapper.vm.alert;
    await flushPromises();

    return { router, screen: wrapper.findComponent(PreviewScreen) };
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('Telescope', { basePath: '/telescope' });
    axios.get.mockReset().mockResolvedValue(response(entry('first', 'processed')));
    axios.put.mockReset();
});

afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('exposes the loaded entry and related batch before consumers handle ready', async () => {
    const related = { ...entry('related'), type: 'job' };
    axios.get.mockResolvedValue(response(entry('first', 'processed'), [related]));
    const { screen } = await mountPreview();

    expect(screen.find('.entry-content').text()).toBe('first');
    expect(screen.find('a').text()).toBe('View Job');
    expect(screen.vm.$parent.entry.id).toBe('first');
    expect(screen.vm.$parent.batch).toEqual([related]);
    expect(screen.emitted('ready')).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(5000);
    expect(axios.get).toHaveBeenCalledOnce();
});

it('clears the previous entry on route reuse and ignores a stale response', async () => {
    const { router, screen } = await mountPreview();
    const second = Promise.withResolvers();
    axios.get.mockReturnValueOnce(second.promise).mockRejectedValueOnce({ response: { status: 404 } });
    await router.push('/jobs/second');
    await flushPromises();
    expect(screen.vm.entry).toBeNull();
    expect(screen.vm.$parent.entry).toBeNull();
    const signal = axios.get.mock.calls[1][1].signal;

    await router.push('/jobs/missing');
    await flushPromises();
    second.resolve(response(entry('second')));
    await flushPromises();
    expect(signal.aborted).toBe(true);
    expect(wrapper.text()).toContain('No entry found.');
    expect(screen.find('.entry-content').exists()).toBe(false);
    expect(screen.vm.$parent.batch).toEqual([]);
    expect(wrapper.vm.alert.type).toBeUndefined();
});

it('polls a pending job until it completes and stops on unmount', async () => {
    axios.get.mockResolvedValueOnce(response(entry('first'))).mockResolvedValue(response(entry('first', 'processed')));
    const { router, screen } = await mountPreview();
    await vi.advanceTimersByTimeAsync(2500);
    expect(screen.vm.entry.content.status).toBe('processed');
    expect(screen.vm.$parent.entry.content.status).toBe('processed');
    await vi.advanceTimersByTimeAsync(5000);
    expect(axios.get).toHaveBeenCalledTimes(2);

    await router.push('/other');
    expect(axios.get.mock.calls[1][1].signal.aborted).toBe(true);
    await vi.advanceTimersByTimeAsync(5000);
    expect(axios.get).toHaveBeenCalledTimes(2);
});

it('loads scheduled entries through their endpoint and links tags to the schedule list', async () => {
    axios.get.mockResolvedValue(response({
        ...entry('first', 'finished'),
        tags: ['daily'],
    }));
    const { screen } = await mountPreview('/schedule/first');

    expect(axios.get.mock.calls[0][0]).toBe('/telescope/telescope-api/schedule/first');
    expect(screen.find('a.badge').attributes('href')).toBe('/schedule?tag=daily');
});

it.each([
    ['network', new Error('offline'), true],
    ['HTTP', { response: { status: 500 } }, false],
])('preserves the loaded job after a %s polling failure', async (_type, failure, retry) => {
    axios.get.mockResolvedValueOnce(response(entry('first'))).mockRejectedValue(failure);
    const { screen } = await mountPreview();
    await vi.advanceTimersByTimeAsync(5000);

    expect(screen.vm.entry.id).toBe('first');
    expect(axios.get).toHaveBeenCalledTimes(retry ? 3 : 2);
    expect(wrapper.vm.alert.type).toBe(retry ? undefined : 'error');
});

it('resolves an exception in its parent state and ignores completion after navigation', async () => {
    const exception = {
        ...entry('first'),
        family_hash: 'family',
        content: { ...entry('first').content, class: 'RuntimeException', message: 'Failed', context: { user: 1 }, trace: [], line_preview: {} },
    };
    axios.get.mockResolvedValue(response(exception));
    axios.put.mockResolvedValue(response({ ...exception, content: { ...exception.content, resolved_at: '2026-10-28T01:00:00Z' } }));
    const { router } = await mountPreview('/exceptions/first');
    expect(wrapper.findAll('.nav-link').find(link => link.text() === 'Context').isVisible()).toBe(true);
    await wrapper.find('button').trigger('click');
    wrapper.vm.alert.confirmationProceed();
    await flushPromises();
    expect(wrapper.find('button').exists()).toBe(false);
    expect(wrapper.findComponent(ExceptionPreview).vm.entry.content.resolved_at).toBeTruthy();

    const pending = Promise.withResolvers();
    axios.put.mockReturnValue(pending.promise);
    wrapper.findComponent(ExceptionPreview).vm.markExceptionAsResolved(exception);
    wrapper.vm.alert.confirmationProceed();
    axios.get.mockResolvedValue(response({ ...exception, id: 'second' }));
    await router.push('/exceptions/second');
    await flushPromises();
    pending.resolve(response(exception));
    await flushPromises();
    expect(wrapper.findComponent(ExceptionPreview).vm.entry.id).toBe('second');
});
