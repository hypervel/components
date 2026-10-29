import { flushPromises } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';

const mounted = vi.hoisted(() => ({ app: null, root: null }));

vi.mock('axios', () => ({
    default: { post: vi.fn(), delete: vi.fn(), defaults: { headers: { common: {} } } },
}));
vi.mock('./routes', () => ({ default: [{ path: '/', component: { render: () => null } }] }));
vi.mock('vue/dist/vue.esm-bundler.js', async (importOriginal) => {
    const vue = await importOriginal();

    return {
        ...vue,
        createApp(...parameters) {
            mounted.app = vue.createApp(...parameters);
            const mount = mounted.app.mount.bind(mounted.app);
            mounted.app.mount = (...parameters) => {
                mounted.root = mount(...parameters);

                return mounted.root;
            };

            return mounted.app;
        },
    };
});

beforeEach(async () => {
    vi.resetModules();
    axios.post.mockReset();
    axios.delete.mockReset();
    vi.stubGlobal('Telescope', { path: 'telescope', timezone: 'UTC', recording: true });
    window.history.replaceState(null, '', '/telescope');
    document.body.innerHTML = '<div id="telescope"></div>';
    await import('./app');
    await flushPromises();
});

afterEach(() => {
    mounted.app?.unmount();
    mounted.app = null;
    mounted.root = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    window.history.replaceState(null, '', '/');
});

it('changes recording only after confirmation and prevents overlapping toggles', async () => {
    const response = Promise.withResolvers();
    axios.post.mockReturnValue(response.promise);
    const pending = mounted.root.toggleRecording();
    mounted.root.toggleRecording();

    expect(axios.post).toHaveBeenCalledExactlyOnceWith('/telescope/telescope-api/toggle-recording');
    expect(mounted.root.recording).toBe(true);

    response.resolve({ data: { success: true } });
    await pending;
    expect(mounted.root.recording).toBe(false);
});

it('reports an unconfirmed recording change and permits a later request', async () => {
    axios.post.mockRejectedValueOnce(new Error('Session expired')).mockResolvedValue({ data: { success: true } });
    await mounted.root.toggleRecording();

    expect(mounted.root.recording).toBe(true);
    expect(mounted.root.alert.type).toBe('error');
    expect(mounted.root.alert.message).toContain('Reload the page');

    await mounted.root.toggleRecording();
    expect(axios.post).toHaveBeenCalledTimes(2);
    expect(mounted.root.recording).toBe(false);
});

it('reports an unconfirmed clear without reloading', async () => {
    const reload = vi.fn();
    vi.stubGlobal('location', { reload });
    axios.delete.mockRejectedValue(new Error('Session expired'));
    await mounted.root.clearEntries(false);

    expect(axios.delete).toHaveBeenCalledExactlyOnceWith('/telescope/telescope-api/entries');
    expect(reload).not.toHaveBeenCalled();
    expect(mounted.root.alert.type).toBe('error');
    expect(mounted.root.alert.message).toContain('entries were cleared');
});
