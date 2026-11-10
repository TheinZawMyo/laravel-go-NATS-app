import { createPinia } from 'pinia';
import { createSSRApp, nextTick } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import ChatNavPanel from './ChatNavPanel.vue';

describe('ChatNavPanel hydration', () => {
    it('waits until mount before teleporting into the document body', async () => {
        const serverApp = createSSRApp(ChatNavPanel);
        serverApp.use(createPinia());
        const html = await renderToString(serverApp);

        const host = document.createElement('div');
        host.innerHTML = html;
        document.body.append(host);

        // Inertia puts its page payload in the body alongside the app root.
        // A Teleport hydrated too early can mistake this script for its target
        // anchor and report a node mismatch.
        const pagePayload = document.createElement('script');
        pagePayload.dataset.page = 'app';
        document.body.append(pagePayload);

        const warnings = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const clientApp = createSSRApp(ChatNavPanel);
        clientApp.use(createPinia());
        clientApp.mount(host);
        await nextTick();

        expect(
            warnings.mock.calls.some(([message]) =>
                String(message).includes('Hydration node mismatch'),
            ),
        ).toBe(false);

        clientApp.unmount();
        host.remove();
        pagePayload.remove();
        warnings.mockRestore();
    });
});
