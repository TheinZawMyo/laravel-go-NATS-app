import { mount } from '@vue/test-utils';
import { createSSRApp, defineComponent, h, nextTick } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import { useMounted } from './useMounted';

/**
 * The probe renders the flag directly so a test can tell the server render and
 * the client's first render apart from the post-mount update.
 */
const Probe = defineComponent({
    setup() {
        const mounted = useMounted();

        return () => h('span', mounted.value ? 'mounted' : 'pending');
    },
});

describe('useMounted', () => {
    it('stays false on the server so both sides render the same markup', async () => {
        const html = await renderToString(createSSRApp(Probe));

        expect(html).toContain('pending');
        expect(html).not.toContain('mounted');
    });

    it('flips to true only after the client mounts', async () => {
        const wrapper = mount(Probe);

        expect(wrapper.text()).toBe('pending');

        await nextTick();

        expect(wrapper.text()).toBe('mounted');

        wrapper.unmount();
    });
});
