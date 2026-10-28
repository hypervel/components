import { mount } from '@vue/test-utils';
import { expect, it } from 'vitest';
import { h } from 'vue';
import Preview from './preview.vue';

function mountPreview(content) {
    const entry = { content: { type: 'hit', key: 'example', ...content } };

    return mount(Preview, {
        global: {
            mocks: { $route: { params: { id: 'cache-id' } } },
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

it.each([0, false, '', null])('displays a recorded cache value of %j', value => {
    const wrapper = mountPreview({ value });

    expect(wrapper.find('pre').exists()).toBe(true);
    expect(wrapper.find('pre').text()).toBe(String(value));
    wrapper.unmount();
});

it.each(['missed', 'forget'])('omits the value card for a %s entry without a value', type => {
    const wrapper = mountPreview({ type });

    expect(wrapper.find('pre').exists()).toBe(false);
    wrapper.unmount();
});
