<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    Download,
    FileText,
    Film,
    Image as ImageIcon,
    Images,
    Music,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useAttachmentUrls } from '@/composables/useAttachmentUrls';
import {
    formatDuration,
    formatFileSize,
    formatMessageTime,
} from '@/lib/format';
import { useChatStore } from '@/stores/chat';
import type { AttachmentKind, MessageAttachment } from '@/types';

type GalleryTab = 'media' | 'audio' | 'files';

const tabs: { value: GalleryTab; label: string }[] = [
    { value: 'media', label: 'Photos & videos' },
    { value: 'audio', label: 'Audio' },
    { value: 'files', label: 'Files' },
];

const chat = useChatStore();

const open = ref(false);
const tab = ref<GalleryTab>('media');
/** Rows kept from the previous conversation would flash the wrong gallery. */
const loadedConversationId = ref<string | null>(null);

const conversationId = computed(() => chat.activeConversationId);
const attachments = computed(() => chat.mediaFor(conversationId.value));
const hasMore = computed(() => chat.mediaHasMoreFor(conversationId.value));

const { source: attachmentSource, href: attachmentHref } = useAttachmentUrls(
    attachments,
    conversationId,
);

function belongsToTab(
    attachment: MessageAttachment,
    target: GalleryTab,
): boolean {
    switch (target) {
        case 'media':
            return attachment.kind === 'image' || attachment.kind === 'video';
        case 'audio':
            return attachment.kind === 'audio';
        default:
            return attachment.kind === 'file';
    }
}

const visibleAttachments = computed(() =>
    attachments.value.filter((attachment) =>
        belongsToTab(attachment, tab.value),
    ),
);

/** Counts per tab so an empty tab is never a surprise click. */
const counts = computed<Record<GalleryTab, number>>(() => {
    const totals: Record<GalleryTab, number> = { media: 0, audio: 0, files: 0 };

    for (const attachment of attachments.value) {
        if (attachment.kind === 'image' || attachment.kind === 'video') {
            totals.media += 1;
        } else if (attachment.kind === 'audio') {
            totals.audio += 1;
        } else {
            totals.files += 1;
        }
    }

    return totals;
});

