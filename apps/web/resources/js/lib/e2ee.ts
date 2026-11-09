import type {
    E2eeConversationKeyState,
    E2eeEncryption,
    E2eeKeyBackup,
    E2eeKeyWrap,
    E2eeMemberKey,
} from '@/types';

const LEGACY_IDENTITY_STORAGE_KEY = 'chatapp.e2ee.identity.v1';
const LEGACY_NAMESPACED_KEY_PREFIX = 'chatapp.e2ee.identity.v1.';
const IDENTITY_DATABASE = 'chatapp-e2ee-identities';
const IDENTITY_STORE = 'identities';

const encoder = new TextEncoder();
const decoder = new TextDecoder();

export type StoredIdentity = {
    userId: string;
    privateKeyJwk: JsonWebKey;
    publicKeyJwk: JsonWebKey;
    fingerprint: string;
};

export type LoadedIdentity = {
    stored: StoredIdentity;
    privateKey: CryptoKey;
    publicKey: CryptoKey;
};

type StoredIdentityRecord = {
    userId: string;
    key: CryptoKey;
    iv: string;
    ciphertext: string;
};

function subtle(): SubtleCrypto {
    if (typeof crypto === 'undefined' || crypto.subtle === undefined) {
        throw new Error('Web Crypto is not available in this browser.');
    }

    return crypto.subtle;
}

function randomBytes(length: number): Uint8Array<ArrayBuffer> {
    const bytes = new Uint8Array(new ArrayBuffer(length));
    crypto.getRandomValues(bytes);

    return bytes;
}

export function isSupported(): boolean {
    return (
        typeof crypto !== 'undefined' &&
        crypto.subtle !== undefined &&
        typeof indexedDB !== 'undefined'
    );
}

export function toBase64(buffer: ArrayBuffer | Uint8Array): string {
    const bytes =
        buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let binary = '';

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary);
}

