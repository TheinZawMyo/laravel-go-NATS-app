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

function message(seq: number, body: string, createdAt?: Date): ChatMessage {
    const timestamp = (
        createdAt ?? new Date('2026-10-29T00:00:00Z')
    ).toISOString();

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
        created_at: timestamp,
        updated_at: timestamp,
        status: 'sent',
        error: null,
    };
}

/**
 * Local wall clock dates keep the assertions timezone independent: the day a
 * message lands on is what decides its divider.
 */
function localDay(daysAgo: number, hour: number): Date {
    const now = new Date();

    return new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate() - daysAgo,
        hour,
    );
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

    it('adds a new divider when a message arrives on a new day', async () => {
        const chat = useChatStore();

        chat.messages = [message(1, 'last night', localDay(1, 23))];

        const wrapper = mount(MessageTimeline, { attachTo: document.body });
        await flush();

        expect(wrapper.findAll('[role="separator"]')).toHaveLength(1);

        chat.messages = [
            ...chat.messages,
            message(2, 'this morning', localDay(0, 9)),
        ];
        await flush();

        const separators = wrapper.findAll('[role="separator"]');

        expect(separators).toHaveLength(2);
        expect(separators[0]?.text()).toBe('Yesterday');
        expect(separators[1]?.text()).toBe('Today');
        expect(wrapper.text()).toContain('this morning');

        wrapper.unmount();
    });

    it('groups messages under one divider per calendar day', async () => {
        const chat = useChatStore();

        chat.messages = [
            message(1, 'yesterday message', localDay(1, 18)),
            message(2, 'today morning', localDay(0, 9)),
            message(3, 'today evening', localDay(0, 18)),
        ];

        const wrapper = mount(MessageTimeline, { attachTo: document.body });
        await flush();

        const separators = wrapper.findAll('[role="separator"]');

        expect(separators).toHaveLength(2);
        expect(separators[0]?.text()).toBe('Yesterday');
        expect(separators[1]?.text()).toBe('Today');
        expect(wrapper.text()).toContain('yesterday message');
        expect(wrapper.text()).toContain('today evening');

        wrapper.unmount();
    });
});
