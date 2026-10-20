import { shallowMount } from '@vue/test-utils';
import { format } from 'sql-formatter';
import { describe, expect, it } from 'vitest';
import Preview from './preview.vue';

/**
 * Mount the query preview with a fake entry, so no request is sent.
 *
 * The preview screen fetches the entry over HTTP and exposes it to the
 * scoped slots of its children. Both global components are replaced here:
 * the screen stub renders the slots with a fixed entry, and the clipboard
 * stub renders its default slot while exposing the copied value.
 */
function mountPreview(content) {
    const entry = {
        created_at: '2026-10-13T12:00:00.000000Z',
        tags: [],
        content: { connection: 'mysql', time: 1.5, ...content },
    };

    const PreviewScreenStub = {
        name: 'preview-screen',
        props: ['resource', 'title', 'id'],
        render(h) {
            const slot = (name) => {
                const scoped = this.$scopedSlots[name];

                return scoped ? scoped({ entry }) : [];
            };

            return h('div', [].concat(slot('table-parameters'), slot('after-attributes-card')));
        },
    };

    const CopyClipboardStub = {
        name: 'copy-clipboard',
        props: ['data'],
        render(h) {
            return h('div', { class: 'copy-clipboard-stub' }, this.$slots.default);
        },
    };

    return shallowMount(Preview, {
        mocks: { $route: { params: { id: 'entry-id' } } },
        stubs: {
            'preview-screen': PreviewScreenStub,
            'copy-clipboard': CopyClipboardStub,
        },
    });
}

const query = 'select * from products where id = 1';

describe('query preview', () => {
    // REMOVED: the MongoDB JSON printing, json grammar and type wrapper tests; Hypervel does not support MongoDB.

    it('formats a SQL query with the dialect matching the connection', () => {
        const wrapper = mountPreview({ driver: 'sqlite', sql: 'select * from products where name like "%a%"' });

        expect(wrapper.find('pre').classes()).toContain('language-sql');
        expect(wrapper.find('pre').text()).toContain('products');
        expect(wrapper.find('pre').text()).toMatch(/select/i);
    });

    it('resolves the dialect of an aliased driver', () => {
        const wrapper = mountPreview({ driver: 'pgsql', sql: "select * from products where tags @> array['new']" });

        // sqlDialect() receives an already aliased dialect, the aliases are
        // applied by formatQuery().
        expect(wrapper.vm.sqlDialect('postgresql')).toBe('postgresql');
        expect(wrapper.vm.sqlDialect('transactsql')).toBe('transactsql');
        expect(wrapper.vm.sqlDialect('pgsql')).toBeNull();
        expect(wrapper.vm.sqlDialect()).toBe('sql');
    });

    // REMOVED: the highlight language test; without JSON commands, every query highlights as SQL.

    it('formats a PostgreSQL query without breaking its operators', () => {
        const wrapper = mountPreview({ driver: 'pgsql', sql: "select * from products where tags @> array['new']" });

        expect(wrapper.find('pre').text()).toContain('@>');
    });

    it('returns a query of an unknown driver untouched', () => {
        const wrapper = mountPreview({ driver: 'weird', sql: 'not a known dialect' });

        expect(wrapper.find('pre').text()).toBe('not a known dialect');
    });

    it('falls back to the generic SQL dialect when no driver is recorded', () => {
        const wrapper = mountPreview({ driver: undefined, sql: 'select 1' });

        expect(wrapper.find('pre').classes()).toContain('language-sql');
        expect(wrapper.find('pre').text()).toContain('1');
    });

    it('copies the formatted query, not the raw one', () => {
        const wrapper = mountPreview({ driver: 'mysql', sql: query });

        expect(wrapper.findComponent({ name: 'copy-clipboard' }).props('data')).toBe(
            format(query, { language: 'mysql' })
        );
    });

    it('highlights the query when the entry is ready', async () => {
        const wrapper = mountPreview({ driver: 'mysql', sql: query });

        wrapper.findComponent({ name: 'preview-screen' }).vm.$emit('ready');
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        const pre = wrapper.find('pre');

        expect(pre.classes()).toContain('hljs');
        expect(pre.html()).toContain('hljs-keyword');
    });
});