export function fromBase64(value: string): Uint8Array<ArrayBuffer> {
    const binary = atob(value);
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

export async function fingerprintOf(publicKeyJwk: JsonWebKey): Promise<string> {
    const canonical = JSON.stringify({
        crv: publicKeyJwk.crv,
        kty: publicKeyJwk.kty,
        x: publicKeyJwk.x,
        y: publicKeyJwk.y,
    });
    const digest = await subtle().digest(
        'SHA-256',
        encoder.encode(canonical),
    );

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

export async function generateIdentity(
    userId: string,
): Promise<StoredIdentity> {
    const pair = (await subtle().generateKey(
        { name: 'ECDH', namedCurve: 'P-256' },
        true,
        ['deriveBits'],
    )) as CryptoKeyPair;

    const publicKeyJwk = await subtle().exportKey('jwk', pair.publicKey);
    const privateKeyJwk = await subtle().exportKey('jwk', pair.privateKey);

    return {
        userId,
        publicKeyJwk,
        privateKeyJwk,
        fingerprint: await fingerprintOf(publicKeyJwk),
    };
}

async function openIdentityDatabase(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(IDENTITY_DATABASE, 1);

        request.onupgradeneeded = () => {
            request.result.createObjectStore(IDENTITY_STORE, {
                keyPath: 'userId',
            });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function identityRecord(userId: string): Promise<StoredIdentityRecord | null> {
    const database = await openIdentityDatabase();

    try {
        return await new Promise((resolve, reject) => {
            const request = database
                .transaction(IDENTITY_STORE, 'readonly')
                .objectStore(IDENTITY_STORE)
                .get(userId);
            request.onsuccess = () =>
                resolve((request.result as StoredIdentityRecord | undefined) ?? null);
            request.onerror = () => reject(request.error);
        });
    } finally {
        database.close();
    }
}

export async function loadStoredIdentity(
    userId: string,
): Promise<StoredIdentity | null> {
    if (typeof indexedDB === 'undefined') {
        return null;
    }

    try {
        const record = await identityRecord(userId);

        if (record !== null) {
            const plaintext = await subtle().decrypt(
                { name: 'AES-GCM', iv: fromBase64(record.iv) },
                record.key,
                fromBase64(record.ciphertext),
            );
            const parsed = JSON.parse(decoder.decode(plaintext)) as StoredIdentity;

            return parsed.userId === userId ? parsed : null;
        }

        // Migrate the previous cleartext browser record once, then delete it.
        if (typeof localStorage === 'undefined') {
            return null;
        }

        const raw =
            localStorage.getItem(`${LEGACY_NAMESPACED_KEY_PREFIX}${userId}`) ??
            localStorage.getItem(LEGACY_IDENTITY_STORAGE_KEY);
        if (raw === null) {
            return null;
        }

        const parsed = JSON.parse(raw) as StoredIdentity;
        if (
            parsed.userId !== userId ||
            parsed.privateKeyJwk === undefined ||
            parsed.publicKeyJwk === undefined
        ) {
            return null;
        }

        await saveStoredIdentity(parsed);
        localStorage.removeItem(`${LEGACY_NAMESPACED_KEY_PREFIX}${userId}`);
        localStorage.removeItem(LEGACY_IDENTITY_STORAGE_KEY);

        return parsed;
    } catch {
        return null;
    }
}

export async function saveStoredIdentity(identity: StoredIdentity): Promise<void> {
    const database = await openIdentityDatabase();

    try {
        const current = await identityRecord(identity.userId);
        const key = current?.key ?? await subtle().generateKey(
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt', 'decrypt'],
        );
        const iv = randomBytes(12);
        const ciphertext = await subtle().encrypt(
            { name: 'AES-GCM', iv },
            key,
            encoder.encode(JSON.stringify(identity)),
        );

        await new Promise<void>((resolve, reject) => {
            const transaction = database.transaction(IDENTITY_STORE, 'readwrite');
            transaction.objectStore(IDENTITY_STORE).put({
                userId: identity.userId,
                key,
                iv: toBase64(iv),
                ciphertext: toBase64(ciphertext),
            } satisfies StoredIdentityRecord);
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
    } finally {
        database.close();
    }
}

export async function clearStoredIdentity(userId: string): Promise<void> {
    if (typeof indexedDB !== 'undefined') {
        const database = await openIdentityDatabase();
        try {
            await new Promise<void>((resolve, reject) => {
                const transaction = database.transaction(IDENTITY_STORE, 'readwrite');
                transaction.objectStore(IDENTITY_STORE).delete(userId);
                transaction.oncomplete = () => resolve();
                transaction.onerror = () => reject(transaction.error);
                transaction.onabort = () => reject(transaction.error);
            });
        } finally {
            database.close();
        }
    }

    if (typeof localStorage !== 'undefined') {
        localStorage.removeItem(`${LEGACY_NAMESPACED_KEY_PREFIX}${userId}`);
        localStorage.removeItem(LEGACY_IDENTITY_STORAGE_KEY);
    }
}

export async function importIdentity(
    stored: StoredIdentity,
): Promise<LoadedIdentity> {
    const privateKey = await subtle().importKey(
        'jwk',
        stored.privateKeyJwk,
        { name: 'ECDH', namedCurve: 'P-256' },
        false,
        ['deriveBits'],
    );
    const publicKey = await subtle().importKey(
        'jwk',
        stored.publicKeyJwk,
        { name: 'ECDH', namedCurve: 'P-256' },
        false,
        [],
    );

    return { stored, privateKey, publicKey };
}

export function publicKeyString(stored: StoredIdentity): string {
    return JSON.stringify(stored.publicKeyJwk);
}

export async function importPublicKey(value: string): Promise<CryptoKey> {
    return subtle().importKey(
        'jwk',
        JSON.parse(value) as JsonWebKey,
        { name: 'ECDH', namedCurve: 'P-256' },
        false,
        [],
    );
}

async function deriveWrapKey(
    privateKey: CryptoKey,
    publicKey: CryptoKey,
    context?: string,
): Promise<CryptoKey> {
    const bits = await subtle().deriveBits(
        { name: 'ECDH', public: publicKey },
        privateKey,
        256,
    );

    if (context === undefined) {
        // Compatibility for key wraps written before the v2 HKDF format.
        return subtle().importKey('raw', bits, 'AES-GCM', false, [
            'encrypt',
            'decrypt',
        ]);
    }

    const material = await subtle().importKey('raw', bits, 'HKDF', false, [
        'deriveKey',
    ]);

    return subtle().deriveKey(
        {
            name: 'HKDF',
            hash: 'SHA-256',
            salt: await subtle().digest(
                'SHA-256',
                encoder.encode(`chatapp/e2ee/wrap-context/${context}`),
            ),
            info: encoder.encode('chatapp/e2ee/conversation-key-wrap/v2'),
        },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

export async function generateConversationKey(): Promise<CryptoKey> {
    return subtle().generateKey(
        { name: 'AES-GCM', length: 256 },
        true,
        ['encrypt', 'decrypt'],
    );
}

export async function wrapConversationKey(
    conversationKey: CryptoKey,
    recipientPublicKey: string,
    context: string,
): Promise<E2eeKeyWrap> {
    const rawKey = await subtle().exportKey('raw', conversationKey);
    const recipient = await importPublicKey(recipientPublicKey);
    const ephemeral = (await subtle().generateKey(
        { name: 'ECDH', namedCurve: 'P-256' },
        true,
        ['deriveBits'],
    )) as CryptoKeyPair;
    const ephemeralPublicJwk = await subtle().exportKey(
        'jwk',
        ephemeral.publicKey,
    );
    const wrapKey = await deriveWrapKey(
        ephemeral.privateKey,
        recipient,
        context,
    );
    const iv = randomBytes(12);
    const ciphertext = await subtle().encrypt(
        { name: 'AES-GCM', iv, additionalData: encoder.encode(context) },
        wrapKey,
        rawKey,
    );

    return {
        algorithm: 'ECDH-P256+HKDF-SHA256+A256GCM',
        ephemeral_public_key: JSON.stringify(ephemeralPublicJwk),
        iv: toBase64(iv),
        ciphertext: toBase64(ciphertext),
    };
}

export async function unwrapConversationKey(
    wrap: E2eeKeyWrap,
    identity: LoadedIdentity,
    context: string,
): Promise<CryptoKey> {
    const isLegacy = wrap.algorithm === 'ECDH-P256+A256GCM';
    if (!isLegacy && wrap.algorithm !== 'ECDH-P256+HKDF-SHA256+A256GCM') {
        throw new Error('Unsupported conversation key wrap algorithm.');
    }

    const ephemeralPublic = await importPublicKey(wrap.ephemeral_public_key);
    const wrapKey = await deriveWrapKey(
        identity.privateKey,
        ephemeralPublic,
        isLegacy ? undefined : context,
    );
    const rawKey = await subtle().decrypt(
        {
            name: 'AES-GCM',
            iv: fromBase64(wrap.iv),
            ...(isLegacy ? {} : { additionalData: encoder.encode(context) }),
        },
        wrapKey,
        fromBase64(wrap.ciphertext),
    );

    return subtle().importKey('raw', rawKey, 'AES-GCM', false, [
        'encrypt',
        'decrypt',
    ]);
}

export async function encryptMessageBody(
    conversationId: string,
    clientMessageId: string,
    keyVersion: number,
    conversationKey: CryptoKey,
    plaintext: string,
): Promise<E2eeEncryption> {
    const aad = `${conversationId}:${clientMessageId}:${keyVersion}`;
    const iv = randomBytes(12);
    const ciphertext = await subtle().encrypt(
        {
            name: 'AES-GCM',
            iv,
            additionalData: encoder.encode(aad),
        },
        conversationKey,
        encoder.encode(plaintext),
    );

    return {
        algorithm: 'AES-GCM',
        key_version: keyVersion,
        iv: toBase64(iv),
        ciphertext: toBase64(ciphertext),
        aad,
    };
}

export async function decryptMessageBody(
    encryption: E2eeEncryption,
    conversationKey: CryptoKey,
): Promise<string> {
    const plaintext = await subtle().decrypt(
        {
            name: 'AES-GCM',
            iv: fromBase64(encryption.iv),
            additionalData: encoder.encode(encryption.aad),
        },
        conversationKey,
        fromBase64(encryption.ciphertext),
    );

    return decoder.decode(plaintext);
}

export async function encryptAttachment(
    conversationKey: CryptoKey,
    file: File | Blob,
): Promise<{
    blob: Blob;
    iv: string;
    keyIv: string;
    keyCiphertext: string;
}> {
    const attachmentKey = await subtle().generateKey(
        { name: 'AES-GCM', length: 256 },
        true,
        ['encrypt', 'decrypt'],
    );
    const rawAttachmentKey = await subtle().exportKey('raw', attachmentKey);
    const iv = randomBytes(12);
    const ciphertext = await subtle().encrypt(
        { name: 'AES-GCM', iv },
        attachmentKey,
        await file.arrayBuffer(),
    );
    const keyIv = randomBytes(12);
    const keyCiphertext = await subtle().encrypt(
        { name: 'AES-GCM', iv: keyIv },
        conversationKey,
        rawAttachmentKey,
    );

    return {
        blob: new Blob([ciphertext], { type: 'application/octet-stream' }),
        iv: toBase64(iv),
        keyIv: toBase64(keyIv),
        keyCiphertext: toBase64(keyCiphertext),
    };
}

export async function decryptAttachment(
    conversationKey: CryptoKey,
    iv: string,
    keyIv: string,
    keyCiphertext: string,
    ciphertext: ArrayBuffer,
    mimeType: string,
): Promise<Blob> {
    const rawKey = await subtle().decrypt(
        { name: 'AES-GCM', iv: fromBase64(keyIv) },
        conversationKey,
        fromBase64(keyCiphertext),
    );
    const attachmentKey = await subtle().importKey(
        'raw',
        rawKey,
        'AES-GCM',
        false,
        ['decrypt'],
    );
    const plaintext = await subtle().decrypt(
        { name: 'AES-GCM', iv: fromBase64(iv) },
        attachmentKey,
        ciphertext,
    );

    return new Blob([plaintext], { type: mimeType });
}

export async function createIdentityBackup(
    identity: StoredIdentity,
    passphrase: string,
): Promise<E2eeKeyBackup> {
    const salt = randomBytes(16);
    const iv = randomBytes(12);
    const iterations = 600000;
    const key = await deriveBackupKey(passphrase, salt, iterations);
    const ciphertext = await subtle().encrypt(
        { name: 'AES-GCM', iv },
        key,
        encoder.encode(JSON.stringify(identity)),
    );

    return {
        ciphertext: toBase64(ciphertext),
        salt: toBase64(salt),
        iv: toBase64(iv),
        kdf: 'PBKDF2-SHA256',
        iterations,
        fingerprint: identity.fingerprint,
    };
}

export async function decryptIdentityBackup(
    backup: E2eeKeyBackup,
    passphrase: string,
): Promise<StoredIdentity> {
    const key = await deriveBackupKey(
        passphrase,
        fromBase64(backup.salt),
        backup.iterations,
    );
    const plaintext = await subtle().decrypt(
        { name: 'AES-GCM', iv: fromBase64(backup.iv) },
        key,
        fromBase64(backup.ciphertext),
    );

    return JSON.parse(decoder.decode(plaintext)) as StoredIdentity;
}

async function deriveBackupKey(
    passphrase: string,
    salt: Uint8Array<ArrayBuffer>,
    iterations: number,
): Promise<CryptoKey> {
    const material = await subtle().importKey(
        'raw',
        encoder.encode(passphrase),
        'PBKDF2',
        false,
        ['deriveKey'],
    );

    return subtle().deriveKey(
        {
            name: 'PBKDF2',
            hash: 'SHA-256',
            salt,
            iterations,
        },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

export function memberKeyFor(
    members: E2eeMemberKey[],
    userId: string,
): E2eeMemberKey | undefined {
    return members.find((member) => member.user_id === userId);
}

export function keyStateFor(
    keys: E2eeConversationKeyState[],
    keyVersion: number,
): E2eeConversationKeyState | undefined {
    return keys.find((key) => key.key_version === keyVersion);
}