function attachmentIcon(kind: AttachmentKind): typeof FileText {
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

function duration(attachment: MessageAttachment): string {
    return formatDuration(attachment.duration_ms);
}

function previewUrl(attachment: MessageAttachment): string | null {
    return attachmentSource(attachment, attachment.kind !== 'video');
}

function load(reset: boolean): void {
    if (conversationId.value === null) {
        return;
    }

    void chat.fetchMedia(conversationId.value, reset);
}

watch(open, (isOpen) => {
    if (!isOpen || conversationId.value === null) {
        return;
    }

    // Reopening the panel starts from the newest attachment: media sent while
    // the panel was closed is exactly what a user expects to see first.
    loadedConversationId.value = conversationId.value;
    load(true);
});

watch(conversationId, (id) => {
    if (id === loadedConversationId.value || !open.value) {
        return;
    }

    loadedConversationId.value = id;
    load(true);
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                v-if="conversationId !== null"
                type="button"
                variant="ghost"
                size="icon"
                class="text-muted-foreground hover:text-chat-accent"
                aria-label="Shared media"
            >
                <Images />
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Shared media</DialogTitle>
                <DialogDescription>
                    Everything sent in this conversation, newest first.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap gap-1">
                <Button
                    v-for="entry in tabs"
                    :key="entry.value"
                    type="button"
                    size="sm"
                    :variant="tab === entry.value ? 'secondary' : 'ghost'"
                    class="h-7 px-2 text-xs"
                    @click="tab = entry.value"
                >
                    {{ entry.label }}
                    <span class="ml-1 text-[11px] text-muted-foreground">
                        {{ counts[entry.value] }}
                    </span>
                </Button>
            </div>

            <Separator />

            <div class="max-h-[60vh] overflow-y-auto pr-1">
                <div
                    v-if="chat.loadingMedia && visibleAttachments.length === 0"
                    class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
                >
                    <Spinner />
                    Loading shared media…
                </div>

                <p
                    v-else-if="visibleAttachments.length === 0"
                    class="py-10 text-center text-sm text-muted-foreground"
                >
                    Nothing here yet.
                </p>

                <div
                    v-else-if="tab === 'media'"
                    class="grid grid-cols-3 gap-1.5 py-2 sm:grid-cols-4"
                >
                    <a
                        v-for="attachment in visibleAttachments"
                        :key="attachment.id"
                        :href="attachmentHref(attachment) ?? undefined"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="group relative aspect-square overflow-hidden rounded-lg bg-muted"
                    >
                        <img
                            v-if="previewUrl(attachment) !== null"
                            :src="previewUrl(attachment) ?? undefined"
                            :alt="attachment.name"
                            loading="lazy"
                            class="size-full object-cover transition duration-200 group-hover:scale-105"
                        />
                        <span
                            v-else
                            class="flex size-full items-center justify-center"
                        >
                            <component
                                :is="attachmentIcon(attachment.kind)"
                                class="size-6 text-muted-foreground"
                            />
                        </span>
                        <span
                            v-if="attachment.kind === 'video'"
                            class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-1 bg-gradient-to-t from-black/70 to-transparent px-1.5 py-1 text-[11px] font-medium text-white"
                        >
                            <Film class="size-3" />
                            <span v-if="duration(attachment) !== ''">
                                {{ duration(attachment) }}
                            </span>
                        </span>
                    </a>
                </div>

                <div v-else-if="tab === 'audio'" class="space-y-2 py-2">
                    <div
                        v-for="attachment in visibleAttachments"
                        :key="attachment.id"
                        class="rounded-xl border border-border/70 px-3 py-2"
                    >
                        <div class="flex items-center gap-2">
                            <Music
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-xs font-medium"
                                >
                                    {{ attachment.name }}
                                </span>
                                <span
                                    class="block text-[11px] text-muted-foreground"
                                >
                                    {{
                                        formatMessageTime(attachment.created_at)
                                    }}
                                    <template
                                        v-if="duration(attachment) !== ''"
                                    >
                                        · {{ duration(attachment) }}
                                    </template>
                                </span>
                            </span>
                            <a
                                v-if="attachmentHref(attachment) !== null"
                                :href="attachmentHref(attachment) ?? undefined"
                                :download="attachment.name"
                                class="text-muted-foreground hover:text-foreground"
                                :aria-label="`Download ${attachment.name}`"
                            >
                                <Download class="size-4" />
                            </a>
                        </div>
                        <audio
                            v-if="attachmentHref(attachment) !== null"
                            :src="attachmentHref(attachment) ?? undefined"
                            controls
                            preload="none"
                            class="mt-2 w-full"
                        />
                    </div>
                </div>

                <ul v-else class="space-y-2 py-2">
                    <li
                        v-for="attachment in visibleAttachments"
                        :key="attachment.id"
                        class="flex items-center gap-2.5 rounded-xl border border-border/70 px-3 py-2"
                    >
                        <FileText
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-medium">
                                {{ attachment.name }}
                            </span>
                            <span
                                class="block text-[11px] text-muted-foreground"
                            >
                                {{ formatFileSize(attachment.size_bytes) }} ·
                                {{ formatMessageTime(attachment.created_at) }}
                            </span>
                        </span>
                        <a
                            v-if="attachmentHref(attachment) !== null"
                            :href="attachmentHref(attachment) ?? undefined"
                            :download="attachment.name"
                            class="text-muted-foreground hover:text-foreground"
                            :aria-label="`Download ${attachment.name}`"
                        >
                            <Download class="size-4" />
                        </a>
                    </li>
                </ul>

                <div v-if="hasMore" class="flex justify-center py-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="h-8 text-xs text-muted-foreground"
                        :disabled="chat.loadingMedia"
                        @click="load(false)"
                    >
                        <Spinner v-if="chat.loadingMedia" class="size-3.5" />
                        Load older attachments
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
