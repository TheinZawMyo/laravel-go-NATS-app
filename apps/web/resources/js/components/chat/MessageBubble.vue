<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    CheckCheck,
    FileText,
    Film,
    Image as ImageIcon,
    MoreHorizontal,
    Music,
    Pencil,
    Pin,
    PinOff,
    Reply,
    RotateCcw,
    Trash2,
    X,
} from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import { useAuth } from '@/composables/useAuth';
import { useInitials } from '@/composables/useInitials';
import {
    formatDuration,
    formatFileSize,
    formatMessageTime,
} from '@/lib/format';
import { ApiError } from '@/lib/http';
import { useChatStore } from '@/stores/chat';
import type { ChatMessage, MessageAttachment } from '@/types';

const props = withDefaults(
    defineProps<{
        message: ChatMessage;
        showSender?: boolean;
    }>(),
    {
        showSender: false,
    },
);

const chat = useChatStore();
const { user } = useAuth();
const { getInitials } = useInitials();

const editing = ref(false);
const draft = ref('');
const saving = ref(false);
const localError = ref<string | null>(null);

const isOwn = computed(() => props.message.sender.id === user.value?.id);
const isDeleted = computed(() => props.message.deleted_at !== null);
const pinned = computed(() =>
    chat.isPinned(props.message.conversation_id, props.message.id),
);
const canTogglePin = computed(() => chat.canPin() && !isDeleted.value);

const visibleAttachments = computed(() =>
    isDeleted.value ? [] : props.message.attachments,
);

const hasBody = computed(() => {
    const body = props.message.body;

    return body !== null && body.trim() !== '';
});

function attachmentIcon(kind: MessageAttachment['kind']): typeof FileText {
    switch (kind) {
        case 'image':
            return ImageIcon;
        case 'video':
            return Film;
        case 'audio':
            return Music;
        default:
            return FileText;
    }
}

/**
 * Direct conversations have at most two members, so "the highest cursor of
 * everyone else" is exactly "has the other person read this?". Group receipts
 * would need a per-member breakdown the gateway does not send yet.
 */
const isDirect = computed(
    () => (chat.activeConversation?.members.length ?? 0) <= 2,
);

const readByOthers = computed(() => {
    const conversationId = chat.activeConversationId;

    if (
        conversationId === null ||
        !isOwn.value ||
        !isDirect.value ||
        props.message.status !== 'sent' ||
        props.message.seq <= 0
    ) {
        return false;
    }

    return chat.othersReadSeq(conversationId) >= props.message.seq;
});

function startEditing(): void {
    draft.value = props.message.body ?? '';
    localError.value = null;
    editing.value = true;
}

function cancelEditing(): void {
    editing.value = false;
    draft.value = '';
    localError.value = null;
}

async function saveEdit(): Promise<void> {
    const body = draft.value.trim();

    if (body === '' || saving.value) {
        return;
    }

    saving.value = true;
    localError.value = null;

    try {
        await chat.editMessage(props.message, body);
        editing.value = false;
    } catch (error) {
        localError.value =
            error instanceof ApiError
                ? (error.errors.body?.[0] ?? error.message)
                : 'Unable to edit this message.';
    } finally {
        saving.value = false;
    }
}

/**
 * Pinning is idempotent on the server, so a client that does not have the pin
 * list loaded yet can still "pin" safely: the request just confirms the state.
 */
async function togglePin(): Promise<void> {
    localError.value = null;

    try {
        if (pinned.value) {
            await chat.unpinMessage(props.message);
        } else {
            await chat.pinMessage(props.message);
        }
    } catch (error) {
        localError.value =
            error instanceof ApiError
                ? error.message
                : 'Unable to update this pin.';
    }
}

async function remove(): Promise<void> {
    if (
        typeof window !== 'undefined' &&
        !window.confirm('Delete this message?')
    ) {
        return;
    }

    localError.value = null;

    try {
        await chat.deleteMessage(props.message);
    } catch (error) {
        localError.value =
            error instanceof ApiError
                ? error.message
                : 'Unable to delete this message.';
    }
}
</script>

