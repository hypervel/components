import { mount } from '@vue/test-utils';
import { expect, it } from 'vitest';
import { h } from 'vue';
import Preview from './preview.vue';

function mountPreview(addresses) {
    const entry = { content: { mailable: 'Welcome', subject: 'Hello', from: {}, to: {}, ...addresses } };

    return mount(Preview, {
        global: {
            mocks: { $route: { params: { id: 'mail-id' } }, Telescope: { basePath: '/telescope' } },
            stubs: {
                'preview-screen': {
                    render() {
                        return h('div', [
                            h('table', h('tbody', this.$slots['table-parameters']({ entry }))),
                            this.$slots['after-attributes-card']({ entry }),
                        ]);
                    },
                },
            },
        },
    });
}

it('renders recorded Reply-To, CC and BCC addresses without changing mail links', () => {
    const wrapper = mountPreview({
        replyTo: { 'reply@example.test': 'Reply' },
        cc: { 'cc@example.test': '' },
        bcc: { 'bcc@example.test': 'Hidden' },
    });
    const rows = Object.fromEntries(wrapper.findAll('tr').map(row => row.findAll('td').map(cell => cell.text())));

    expect(rows['Reply-To']).toBe('<Reply> reply@example.test');
    expect(rows.CC).toBe('cc@example.test');
    expect(rows.BCC).toBe('<Hidden> bcc@example.test');
    expect(wrapper.find('a').attributes('href')).toBe('/telescope/telescope-api/mail/mail-id/download');
    expect(wrapper.find('iframe').attributes('src')).toBe('/telescope/telescope-api/mail/mail-id/preview');
    wrapper.unmount();
});

it.each([[], {}])('omits empty address collections represented by %j', empty => {
    const wrapper = mountPreview({ replyTo: empty, cc: empty, bcc: empty });
    const labels = wrapper.findAll('tr td:first-child').map(cell => cell.text());

    expect(labels).not.toContain('Reply-To');
    expect(labels).not.toContain('CC');
    expect(labels).not.toContain('BCC');
    wrapper.unmount();
});
