<script setup lang="ts">
import { useVirtualizer } from '@tanstack/vue-virtual';
import { ArrowDown, MessageSquare } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import MessageBubble from '@/components/chat/MessageBubble.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useChatStore } from '@/stores/chat';

const chat = useChatStore();

const scrollElement = ref<HTMLElement | null>(null);
const stickToBottom = ref(true);

// The virtualizer only re-reads its options when the options object identity
// changes, so the count has to be reactive: a plain object freezes `count` at
// mount time and messages appended later would never be rendered.
const virtualizer = useVirtualizer(
    computed(() => ({
        count: chat.messages.length,
        getScrollElement: () => scrollElement.value,
        estimateSize: () => 74,
        overscan: 8,
        getItemKey: (index: number) => chat.messages[index]?.id ?? index,
    })),
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
                    v-for="item in virtualizer.getVirtualItems()"
                    :key="String(item.key)"
                    :data-index="item.index"
                    :ref="measureElement"
                    class="absolute top-0 left-0 w-full"
                    :style="{
                        transform: `translateY(${item.start}px)`,
                    }"
                >
                    <MessageBubble
                        v-if="chat.messages[item.index] !== undefined"
                        :message="chat.messages[item.index]"
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
