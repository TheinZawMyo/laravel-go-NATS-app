<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import {
    CornerUpLeft,
    Clock3,
    FaceGrinning,
    Image as ImageIcon,
    Mic,
    Paperclip,
    Plus,
    RotateCcw,
    Send,
    Sticker,
    X,
} from '@lucide/vue';
import EmojiPicker from 'vue3-emoji-picker';
import 'vue3-emoji-picker/css';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAppearance } from '@/composables/useAppearance';
import { formatDuration, formatFileSize } from '@/lib/format';
import { apiRequest } from '@/lib/http';
import { MAX_UPLOAD_BYTES, uploadFile } from '@/lib/upload';
import { useChatStore } from '@/stores/chat';
import { useRealtimeStore } from '@/stores/realtime';
import type { MessageAttachment } from '@/types';

type PendingUpload = {
    localId: string;
    file: File;
    /** Local object URL for instant image previews before the send. */
    previewUrl: string | null;
    progress: number;
    status: 'uploading' | 'done' | 'error';
    attachment: MessageAttachment | null;
    error: string | null;
    controller: AbortController;
};

const chat = useChatStore();
const realtime = useRealtimeStore();
const { resolvedAppearance } = useAppearance();

const draft = ref('');
const showEmoji = ref(false);
const showSticker = ref(false);
const expanded = ref(false);
const dragging = ref(false);
const uploads = ref<PendingUpload[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const imageInput = ref<HTMLInputElement | null>(null);
const audioInput = ref<HTMLInputElement | null>(null);
const showScheduler = ref(false);
const scheduledFor = ref('');
const scheduling = ref(false);

/**
 * Voice messages are recorded in the browser with MediaRecorder and then
 * uploaded like any other attachment, so the server never needs a streaming
 * endpoint: the clip is a normal audio file by the time it leaves the client.
 */
const recording = ref(false);
const recordSeconds = ref(0);

let recorder: MediaRecorder | null = null;
let recordStream: MediaStream | null = null;
let recordTimer: number | null = null;
let recordChunks: Blob[] = [];
let recordCancelled = false;

/**
 * Preference order matters: Chromium records WebM/Opus, Safari records AAC in
 * an MP4 container, and both are in the server's allowed MIME list.
 */
const RECORDING_MIME_TYPES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/mp4',
    'audio/ogg;codecs=opus',
];

const recordingSupported = computed(
    () =>
        typeof navigator !== 'undefined' &&
        typeof navigator.mediaDevices?.getUserMedia === 'function' &&
        typeof MediaRecorder !== 'undefined',
);

const hasUploading = computed(() =>
    uploads.value.some((upload) => upload.status === 'uploading'),
);
const hasFailed = computed(() =>
    uploads.value.some((upload) => upload.status === 'error'),
);
const readyUploads = computed(() =>
    uploads.value.filter(
        (upload) => upload.status === 'done' && upload.attachment !== null,
    ),
);
const canSend = computed(
    () =>
        !hasUploading.value &&
        !hasFailed.value &&
        !chat.sending &&
        (draft.value.trim() !== '' || readyUploads.value.length > 0),
);
const canSchedule = computed(
    () =>
        !scheduling.value &&
        !chat.sending &&
        draft.value.trim() !== '' &&
        chat.replyTo === null &&
        uploads.value.length === 0,
);
const scheduleMinimum = ref(
    localDateTimeValue(new Date(Date.now() + 60_000)),
);

function localDateTimeValue(date: Date): string {
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
}

function newClientMessageId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const value = Math.floor(Math.random() * 16);

        return (char === 'x' ? value : (value & 0x3) | 0x8).toString(16);
    });
}

