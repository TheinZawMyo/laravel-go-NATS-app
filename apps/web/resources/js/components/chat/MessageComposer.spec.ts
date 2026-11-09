import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Conversation, MessageAttachment } from '@/types';
import { uploadFile } from '@/lib/upload';
import { useChatStore } from '@/stores/chat';
import MessageComposer from './MessageComposer.vue';

vi.mock('@/lib/upload', () => ({
    MAX_UPLOAD_BYTES: 25 * 1024 * 1024,
    uploadFile: vi.fn(),
}));

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

const viewer = {
    id: 'user-1',
    name: 'Test User',
    username: 'testuser',
    avatar_url: null,
    bio: null,
    status_text: null,
    last_seen_at: null,
};

const conversation: Conversation = {
    id: 'conversation-1',
    type: 'direct',
    title: null,
    description: null,
    avatar_url: null,
    unread_count: 0,
    last_read_seq: 0,
    next_message_seq: 0,
    viewer_role: 'member',
    history_visible_to_new_members: false,
    e2ee_enabled: false,
    e2ee_key_version: null,
    e2ee_rotation_required: false,
    muted_until: null,
    is_muted: false,
    archived: false,
    participant: viewer,
    last_message: null,
    members: [{ role: 'member', last_read_seq: 0, user: viewer }],
    created_at: '2026-10-29T00:00:00Z',
    updated_at: '2026-10-29T00:00:00Z',
};

function attachment(): MessageAttachment {
    return {
        id: 'attachment-1',
        message_id: null,
        name: 'report.pdf',
        mime_type: 'application/pdf',
        is_encrypted: false,
        key_version: null,
        iv: null,
        key_iv: null,
        key_ciphertext: null,
        kind: 'file',
        size_bytes: 2048,
        width: null,
        height: null,
        duration_ms: null,
        url: '/api/v1/messages/message-1/attachments/attachment-1',
        thumbnail_url: null,
        created_at: '2026-11-02T00:00:00Z',
    };
}

function deferred<T>(): {
    promise: Promise<T>;
    resolve: (value: T) => void;
    reject: (reason: unknown) => void;
} {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

/**
 * jsdom cannot set `input.files` from user input, so the picker result is
 * defined directly before dispatching the change event the composer listens to.
 */
async function pickFile(wrapper: VueWrapper, file: File): Promise<void> {
    const input = wrapper.get('input[type="file"]');

    Object.defineProperty(input.element, 'files', {
        value: [file],
        configurable: true,
    });

    await input.trigger('change');
}

const pdf = (): File =>
    new File(['%PDF-1.4'], 'report.pdf', { type: 'application/pdf' });

describe('MessageComposer uploads', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.mocked(uploadFile).mockReset();

        useChatStore().initialize({
            conversations: [conversation],
            activeConversation: conversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });
    });

    it('paints upload progress and releases the send button when the upload lands', async () => {
        const gate = deferred<MessageAttachment>();
        let report: ((percent: number) => void) | undefined;

        vi.mocked(uploadFile).mockImplementation((_file, onProgress) => {
            report = onProgress;

            return gate.promise;
        });

        const wrapper = mount(MessageComposer);

        await pickFile(wrapper, pdf());

        // An in-flight upload blocks the send: the button shows a spinner.
        const send = wrapper.get('[aria-label="Send message"]');
        expect(send.attributes('disabled')).toBeDefined();
        expect(send.find('.animate-spin').exists()).toBe(true);
        expect(wrapper.text()).toContain('0%');

        report?.(42);
        await nextTick();

        // Regression: mutating the pending item through a non-reactive handle
        // left this label frozen at "0%" and the spinner running forever.
        expect(wrapper.text()).toContain('42%');

        gate.resolve(attachment());
        await flushPromises();

        expect(wrapper.text()).not.toContain('42%');
        expect(wrapper.text()).toContain('8 B');
        expect(send.find('.animate-spin').exists()).toBe(false);

        // A finished upload is what makes an empty draft sendable.
        expect(send.attributes('disabled')).toBeUndefined();
    });

    it('reports a failed upload and offers a retry instead of spinning forever', async () => {
        vi.mocked(uploadFile).mockRejectedValue(
            new Error(
                'The upload failed. Check your connection and try again.',
            ),
        );

        const wrapper = mount(MessageComposer);

        await pickFile(wrapper, pdf());
        await flushPromises();

        expect(wrapper.text()).toContain('The upload failed');

        const send = wrapper.get('[aria-label="Send message"]');
        expect(send.find('.animate-spin').exists()).toBe(false);
        expect(wrapper.find('[aria-label="Retry report.pdf"]').exists()).toBe(
            true,
        );

        // A failed upload must not be smuggled into a message.
        await wrapper.get('textarea').setValue('hello');
        expect(send.attributes('disabled')).toBeDefined();
    });

    it('drops an upload the user removes', async () => {
        const gate = deferred<MessageAttachment>();

        vi.mocked(uploadFile).mockImplementation(() => gate.promise);

        const wrapper = mount(MessageComposer);

        await pickFile(wrapper, pdf());
        await wrapper.get('[aria-label="Remove report.pdf"]').trigger('click');

        expect(wrapper.text()).not.toContain('report.pdf');

        const send = wrapper.get('[aria-label="Send message"]');
        expect(send.find('.animate-spin').exists()).toBe(false);
    });
});

describe('MessageComposer scheduling', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        useChatStore().initialize({
            conversations: [conversation],
            activeConversation: conversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });
    });

    it('allows choosing a local date and time for a draft', async () => {
        const wrapper = mount(MessageComposer);
        await wrapper.get('textarea').setValue('Send this later');
        await wrapper.get('[aria-label="Schedule message"]').trigger('click');

        const picker = wrapper.get('input[type="datetime-local"]');
        expect(picker.attributes('disabled')).toBeUndefined();
        expect(picker.attributes('min')).toBeTruthy();

        await picker.setValue('2026-11-10T09:30');
        expect((picker.element as HTMLInputElement).value).toBe(
            '2026-11-10T09:30',
        );
    });

    it('explains that scheduling is unavailable in encrypted conversations', async () => {
        const encryptedConversation = { ...conversation, e2ee_enabled: true };
        useChatStore().initialize({
            conversations: [encryptedConversation],
            activeConversation: encryptedConversation,
            activeMessages: [],
            activeHasMore: false,
            activeNextCursor: null,
        });

        const wrapper = mount(MessageComposer);
        await wrapper.get('textarea').setValue('Send this later');

        const schedule = wrapper.get('[aria-label="Schedule message"]');
        expect(schedule.attributes('disabled')).toBeDefined();
        expect(schedule.attributes('title')).toContain(
            'end-to-end encrypted conversations',
        );
    });
});
