import { flushPromises, shallowMount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import Base from '../base';
import FailedJob from './failedJobs/job.vue';
import FailedJobs from './failedJobs/index.vue';
import Batch from './batches/preview.vue';

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it.each([
    ['failed job', FailedJob],
    ['failed jobs list', FailedJobs],
    ['batch', Batch],
])('reports an unconfirmed %s retry and allows trying again', async (_, component) => {
    vi.useFakeTimers();
    vi.stubGlobal('Horizon', { basePath: '/horizon' });
    const post = vi.fn().mockRejectedValueOnce(new Error('Request failed')).mockResolvedValue({});
    wrapper = shallowMount(component, {
        global: {
            mixins: [Base, { data: () => ({ alert: {} }) }],
            mocks: {
                $http: {
                    post,
                    get: vi.fn().mockResolvedValue({
                        data: {
                            id: 'example',
                            payload: { data: {} },
                            context: {},
                            exception: '',
                            retried_by: [],
                        },
                    }),
                },
                $route: { params: { jobId: 'example' } },
            },
            stubs: { poll: true, 'vue-json-pretty': true, 'router-link': true },
        },
    });
    await flushPromises();
    await wrapper.vm.retry('example');
    expect(wrapper.vm.$root.alert.message).toContain('could not confirm');
    await wrapper.vm.retry('example');
    expect(post).toHaveBeenCalledTimes(2);
});
