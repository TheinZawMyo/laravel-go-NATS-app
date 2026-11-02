import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ConversationList from './ConversationList.vue';

vi.mock('@/composables/useAuth', () => ({
    useAuth: () => ({ user: ref(null) }),
}));

/**
 * The header widgets drag in dialogs, radix primitives and stores that have
 * nothing to do with the notification bell, so they are stubbed out: the test
 * only cares about what the very first render puts in the DOM.
 */
const stubs = {
    ConnectionStatus: true,
    CreateGroupDialog: true,
    NewConversationDialog: true,
    PeopleDialog: true,
    UserMenuContent: true,
};

const notificationButton = 'button[aria-label="Enable desktop notifications"]';

describe('ConversationList hydration', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.stubGlobal('Notification', { permission: 'default' });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    /**
     * Server rendering has no `window`, so the server never emits the bell. The
     * client's first render has to agree, otherwise Vue logs
     * "Hydration node mismatch: rendered on server <!---->, expected on client
     * <button>" and rebuilds the header.
     */
    it('renders the notification bell only after mount', async () => {
        const wrapper = mount(ConversationList, { global: { stubs } });

        expect(wrapper.find(notificationButton).exists()).toBe(false);

        await nextTick();

        expect(wrapper.find(notificationButton).exists()).toBe(true);

        wrapper.unmount();
    });
});