async function scheduleDraft(): Promise<void> {
    if (
        !canSchedule.value ||
        chat.activeConversation === null ||
        scheduledFor.value === ''
    ) {
        return;
    }

    const sendAt = new Date(scheduledFor.value);

    if (Number.isNaN(sendAt.getTime()) || sendAt.getTime() < Date.now() + 60_000) {
        toast.error('Choose a time at least one minute from now.');

        return;
    }

    scheduling.value = true;

    try {
        await apiRequest(`/api/v1/conversations/${chat.activeConversation.id}/scheduled-messages`, {
            method: 'POST',
            body: {
                client_message_id: newClientMessageId(),
                body: draft.value.trim(),
                send_at: sendAt.toISOString(),
            },
        });
        draft.value = '';
        showScheduler.value = false;
        scheduledFor.value = '';
        chat.setReplyTo(null);
        toast.success('Message scheduled.');
    } catch {
        toast.error('Could not schedule this message. Please try again.');
    } finally {
        scheduling.value = false;
    }
}

function openScheduler(): void {
    showScheduler.value = !showScheduler.value;

    if (showScheduler.value) {
        scheduleMinimum.value = localDateTimeValue(
            new Date(Date.now() + 60_000),
        );

        if (scheduledFor.value === '') {
            scheduledFor.value = localDateTimeValue(
                new Date(Date.now() + 60 * 60_000),
            );
        }
    }
}

const commonStickers = [
    '🐶',
    '🐱',
    '🐼',
    '🦊',
    '🐯',
    '🐸',
    '🐵',
    '🐰',
    '🐻',
    '🐨',
    '🌸',
    '🌈',
    '⭐',
    '💫',
    '🎵',
    '🍕',
    '☕',
    '🎁',
    '🏆',
    '💎',
];

function insertText(value: string): void {
    draft.value = `${draft.value}${value}`;
}

function insertEmoji(emoji: { native: string }): void {
    insertText(emoji.native);
}

function toggleEmoji(): void {
    showSticker.value = false;
    showEmoji.value = !showEmoji.value;
}

function toggleSticker(): void {
    showEmoji.value = false;
    showSticker.value = !showSticker.value;
}

function toggleExpanded(): void {
    expanded.value = !expanded.value;

    if (!expanded.value) {
        showSticker.value = false;
    }
}

function pickFiles(): void {
    fileInput.value?.click();
}

function pickImages(): void {
    imageInput.value?.click();
}

function pickAudio(): void {
    audioInput.value?.click();
}

function preferredRecordingMimeType(): string | undefined {
    if (typeof MediaRecorder === 'undefined') {
        return undefined;
    }

    return RECORDING_MIME_TYPES.find((type) =>
        MediaRecorder.isTypeSupported(type),
    );
}

/** MediaRecorder mime types carry codecs, file names only need a container. */
function recordingExtension(mimeType: string): string {
    if (mimeType.includes('ogg')) {
        return 'ogg';
    }

    if (mimeType.includes('mp4')) {
        return 'm4a';
    }

    return 'webm';
}

/**
 * Mic button: start recording when the browser supports it and fall back to
 * the file picker otherwise, so the control never becomes a dead end.
 */
function toggleRecording(): void {
    if (recording.value) {
        sendRecording();

        return;
    }

    void startRecording();
}

async function startRecording(): Promise<void> {
    if (recording.value) {
        return;
    }

    if (!recordingSupported.value) {
        pickAudio();

        return;
    }

    try {
        recordStream = await navigator.mediaDevices.getUserMedia({
            audio: true,
        });
    } catch {
        toast.error(
            'Microphone access was denied. Attach an audio file instead.',
        );
        pickAudio();

        return;
    }

    const mimeType = preferredRecordingMimeType();

    recorder = new MediaRecorder(
        recordStream,
        mimeType === undefined ? undefined : { mimeType },
    );
    recordChunks = [];
    recordCancelled = false;
    recordSeconds.value = 0;

    recorder.addEventListener('dataavailable', (event: BlobEvent) => {
        if (event.data.size > 0) {
            recordChunks.push(event.data);
        }
    });

    recorder.addEventListener('stop', () => finishRecording());

    recorder.start();
    recording.value = true;
    recordTimer = window.setInterval(() => {
        recordSeconds.value += 1;
    }, 1000);
}

function sendRecording(): void {
    stopRecording(false);
}

function cancelRecording(): void {
    stopRecording(true);
}

