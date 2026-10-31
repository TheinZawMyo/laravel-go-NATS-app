import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChatMessage } from '@/types';
import { useChatStore } from '@/stores/chat';
import MessageTimeline from './MessageTimeline.vue';

vi.mock('@/components/chat/MessageBubble.vue', () => ({
    default: {
        props: ['message'],
        template: '<div class="bubble">{{ message.body }}</div>',
    },
}));

const other = {
    id: 'user-2',
    name: 'Alice',
    username: 'alice',
    avatar_url: null,
    bio: null,
    status_text: null,
    last_seen_at: null,
};

function message(seq: number, body: string): ChatMessage {
    return {
        id: `message-${seq}`,
        conversation_id: 'conversation-1',
        seq,
        client_message_id: `client-${seq}`,
        sender: other,
        type: 'text',
        body,
        reply_to: null,
        edited_at: null,
        deleted_at: null,
        created_at: '2026-10-29T00:00:00Z',
        updated_at: '2026-10-29T00:00:00Z',
        status: 'sent',
        error: null,
    };
}

async function flush(): Promise<void> {
    for (let index = 0; index < 4; index += 1) {
        await nextTick();
    }
}

function stubLayout(): void {
    Object.defineProperty(HTMLElement.prototype, 'clientHeight', {
        configurable: true,
        get: () => 800,
    });
    Object.defineProperty(HTMLElement.prototype, 'clientWidth', {
        configurable: true,
        get: () => 600,
    });
    Object.defineProperty(HTMLElement.prototype, 'scrollHeight', {
        configurable: true,
        get: () => 800,
    });
}

describe('MessageTimeline', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        stubLayout();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders messages appended after mount', async () => {
        const chat = useChatStore();

        chat.messages = [
            message(1, 'first message'),
            message(2, 'second message'),
        ];

        const wrapper = mount(MessageTimeline, { attachTo: document.body });
        await flush();

        expect(wrapper.text()).toContain('second message');

        chat.messages = [...chat.messages, message(3, 'third message')];
        await flush();

        expect(wrapper.text()).toContain('third message');
        expect(wrapper.text()).toContain('first message');

        wrapper.unmount();
    });

    it('renders messages that arrive after an empty mount', async () => {
        const chat = useChatStore();

        chat.messages = [];

        const wrapper = mount(MessageTimeline, { attachTo: document.body });
        await flush();

        expect(wrapper.text()).toContain('No messages yet');

        chat.messages = [message(1, 'loaded later')];
        await flush();

        expect(wrapper.text()).toContain('loaded later');

        wrapper.unmount();
    });
});
