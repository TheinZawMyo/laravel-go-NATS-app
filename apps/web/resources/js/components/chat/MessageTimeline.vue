<script setup lang="ts">
import { useVirtualizer } from '@tanstack/vue-virtual';
import { ArrowDown, MessageSquare } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import MessageBubble from '@/components/chat/MessageBubble.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatMessageDay, messageDayKey } from '@/lib/format';
import { useNow } from '@/composables/useNow';
import { useChatStore } from '@/stores/chat';
import type { ChatMessage } from '@/types';

const chat = useChatStore();
// Day labels must roll over at midnight even if no message arrives, so the
// divider computation reads a clock that ticks once a minute.
const now = useNow(60_000);

const scrollElement = ref<HTMLElement | null>(null);
const stickToBottom = ref(true);

/**
 * A flat timeline where the first message of every calendar day carries the
 * divider label. Keeping dividers attached to a message row means the
 * virtualizer still measures one element per row and day breaks never drift
 * away from the messages they introduce.
 */
type TimelineRow = {
    key: string;
    message: ChatMessage;
    dayLabel: string | null;
};

const rows = computed<TimelineRow[]>(() => {
    const nowMs = now.value;
    let previousDay: string | null = null;

    return chat.messages.map((message) => {
        const day = messageDayKey(message.created_at);
        const startsDay = day !== previousDay;
        const label = startsDay
            ? formatMessageDay(message.created_at, nowMs)
            : '';

        previousDay = day;

        return {
            key: message.id,
            message,
            dayLabel: label === '' ? null : label,
        };
    });
});

// The virtualizer only re-reads its options when the options object identity
// changes, so the count has to be reactive: a plain object freezes `count` at
// mount time and messages appended later would never be rendered.
const virtualizer = useVirtualizer(
    computed(() => ({
        count: rows.value.length,
        getScrollElement: () => scrollElement.value,
        estimateSize: () => 74,
        overscan: 8,
        getItemKey: (index: number) => rows.value[index]?.key ?? index,
    })),
);

/**
 * Virtual items carry layout offsets while `rows` carries content; merging
 * them here keeps the template free of index lookups that could be stale for
 * one tick after the message list shrinks.
 */
type VisibleRow = {
    key: string;
    index: number;
    start: number;
    message: ChatMessage;
    dayLabel: string | null;
};

const visibleRows = computed<VisibleRow[]>(() =>
    virtualizer.value
        .getVirtualItems()
        .map((item): VisibleRow | null => {
            const row = rows.value[item.index];

            if (row === undefined) {
                return null;
            }

            return {
                key: String(item.key),
                index: item.index,
                start: item.start,
                message: row.message,
                dayLabel: row.dayLabel,
            };
        })
        .filter((row): row is VisibleRow => row !== null),
);

function nearBottom(element: HTMLElement): boolean {
    return (
        element.scrollHeight - element.scrollTop - element.clientHeight < 120
    );
}

function scrollToBottom(): void {
    void nextTick(() => {
        const element = scrollElement.value;

        if (element !== null) {
            element.scrollTop = element.scrollHeight;
        }

        requestAnimationFrame(() => {
            const current = scrollElement.value;

            if (current !== null) {
                current.scrollTop = current.scrollHeight;
            }
        });
    });
}

function onScroll(): void {
    const element = scrollElement.value;

    if (element === null) {
        return;
    }

    stickToBottom.value = nearBottom(element);

    if (element.scrollTop < 48) {
        void loadOlderMessages();
    }
}

async function loadOlderMessages(): Promise<void> {
    if (!chat.hasMore || chat.loadingMore) {
        return;
    }

    const element = scrollElement.value;
    const previousHeight = element?.scrollHeight ?? 0;
    const previousTop = element?.scrollTop ?? 0;

    await chat.loadOlder();
    await nextTick();

    if (element !== null) {
        element.scrollTop = element.scrollHeight - previousHeight + previousTop;
    }
}

function measureElement(
    element: Element | ComponentPublicInstance | null,
): void {
    if (element instanceof Element) {
        virtualizer.value.measureElement(element);
    }
}

watch(
    () => chat.messages.length,
    async () => {
        if (stickToBottom.value) {
            await nextTick();
            scrollToBottom();
        }
    },
);

watch(
    () => chat.activeConversationId,
    async () => {
        stickToBottom.value = true;
        await nextTick();
        scrollToBottom();
    },
);

onMounted(() => {
    scrollToBottom();
});
</script>

<template>
    <div class="chat-wallpaper relative flex min-h-0 flex-1 flex-col">
        <div
            v-if="chat.loadingMessages && chat.messages.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 text-sm text-muted-foreground"
        >
            <Spinner />
            Loading messages…
        </div>

        <div
            v-else-if="chat.messages.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 px-6 text-center"
        >
            <div
                class="flex size-14 items-center justify-center rounded-full bg-chat-accent/10"
            >
                <MessageSquare class="size-6 text-chat-accent" />
            </div>
            <div>
                <p class="text-sm font-medium">No messages yet</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Say hello to start the conversation.
                </p>
            </div>
        </div>

        <div
            v-else
            ref="scrollElement"
            class="min-h-0 flex-1 overflow-y-auto px-1 py-5 sm:px-3 sm:py-6"
            role="log"
            aria-live="polite"
            @scroll.passive="onScroll"
        >
            <div
                class="relative w-full"
                :style="{ height: `${virtualizer.getTotalSize()}px` }"
            >
                <div
                    v-for="item in visibleRows"
                    :key="item.key"
                    :data-index="item.index"
                    :ref="measureElement"
                    class="absolute top-0 left-0 w-full"
                    :style="{
                        transform: `translateY(${item.start}px)`,
                    }"
                >
                    <div
                        v-if="item.dayLabel !== null"
                        class="flex justify-center py-3"
                        role="separator"
                        :aria-label="item.dayLabel"
                    >
                        <span
                            class="rounded-full border border-border/60 bg-muted/80 px-3 py-1 text-[11px] font-medium tracking-wide text-muted-foreground shadow-sm backdrop-blur"
                        >
                            {{ item.dayLabel }}
                        </span>
                    </div>
                    <MessageBubble
                        :message="item.message"
                        :show-sender="chat.activeConversation?.type === 'group'"
                    />
                </div>
            </div>
        </div>

        <Button
            v-if="chat.hasMore"
            type="button"
            size="sm"
            variant="secondary"
            class="absolute top-3 left-1/2 z-10 -translate-x-1/2 rounded-full border border-border/70 bg-background/95 px-4 shadow-md"
            :disabled="chat.loadingMore"
            @click="loadOlderMessages"
        >
            <Spinner v-if="chat.loadingMore" />
            {{ chat.loadingMore ? 'Loading…' : 'Load earlier messages' }}
        </Button>

        <Button
            v-if="!stickToBottom"
            type="button"
            size="icon-sm"
            variant="secondary"
            class="absolute right-4 bottom-4 z-10 rounded-full border border-border/70 bg-background shadow-md hover:text-chat-accent"
            aria-label="Jump to latest messages"
            @click="scrollToBottom"
        >
            <ArrowDown />
        </Button>
    </div>
</template>