function stopRecording(cancelled: boolean): void {
    if (recorder === null || recorder.state === 'inactive') {
        teardownRecording();

        return;
    }

    recordCancelled = cancelled;
    // `stop()` flushes the final chunk and then fires the `stop` event, which
    // is where the clip is converted into a file.
    recorder.stop();
}

function finishRecording(): void {
    const chunks = recordChunks;
    const mimeType = recorder?.mimeType ?? 'audio/webm';
    const cancelled = recordCancelled;

    recordChunks = [];
    teardownRecording();

    if (cancelled || chunks.length === 0) {
        return;
    }

    const blob = new Blob(chunks, { type: mimeType });

    if (blob.size === 0) {
        return;
    }

    if (blob.size > MAX_UPLOAD_BYTES) {
        toast.error('That recording is too long. Try a shorter clip.');

        return;
    }

    const extension = recordingExtension(mimeType);
    const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');

    addFiles([
        new File([blob], `voice-message-${stamp}.${extension}`, {
            type: mimeType,
        }),
    ]);
    toast.success('Voice message attached — press send to share it.');
}

function teardownRecording(): void {
    if (recordTimer !== null) {
        window.clearInterval(recordTimer);
        recordTimer = null;
    }

    recordStream?.getTracks().forEach((track) => track.stop());
    recordStream = null;
    recorder = null;
    recording.value = false;
    recordSeconds.value = 0;
}

function handleFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;

    addFiles(Array.from(input.files ?? []));
    input.value = '';
}

function addFiles(files: File[]): void {
    for (const file of files) {
        if (file.size === 0) {
            toast.error(`${file.name} is empty.`);

            continue;
        }

        if (file.size > MAX_UPLOAD_BYTES) {
            toast.error(`${file.name} exceeds the 25 MB limit.`);

            continue;
        }

        // The queue is deeply reactive through the ref, but an object only
        // becomes a proxy when it is read back out of the array. `startUpload`
        // keeps the reference it was handed, so it must be a reactive proxy
        // already — otherwise `progress`/`status` writes would not repaint and
        // the item would sit at 0 % with the send button spinning forever.
        const upload: PendingUpload = reactive({
            localId: crypto.randomUUID(),
            file,
            previewUrl: file.type.startsWith('image/')
                ? URL.createObjectURL(file)
                : null,
            progress: 0,
            status: 'uploading',
            attachment: null,
            error: null,
            controller: new AbortController(),
        });

        uploads.value.push(upload);
        void startUpload(upload);
    }
}

async function startUpload(upload: PendingUpload): Promise<void> {
    try {
        upload.attachment = await uploadFile(
            upload.file,
            (percent) => {
                upload.progress = percent;
            },
            upload.controller.signal,
        );
        upload.status = 'done';
        upload.progress = 100;
    } catch (error) {
        if (upload.controller.signal.aborted) {
            removeUpload(upload.localId);

            return;
        }

        upload.status = 'error';
        upload.error =
            error instanceof Error
                ? error.message
                : 'The upload failed. Try again.';

        toast.error(`${upload.file.name}: ${upload.error}`);
    }
}

function retryUpload(upload: PendingUpload): void {
    const index = uploads.value.findIndex(
        (item) => item.localId === upload.localId,
    );

    if (index === -1) {
        return;
    }

    uploads.value.splice(index, 1);
    addFiles([upload.file]);
}

function removeUpload(localId: string): void {
    const index = uploads.value.findIndex(
        (upload) => upload.localId === localId,
    );

    if (index === -1) {
        return;
    }

    const [removed] = uploads.value.splice(index, 1);

    removed.controller.abort();

    if (removed.previewUrl !== null) {
        URL.revokeObjectURL(removed.previewUrl);
    }
}

function releaseUploads(sent: PendingUpload[]): void {
    for (const upload of sent) {
        if (upload.previewUrl !== null) {
            URL.revokeObjectURL(upload.previewUrl);
        }
    }

    const sentIds = new Set(sent.map((upload) => upload.localId));

    uploads.value = uploads.value.filter(
        (upload) => !sentIds.has(upload.localId),
    );
}

function onDrop(event: DragEvent): void {
    dragging.value = false;

    const files = Array.from(event.dataTransfer?.files ?? []);

    if (files.length > 0) {
        addFiles(files);
    }
}