<template>
    <div
        class="group flex w-full gap-2 px-3 py-1.5 sm:px-6 lg:px-8"
        :class="isOwn ? 'flex-row-reverse' : ''"
    >
        <Avatar v-if="!isOwn" class="mt-1 size-8 shrink-0">
            <AvatarImage
                v-if="message.sender.avatar_url"
                :src="message.sender.avatar_url"
                :alt="message.sender.name"
            />
            <AvatarFallback>{{
                getInitials(message.sender.name)
            }}</AvatarFallback>
        </Avatar>

        <div
            class="flex max-w-[min(82%,42rem)] min-w-0 flex-col"
            :class="isOwn ? 'items-end' : 'items-start'"
        >
            <div
                class="mb-1 flex items-center gap-2 px-1 text-[11px] text-muted-foreground"
            >
                <span v-if="showSender && !isOwn" class="font-medium">
                    {{ message.sender.name }}
                </span>
                <span>{{ formatMessageTime(message.created_at) }}</span>
                <span v-if="message.edited_at !== null" class="italic"
                    >edited</span
                >
            </div>

            <div
                class="rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed shadow-sm ring-1 ring-black/5 dark:ring-white/5"
                :class="
                    isOwn
                        ? 'rounded-br-md bg-chat-outgoing text-chat-outgoing-foreground'
                        : 'rounded-bl-md bg-background text-foreground'
                "
            >
                <div
                    v-if="message.reply_to !== null"
                    class="mb-1.5 rounded-md border-l-2 px-2 py-1 text-xs opacity-80"
                    :class="
                        isOwn
                            ? 'border-chat-outgoing-foreground/40 bg-chat-outgoing-foreground/10'
                            : 'border-chat-accent/50 bg-chat-accent/5'
                    "
                >
                    <div class="font-medium">
                        {{ message.reply_to.sender.name }}
                    </div>
                    <div class="line-clamp-2 break-words whitespace-pre-wrap">
                        {{
                            message.reply_to.deleted_at !== null
                                ? 'Message deleted'
                                : message.reply_to.body
                        }}
                    </div>
                </div>

                <div
                    v-if="visibleAttachments.length > 0"
                    class="space-y-2"
                    :class="hasBody ? 'mb-2' : ''"
                >
                    <template
                        v-for="attachment in visibleAttachments"
                        :key="attachment.id"
                    >
                        <a
                            v-if="
                                attachment.kind === 'image' &&
                                attachment.url !== null
                            "
                            :href="attachment.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="block overflow-hidden rounded-xl"
                        >
                            <img
                                :src="
                                    attachment.thumbnail_url ?? attachment.url
                                "
                                :alt="attachment.name"
                                loading="lazy"
                                class="max-h-72 w-full max-w-80 object-cover"
                            />
                        </a>

                        <div
                            v-else-if="
                                attachment.kind === 'video' &&
                                attachment.url !== null
                            "
                            class="relative"
                        >
                            <video
                                :src="attachment.url"
                                :poster="attachment.thumbnail_url ?? undefined"
                                controls
                                preload="metadata"
                                class="max-h-72 w-full max-w-80 rounded-xl"
                            />
                            <span
                                v-if="
                                    formatDuration(attachment.duration_ms) !==
                                    ''
                                "
                                class="pointer-events-none absolute top-1.5 right-1.5 rounded-full bg-black/60 px-1.5 py-0.5 text-[10px] font-medium text-white"
                            >
                                {{ formatDuration(attachment.duration_ms) }}
                            </span>
                        </div>

                        <div
                            v-else-if="
                                attachment.kind === 'audio' &&
                                attachment.url !== null
                            "
                            class="flex w-64 max-w-full flex-col gap-0.5"
                        >
                            <audio
                                :src="attachment.url"
                                controls
                                preload="metadata"
                                class="w-full"
                            />
                            <span
                                v-if="
                                    formatDuration(attachment.duration_ms) !==
                                    ''
                                "
                                class="pl-1 text-[11px] text-muted-foreground"
                            >
                                {{ formatDuration(attachment.duration_ms) }}
                            </span>
                        </div>

                        <component
                            :is="attachment.url !== null ? 'a' : 'span'"
                            v-else
                            class="inline-flex max-w-full items-center gap-2.5 rounded-xl border border-border/60 bg-background/60 px-3 py-2 text-xs"
                            :href="
                                attachment.url !== null
                                    ? attachment.url
                                    : undefined
                            "
                            :target="
                                attachment.url !== null ? '_blank' : undefined
                            "
                            :rel="
                                attachment.url !== null
                                    ? 'noopener noreferrer'
                                    : undefined
                            "
                            :download="
                                attachment.url !== null
                                    ? attachment.name
                                    : undefined
                            "
                        >
                            <component
                                :is="attachmentIcon(attachment.kind)"
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0">
                                <span
                                    class="block max-w-48 truncate font-medium"
                                >
                                    {{ attachment.name }}
                                </span>
                                <span
                                    class="block text-[11px] text-muted-foreground"
                                >
                                    {{ formatFileSize(attachment.size_bytes) }}
                                </span>
                            </span>
                        </component>
                    </template>
                </div>

                <p v-if="isDeleted" class="italic opacity-70">
                    This message was deleted.
                </p>

                <form
                    v-else-if="editing"
                    class="w-[min(70vw,26rem)] space-y-2"
                    @submit.prevent="saveEdit"
                >
                    <Textarea
                        v-model="draft"
                        v-focus
                        class="min-h-16 resize-none bg-background text-foreground"
                        @keydown.esc="cancelEditing"
                    />
                    <div class="flex justify-end gap-1.5">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="text-foreground hover:bg-background/20"
                            @click="cancelEditing"
                        >
                            <X />
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="saving || draft.trim() === ''"
                        >
                            Save
                        </Button>
                    </div>
                </form>

                <p v-else-if="hasBody" class="break-words whitespace-pre-wrap">
                    {{ message.body }}
                </p>
            </div>

            <div
                v-if="isOwn && !isDeleted && !editing"
                class="mt-0.5 flex min-h-4 items-center gap-2 px-1 text-[11px] text-muted-foreground"
            >
                <span v-if="message.status === 'sending'">Sending…</span>
                <button
                    v-else-if="message.status === 'failed'"
                    type="button"
                    class="inline-flex items-center gap-1 text-destructive hover:underline"
                    @click="chat.retryMessage(message)"
                >
                    <RotateCcw class="size-3" />
                    Failed to send — retry
                </button>
                <span
                    v-else-if="readByOthers"
                    class="inline-flex items-center gap-1 text-chat-accent"
                >
                    <CheckCheck class="size-3.5" aria-hidden="true" />
                    Read
                </span>
            </div>

            <p
                v-if="localError !== null"
                class="mt-1 max-w-xs px-1 text-[11px] text-destructive"
            >
                {{ localError }}
            </p>
        </div>

        <div
            v-if="!isDeleted && !editing && message.status !== 'sending'"
            class="self-center opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100"
        >
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Message actions"
                    >
                        <MoreHorizontal />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem @select="chat.setReplyTo(message)">
                        <Reply />
                        Reply
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="canTogglePin" @select="togglePin">
                        <PinOff v-if="pinned" />
                        <Pin v-else />
                        {{ pinned ? 'Unpin' : 'Pin' }}
                    </DropdownMenuItem>
                    <template v-if="isOwn">
                        <DropdownMenuItem @select="startEditing">
                            <Pencil />
                            Edit
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            variant="destructive"
                            @select="remove"
                        >
                            <Trash2 />
                            Delete
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
