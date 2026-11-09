/// <reference types="node" />

import { webcrypto } from 'node:crypto';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import {
    createIdentityBackup,
    decryptAttachment,
    decryptIdentityBackup,
    decryptMessageBody,
    encryptAttachment,
    encryptMessageBody,
    generateConversationKey,
    generateIdentity,
    importIdentity,
} from './e2ee';

beforeAll(() => {
    vi.stubGlobal('crypto', webcrypto);
});

describe('end-to-end encryption primitives', () => {
    it('round trips an authenticated message body', async () => {
        const key = await generateConversationKey();
        const encrypted = await encryptMessageBody(
            'conversation-1',
            'message-1',
            1,
            key,
            'secret message',
        );

        await expect(decryptMessageBody(encrypted, key)).resolves.toBe(
            'secret message',
        );
    });

    it('rejects a message whose authenticated ciphertext is changed', async () => {
        const key = await generateConversationKey();
        const encrypted = await encryptMessageBody(
            'conversation-1',
            'message-1',
            1,
            key,
            'secret message',
        );
        const tampered = { ...encrypted, aad: 'conversation-2:message-1:1' };

        await expect(decryptMessageBody(tampered, key)).rejects.toThrow();
    });

    it('round trips encrypted attachment bytes', async () => {
        const key = await generateConversationKey();
        const source = new Blob(['private attachment']);
        const encrypted = await encryptAttachment(key, source);
        const restored = await decryptAttachment(
            key,
            encrypted.iv,
            encrypted.keyIv,
            encrypted.keyCiphertext,
            await encrypted.blob.arrayBuffer(),
            'text/plain',
        );

        await expect(restored.text()).resolves.toBe('private attachment');
    });

    it('rejects a backup opened with the wrong passphrase', async () => {
        const identity = await generateIdentity('user-1');
        const backup = await createIdentityBackup(identity, 'correct passphrase');

        await expect(
            decryptIdentityBackup(backup, 'wrong passphrase'),
        ).rejects.toThrow();
    });

    it('wraps and unwraps a conversation key for the registered identity', async () => {
        const { wrapConversationKey, unwrapConversationKey } = await import('./e2ee');
        const identity = await generateIdentity('user-1');
        const loaded = await importIdentity(identity);
        const conversationKey = await generateConversationKey();
        const wrap = await wrapConversationKey(
            conversationKey,
            JSON.stringify(identity.publicKeyJwk),
            'conversation:conversation-1:version:1:recipient:user-1',
        );
        const restored = await unwrapConversationKey(
            wrap,
            loaded,
            'conversation:conversation-1:version:1:recipient:user-1',
        );
        await expect(
            unwrapConversationKey(
                wrap,
                loaded,
                'conversation:another-chat:version:1:recipient:user-1',
            ),
        ).rejects.toThrow();
        const encrypted = await encryptMessageBody(
            'conversation-1',
            'message-1',
            1,
            restored,
            'wrapped key works',
        );

        await expect(decryptMessageBody(encrypted, conversationKey)).resolves.toBe(
            'wrapped key works',
        );
    });
});