function handleInput(): void {
    if (chat.activeConversation === null) {
        return;
    }

    if (draft.value.trim() === '') {
        realtime.stopTyping(chat.activeConversation.id);

        return;
    }

    realtime.signalTyping(chat.activeConversation.id);
}

async function submit(): Promise<void> {
    if (!canSend.value || chat.activeConversation === null) {
        if (hasFailed.value) {
            toast.error('Remove or retry the failed uploads before sending.');
        }

        return;
    }

    const body = draft.value.trim();
    const ready = readyUploads.value;
    const replyToId = chat.replyTo?.id ?? null;
    const reply = chat.replyTo;

    // The optimistic bubble previews the local file; the server response
    // replaces these URLs with the authorized download links.
    const attachments: MessageAttachment[] = ready.map((upload) => ({
        ...(upload.attachment as MessageAttachment),
        url: upload.previewUrl ?? upload.attachment?.url ?? null,
        thumbnail_url:
            upload.previewUrl ?? upload.attachment?.thumbnail_url ?? null,
    }));

    realtime.stopTyping(chat.activeConversation.id);
    draft.value = '';
    showEmoji.value = false;
    showSticker.value = false;
    chat.setReplyTo(null);

    const message = await chat.sendMessage(body, replyToId, attachments);

    if (message === null) {
        if (reply !== null) {
            chat.setReplyTo(reply);
        }

        return;
    }

    releaseUploads(ready);
}

onBeforeUnmount(() => {
    teardownRecording();

    for (const upload of uploads.value) {
        upload.controller.abort();

        if (upload.previewUrl !== null) {
            URL.revokeObjectURL(upload.previewUrl);
        }
    }
});
</script>

