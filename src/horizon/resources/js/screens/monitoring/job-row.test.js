import { shallowMount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import Base from '../../base';
import JobRow from './job-row.vue';

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
});

it.each([
    ['seconds', 'i:600;'],
    ['interval', 'O:12:"DateInterval":2:{s:11:"from_string";b:1;s:11:"date_string";s:10:"10 minutes";}'],
])('shows a monitored job delay expressed as %s', (_, delay) => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-01-01T00:00:00Z'));
    wrapper = shallowMount(JobRow, {
        props: {
            job: {
                id: 'example',
                name: 'ExampleJob',
                status: 'pending',
                payload: {
                    tags: [],
                    pushedAt: Date.now() / 1000,
                    delay: 600,
                    data: { command: `O:8:"stdClass":1:{s:5:"delay";${delay}}` },
                },
            },
        },
        global: { mixins: [Base], stubs: { 'router-link': true } },
    });
    expect(wrapper.find('[title="Delayed for 10 minutes"]').exists()).toBe(true);
});
