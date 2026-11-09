import { onBeforeUnmount, ref, watch, type Ref } from 'vue';
import { useE2eeStore } from '@/stores/e2ee';
import type { MessageAttachment } from '@/types';

/**
 * Resolves attachment sources. Plaintext attachments keep using the signed
 * download URL; encrypted attachments are fetched as ciphertext, decrypted in
 * the browser, and exposed as short-lived object URLs.
 */
export function useAttachmentUrls(
    attachments: Ref<MessageAttachment[]>,
    conversationId: Ref<string | null>,
) {
    const e2ee = useE2eeStore();
    const urls = ref<Record<string, string>>({});
    const failed = ref<Record<string, boolean>>({});

    const objectUrls = new Set<string>();
    const pending = new Set<string>();

    function revokeAll(): void {
        objectUrls.forEach((url) => URL.revokeObjectURL(url));
        objectUrls.clear();
    }

    async function resolveOne(attachment: MessageAttachment): Promise<void> {
        if (
            attachment.is_encrypted !== true ||
            attachment.url === null ||
            conversationId.value === null ||
            urls.value[attachment.id] !== undefined ||
            pending.has(attachment.id)
        ) {
            return;
        }

        pending.add(attachment.id);

        try {
            const response = await fetch(attachment.url, {
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Unable to download the attachment.');
            }

            const ciphertext = await response.arrayBuffer();
            const blob = await e2ee.decryptAttachment(
                conversationId.value,
                attachment,
                ciphertext,
            );
            const objectUrl = URL.createObjectURL(blob);

            objectUrls.add(objectUrl);
            urls.value = { ...urls.value, [attachment.id]: objectUrl };
        } catch {
            failed.value = { ...failed.value, [attachment.id]: true };
        } finally {
            pending.delete(attachment.id);
        }
    }

    function resolveAll(): void {
        revokeAll();
        urls.value = {};
        failed.value = {};

        for (const attachment of attachments.value) {
            void resolveOne(attachment);
        }
    }

    watch([attachments, conversationId], resolveAll, {
        immediate: true,
        deep: true,
    });

    onBeforeUnmount(revokeAll);

    function source(
        attachment: MessageAttachment,
        preferThumbnail = true,
    ): string | null {
        if (attachment.is_encrypted === true) {
            return urls.value[attachment.id] ?? null;
        }

        return preferThumbnail
            ? (attachment.thumbnail_url ?? attachment.url)
            : attachment.url;
    }

    function href(attachment: MessageAttachment): string | null {
        if (attachment.is_encrypted === true) {
            return urls.value[attachment.id] ?? null;
        }

        return attachment.url;
    }

    function isFailed(attachment: MessageAttachment): boolean {
        return (
            attachment.is_encrypted === true &&
            failed.value[attachment.id] === true
        );
    }

    return { source, href, isFailed };
}