<template>
    <div
        class="shrink-0 border-t border-sidebar-border/70 bg-chat-canvas px-3 py-3 sm:px-5 sm:py-4"
        :class="
            dragging
                ? 'outline outline-2 -outline-offset-4 outline-chat-accent/40'
                : ''
        "
        @dragenter.prevent="dragging = true"
        @dragover.prevent="dragging = true"
        @dragleave.self="dragging = false"
        @drop.prevent="onDrop"
    >
        <div
            v-if="chat.replyTo !== null"
            class="mx-auto mb-3 flex max-w-4xl items-start gap-2 rounded-xl border-l-2 border-chat-accent bg-background px-3 py-2.5 text-xs shadow-sm"
        >
            <CornerUpLeft class="mt-0.5 size-3.5 shrink-0 text-chat-accent" />
            <div class="min-w-0 flex-1">
                <div class="font-medium">
                    Replying to {{ chat.replyTo.sender.name }}
                </div>
                <div
                    class="line-clamp-2 break-words whitespace-pre-wrap text-muted-foreground"
                >
                    {{ chat.replyTo.body }}
                </div>
            </div>
            <Button
                type="button"
                size="icon-sm"
                variant="ghost"
                aria-label="Cancel reply"
                @click="chat.setReplyTo(null)"
            >
                <X />
            </Button>
        </div>

        <form
            class="relative mx-auto flex max-w-4xl flex-col gap-2"
            @submit.prevent="submit"
        >
            <div
                v-if="showScheduler"
                class="flex flex-wrap items-end gap-2 rounded-xl border border-border/70 bg-background p-3"
            >
                <label class="flex min-w-48 flex-1 flex-col gap-1 text-xs text-muted-foreground">
                    Send at
                    <input
                        v-model="scheduledFor"
                        type="datetime-local"
                        :min="scheduleMinimum"
                        class="h-9 rounded-lg border border-border bg-background px-2 text-sm text-foreground"
                    />
                </label>
                <Button
                    type="button"
                    size="sm"
                    class="bg-chat-accent text-white hover:bg-chat-accent/90"
                    :disabled="!canSchedule || scheduledFor === ''"
                    @click="scheduleDraft"
                >
                    {{ scheduling ? 'Scheduling…' : 'Schedule message' }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="showScheduler = false"
                >
                    Cancel
                </Button>
            </div>

            <div v-if="uploads.length > 0" class="flex flex-wrap gap-2 px-1">
                <div
                    v-for="upload in uploads"
                    :key="upload.localId"
                    class="relative inline-flex max-w-full items-center gap-2 overflow-hidden rounded-xl border border-border/60 bg-muted/40 py-1 pr-2 pl-1"
                >
                    <img
                        v-if="upload.previewUrl !== null"
                        :src="upload.previewUrl"
                        alt=""
                        class="size-9 shrink-0 rounded-lg object-cover"
                    />
                    <span
                        v-else
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-background"
                    >
                        <Spinner
                            v-if="upload.status === 'uploading'"
                            class="size-4 text-muted-foreground"
                        />
                        <Paperclip
                            v-else
                            class="size-4 text-muted-foreground"
                        />
                    </span>

                    <span class="min-w-0">
                        <span
                            class="block max-w-36 truncate text-xs font-medium"
                        >
                            {{ upload.file.name }}
                        </span>
                        <span
                            class="block text-[11px]"
                            :class="
                                upload.status === 'error'
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            <template v-if="upload.status === 'uploading'">
                                {{ upload.progress }}% ·
                                {{ formatFileSize(upload.file.size) }}
                            </template>
                            <template v-else-if="upload.status === 'error'">
                                {{ upload.error }}
                            </template>
                            <template v-else>
                                {{ formatFileSize(upload.file.size) }}
                            </template>
                        </span>
                    </span>

                    <button
                        v-if="upload.status === 'error'"
                        type="button"
                        class="text-muted-foreground hover:text-foreground"
                        :aria-label="`Retry ${upload.file.name}`"
                        @click="retryUpload(upload)"
                    >
                        <RotateCcw class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground"
                        :aria-label="`Remove ${upload.file.name}`"
                        @click="removeUpload(upload.localId)"
                    >
                        <X class="size-3.5" />
                    </button>

                    <span
                        v-if="upload.status === 'uploading'"
                        class="absolute bottom-0 left-0 h-0.5 bg-chat-accent transition-all duration-200"
                        :style="{ width: `${upload.progress}%` }"
                    />
                </div>
            </div>

            <div
                v-if="!recording"
                class="flex items-center gap-2 rounded-[28px] border border-border/70 bg-background p-1.5 shadow-sm transition-all duration-200 focus-within:border-chat-accent/40 focus-within:shadow-lg focus-within:shadow-chat-accent/10"
            >
                <div class="flex shrink-0 items-center gap-0.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground hover:text-chat-accent"
                        aria-label="Emoji"
                        :aria-expanded="showEmoji"
                        @click="toggleEmoji"
                    >
                        <FaceGrinning />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground hover:text-chat-accent"
                        aria-label="Record a voice message"
                        :aria-pressed="recording"
                        @click="toggleRecording"
                    >
                        <Mic />
                    </Button>

                    <template v-if="expanded">
                        <span class="mx-0.5 h-5 w-px bg-border/70" />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="text-muted-foreground hover:text-chat-accent"
                            aria-label="Attach file"
                            @click="pickFiles"
                        >
                            <Paperclip />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="text-muted-foreground hover:text-chat-accent"
                            aria-label="Attach image"
                            @click="pickImages"
                        >
                            <ImageIcon />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="text-muted-foreground hover:text-chat-accent"
                            aria-label="Sticker"
                            :aria-expanded="showSticker"
                            @click="toggleSticker"
                        >
                            <Sticker />
                        </Button>
                    </template>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground hover:text-foreground"
                        :aria-label="
                            expanded
                                ? 'Show fewer options'
                                : 'Show more options'
                        "
                        :aria-expanded="expanded"
                        @click="toggleExpanded"
                    >
                        <Plus
                            class="transition-transform duration-200"
                            :class="expanded ? 'rotate-45' : ''"
                        />
                    </Button>
                </div>

                <Textarea
                    v-model="draft"
                    rows="1"
                    placeholder="Write a message…"
                    aria-label="Message"
                    class="max-h-40 min-h-10 flex-1 resize-none border-0 bg-transparent px-1 py-2 text-sm leading-6 shadow-none focus-visible:border-0 focus-visible:ring-0"
                    @input="handleInput"
                    @blur="
                        chat.activeConversation !== null &&
                        realtime.stopTyping(chat.activeConversation.id)
                    "
                    @keydown.enter.exact.prevent="submit"
                />

                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    class="shrink-0 text-muted-foreground hover:text-chat-accent"
                    aria-label="Schedule message"
                    :disabled="!canSchedule"
                    @click="openScheduler"
                >
                    <Clock3 />
                </Button>

                <Button
                    type="submit"
                    size="icon"
                    class="size-9 shrink-0 rounded-full bg-chat-accent text-white shadow-sm shadow-chat-accent/25 transition-all duration-200 hover:bg-chat-accent hover:shadow-md hover:shadow-chat-accent/30 active:scale-90 disabled:scale-100 disabled:bg-muted disabled:text-muted-foreground disabled:shadow-none"
                    :disabled="!canSend"
                    aria-label="Send message"
                >
                    <Spinner
                        v-if="chat.sending || hasUploading"
                        class="size-4"
                    />
                    <Send v-else class="size-4" />
                </Button>
            </div>

            <div
                v-else
                class="flex items-center gap-2 rounded-[28px] border border-chat-accent/40 bg-background p-1.5 shadow-sm"
            >
                <span class="flex min-w-0 items-center gap-2 pl-2">
                    <span
                        class="size-2 shrink-0 animate-pulse rounded-full bg-destructive"
                    />
                    <span class="text-sm font-medium tabular-nums">
                        {{ formatDuration(recordSeconds * 1000) || '0:00' }}
                    </span>
                    <span class="truncate text-xs text-muted-foreground">
                        Recording a voice message…
                    </span>
                </span>

                <span class="flex-1" />

                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    class="text-muted-foreground hover:text-destructive"
                    aria-label="Cancel recording"
                    @click="cancelRecording"
                >
                    <X />
                </Button>
                <Button
                    type="button"
                    size="icon"
                    class="size-9 shrink-0 rounded-full bg-chat-accent text-white shadow-sm shadow-chat-accent/25 hover:bg-chat-accent active:scale-90"
                    aria-label="Finish recording and attach it"
                    @click="sendRecording"
                >
                    <Send class="size-4" />
                </Button>
            </div>

            <div
                v-if="showEmoji"
                class="absolute bottom-full left-0 z-30 mb-2 w-[280px] max-w-full"
            >
                <div
                    class="overflow-hidden rounded-2xl border border-border/60 shadow-lg"
                >
                    <EmojiPicker
                        set="native"
                        :theme="resolvedAppearance"
                        :per-line="8"
                        :emoji-size="22"
                        recent="recent"
                        :max-recent="24"
                        :auto-focus="false"
                        @select="insertEmoji"
                    />
                </div>
            </div>

            <div
                v-if="showSticker"
                class="grid grid-cols-10 gap-1 rounded-2xl bg-muted/60 p-3"
            >
                <button
                    v-for="sticker in commonStickers"
                    :key="sticker"
                    type="button"
                    class="rounded-lg p-1.5 text-2xl transition hover:bg-background"
                    @click="insertText(sticker)"
                >
                    {{ sticker }}
                </button>
            </div>

            <input
                ref="fileInput"
                type="file"
                multiple
                class="hidden"
                @change="handleFileChange"
            />
            <input
                ref="imageInput"
                type="file"
                accept="image/*"
                multiple
                class="hidden"
                @change="handleFileChange"
            />
            <input
                ref="audioInput"
                type="file"
                accept="audio/*"
                multiple
                class="hidden"
                @change="handleFileChange"
            />
        </form>

        <p
            v-if="chat.error !== null"
            class="mx-auto mt-1.5 max-w-4xl text-xs text-destructive"
        >
            {{ chat.error }}
        </p>
        <p
            class="mx-auto mt-1.5 hidden max-w-4xl px-1 text-[11px] text-muted-foreground sm:block"
        >
            Enter to send · Shift + Enter for a new line
        </p>
    </div>
</template>
