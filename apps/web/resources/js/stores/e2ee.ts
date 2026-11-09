import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { ApiError, apiRequest } from '@/lib/http';
import {
    clearStoredIdentity,
    createIdentityBackup,
    decryptAttachment as decryptAttachmentBytes,
    decryptIdentityBackup,
    decryptMessageBody,
    encryptAttachment as encryptAttachmentBytes,
    encryptMessageBody,
    fingerprintOf,
    generateConversationKey,
    generateIdentity,
    importIdentity,
    isSupported,
    keyStateFor,
    loadStoredIdentity,
    publicKeyString,
    saveStoredIdentity,
    unwrapConversationKey,
    wrapConversationKey,
    type LoadedIdentity,
    type StoredIdentity,
} from '@/lib/e2ee';
import type {
    Conversation,
    E2eeConversationState,
    E2eeEncryption,
    E2eeKeyBackup,
    MessageAttachment,
} from '@/types';

export type E2eeStatus = 'idle' | 'ready' | 'locked' | 'unsupported';

type IdentityResponse = {
    user_id: string;
    public_key: string;
    algorithm: string;
    fingerprint: string;
};

export const useE2eeStore = defineStore('e2ee', () => {
    const status = ref<E2eeStatus>('idle');
    const identity = ref<LoadedIdentity | null>(null);
    const userId = ref<string | null>(null);
    const error = ref<string | null>(null);

    const keys = new Map<string, Map<number, CryptoKey>>();
    const states = new Map<string, E2eeConversationState>();

    const ready = computed(() => status.value === 'ready');
    const locked = computed(() => status.value === 'locked');

    async function bootstrap(activeUserId: string): Promise<void> {
        if (userId.value === activeUserId && status.value !== 'idle') {
            return;
        }

        if (userId.value !== null && userId.value !== activeUserId) {
            identity.value = null;
            keys.clear();
            states.clear();
            status.value = 'idle';
        }

        userId.value = activeUserId;
        error.value = null;

        if (!isSupported()) {
            status.value = 'unsupported';

            return;
        }

        const stored = await loadStoredIdentity(activeUserId);
        const response = await apiRequest<{ data: IdentityResponse | null }>(
            '/api/v1/e2ee/keys',
        );

        if (stored !== null) {
            const localFingerprint = await fingerprintOf(stored.publicKeyJwk);
            let registeredFingerprint: string | null = null;

            try {
                registeredFingerprint =
                    response.data === null
                        ? null
                        : await fingerprintOf(
                              JSON.parse(response.data.public_key) as JsonWebKey,
                          );
            } catch {
                registeredFingerprint = null;
            }

            if (
                response.data === null ||
                registeredFingerprint !== localFingerprint ||
                response.data.fingerprint !== localFingerprint ||
                stored.fingerprint !== localFingerprint
            ) {
                status.value = 'locked';
                error.value = 'The saved encryption identity does not match the registered account key.';

                return;
            }

            identity.value = await importIdentity(stored);
            status.value = 'ready';

            return;
        }

        if (response.data === null) {
            await createIdentity(activeUserId);

            return;
        }

        status.value = 'locked';
    }

    async function createIdentity(activeUserId: string): Promise<void> {
        const generated = await generateIdentity(activeUserId);
        const publicKey = publicKeyString(generated);

        const response = await apiRequest<{ data: IdentityResponse }>('/api/v1/e2ee/keys', {
            method: 'POST',
            body: {
                public_key: publicKey,
                algorithm: 'ECDH-P256',
                fingerprint: generated.fingerprint,
            },
        });

        const registeredFingerprint = await fingerprintOf(
            JSON.parse(response.data.public_key) as JsonWebKey,
        );
        if (
            registeredFingerprint !== generated.fingerprint ||
            response.data.fingerprint !== generated.fingerprint
        ) {
            throw new Error('The server registered a different encryption identity.');
        }

        await saveStoredIdentity(generated);
        identity.value = await importIdentity(generated);
        status.value = 'ready';
    }

    async function unlockWithPassphrase(
        activeUserId: string,
        passphrase: string,
    ): Promise<void> {
        const response = await apiRequest<{ data: E2eeKeyBackup | null }>(
            '/api/v1/e2ee/backup',
        );

        if (response.data === null) {
            throw new ApiError(
                'No encryption backup exists for this account.',
                404,
            );
        }

        const restored = await decryptIdentityBackup(
            response.data,
            passphrase,
        );
        const registered = await apiRequest<{ data: IdentityResponse | null }>(
            '/api/v1/e2ee/keys',
        );
        const restoredFingerprint = await fingerprintOf(restored.publicKeyJwk);
        let registeredFingerprint: string | null = null;

        try {
            registeredFingerprint =
                registered.data === null
                    ? null
                    : await fingerprintOf(
                          JSON.parse(registered.data.public_key) as JsonWebKey,
                      );
        } catch {
            registeredFingerprint = null;
        }

        if (
            registered.data === null ||
            registeredFingerprint !== restoredFingerprint ||
            restored.fingerprint !== restoredFingerprint ||
            registered.data.fingerprint !== restoredFingerprint ||
            response.data.fingerprint !== restoredFingerprint
        ) {
            throw new Error('The backup key does not match the registered account identity.');
        }
        const stored: StoredIdentity = {
            ...restored,
            userId: activeUserId,
        };

        await saveStoredIdentity(stored);
        identity.value = await importIdentity(stored);
        status.value = 'ready';
        error.value = null;
    }

    async function backupIdentity(passphrase: string): Promise<void> {
        const current = identity.value?.stored;

        if (current === undefined) {
            throw new Error('Encryption is not unlocked on this device.');
        }

        const backup = await createIdentityBackup(current, passphrase);

        await apiRequest('/api/v1/e2ee/backup', {
            method: 'PUT',
            body: backup,
        });
    }

    async function forgetDevice(): Promise<void> {
        if (userId.value !== null) {
            await clearStoredIdentity(userId.value);
        }
        identity.value = null;
        keys.clear();
        states.clear();
        status.value = 'locked';
    }

    async function fetchState(
        conversationId: string,
        force = false,
    ): Promise<E2eeConversationState> {
        const cached = states.get(conversationId);

        if (!force && cached !== undefined) {
            return cached;
        }

        const response = await apiRequest<{ data: E2eeConversationState }>(
            `/api/v1/conversations/${conversationId}/e2ee`,
        );

        states.set(conversationId, response.data);

        return response.data;
    }

    async function conversationKey(
        conversationId: string,
        keyVersion: number,
    ): Promise<CryptoKey> {
        if (identity.value === null) {
            throw new ApiError(
                'Encryption is locked on this device. Unlock it to read this conversation.',
                423,
            );
        }

        const cached = keys.get(conversationId)?.get(keyVersion);

        if (cached !== undefined) {
            return cached;
        }

        const state = await fetchState(conversationId, true);
        const keyState = keyStateFor(state.keys, keyVersion);

        if (keyState?.wrap === null || keyState?.wrap === undefined) {
            throw new ApiError(
                'This device does not have the key for this conversation.',
                403,
            );
        }

        const key = await unwrapConversationKey(
            keyState.wrap,
            identity.value,
            keyWrapContext(conversationId, keyVersion, identity.value.stored.userId),
        );

        if (!keys.has(conversationId)) {
            keys.set(conversationId, new Map());
        }

        keys.get(conversationId)?.set(keyVersion, key);

        return key;
    }

    async function encryptForMessage(
        conversationId: string,
        clientMessageId: string,
        plaintext: string,
    ): Promise<E2eeEncryption> {
        const state = await fetchState(conversationId, true);

        if (!state.enabled || state.key_version === null) {
            throw new Error('This conversation is not end-to-end encrypted.');
        }

        const key = await conversationKey(conversationId, state.key_version);

        return encryptMessageBody(
            conversationId,
            clientMessageId,
            state.key_version,
            key,
            plaintext,
        );
    }

    async function encryptForEdit(
        conversationId: string,
        messageId: string,
        plaintext: string,
    ): Promise<E2eeEncryption> {
        const state = await fetchState(conversationId, true);

        if (!state.enabled || state.key_version === null) {
            throw new Error('This conversation is not end-to-end encrypted.');
        }

        const key = await conversationKey(conversationId, state.key_version);

        return encryptMessageBody(
            conversationId,
            messageId,
            state.key_version,
            key,
            plaintext,
        );
    }

    async function decryptMessage(
        conversationId: string,
        encryption: E2eeEncryption,
    ): Promise<string> {
        const key = await conversationKey(
            conversationId,
            encryption.key_version,
        );

        return decryptMessageBody(encryption, key);
    }

    async function enableConversation(
        conversation: Conversation,
    ): Promise<E2eeConversationState> {
        const state = await fetchState(conversation.id, true);

        if (state.enabled) {
            return state;
        }

        return publishNewKey(conversation, state);
    }

    /**
     * A member who joins after a key version exists has no wrap for it. Rather
     * than reusing a key the new member never had, the client rotates to the
     * next version and wraps it for everyone currently active.
     */
    async function ensureConversationReady(
        conversation: Conversation,
    ): Promise<void> {
        const state = await fetchState(conversation.id, true);

        if (!state.enabled) {
            return;
        }

        const current = state.key_version;

        if (current !== null && !state.rotation_required) {
            const keyState = keyStateFor(state.keys, current);

            if (keyState?.wrap !== null && keyState?.wrap !== undefined) {
                await conversationKey(conversation.id, current);

                return;
            }
        }

        await publishNewKey(conversation, state);
    }

    async function publishNewKey(
        conversation: Conversation,
        state: E2eeConversationState,
    ): Promise<E2eeConversationState> {
        if (identity.value === null) {
            throw new ApiError(
                'Encryption is locked on this device. Unlock it to enable encryption.',
                423,
            );
        }

        const missing = state.member_keys.filter(
            (member) => member.public_key === null,
        );

        if (missing.length > 0) {
            throw new ApiError(
                'Every member must open the app once to set up encryption before it can be enabled.',
                422,
            );
        }

        const keyVersion = (state.key_version ?? 0) + 1;
        const conversationKeyValue = await generateConversationKey();
        const wraps = await Promise.all(
            state.member_keys.map(async (member) => {
                if (member.public_key === null || member.fingerprint === null) {
                    throw new Error('A conversation member has no verified encryption identity.');
                }

                const memberFingerprint = await fingerprintOf(
                    JSON.parse(member.public_key) as JsonWebKey,
                );
                if (memberFingerprint !== member.fingerprint) {
                    throw new Error('A conversation member encryption key fingerprint is invalid.');
                }

                return {
                    recipient_user_id: member.user_id,
                    ...(await wrapConversationKey(
                        conversationKeyValue,
                        member.public_key,
                        keyWrapContext(conversation.id, keyVersion, member.user_id),
                    )),
                };
            }),
        );

        const response = await apiRequest<{ data: E2eeConversationState }>(
            `/api/v1/conversations/${conversation.id}/e2ee/keys`,
            {
                method: 'POST',
                body: {
                    key_version: keyVersion,
                    wraps,
                },
            },
        );

        states.set(conversation.id, response.data);

        if (!keys.has(conversation.id)) {
            keys.set(conversation.id, new Map());
        }

        keys.get(conversation.id)?.set(keyVersion, conversationKeyValue);

        return response.data;
    }

    async function encryptAttachment(
        conversationId: string,
        keyVersion: number,
        file: File | Blob,
    ): Promise<{
        blob: Blob;
        iv: string;
        keyIv: string;
        keyCiphertext: string;
    }> {
        const key = await conversationKey(conversationId, keyVersion);

        return encryptAttachmentBytes(key, file);
    }

    async function decryptAttachment(
        conversationId: string,
        attachment: MessageAttachment,
        ciphertext: ArrayBuffer,
    ): Promise<Blob> {
        if (
            attachment.key_version === null ||
            attachment.key_iv === null ||
            attachment.key_ciphertext === null ||
            attachment.iv === null
        ) {
            throw new Error('This attachment is missing its encryption key.');
        }

        const key = await conversationKey(
            conversationId,
            attachment.key_version,
        );

        return decryptAttachmentBytes(
            key,
            attachment.iv,
            attachment.key_iv,
            attachment.key_ciphertext,
            ciphertext,
            attachment.mime_type,
        );
    }

    function invalidate(conversationId?: string): void {
        if (conversationId === undefined) {
            states.clear();
            keys.clear();

            return;
        }

        states.delete(conversationId);
        keys.delete(conversationId);
    }

    function keyWrapContext(
        conversationId: string,
        keyVersion: number,
        recipientUserId: string,
    ): string {
        return `conversation:${conversationId}:version:${keyVersion}:recipient:${recipientUserId}`;
    }

    return {
        status,
        identity,
        error,
        ready,
        locked,
        bootstrap,
        createIdentity,
        unlockWithPassphrase,
        backupIdentity,
        forgetDevice,
        fetchState,
        conversationKey,
        encryptForMessage,
        encryptForEdit,
        decryptMessage,
        enableConversation,
        ensureConversationReady,
        encryptAttachment,
        decryptAttachment,
        invalidate,
    };
});
