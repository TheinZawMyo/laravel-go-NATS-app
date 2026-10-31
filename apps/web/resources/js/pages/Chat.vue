<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChevronLeft, MessagesSquare, RefreshCw } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import ConnectionStatus from '@/components/chat/ConnectionStatus.vue';
import ConversationList from '@/components/chat/ConversationList.vue';
import MessageComposer from '@/components/chat/MessageComposer.vue';
import MessageTimeline from '@/components/chat/MessageTimeline.vue';
import PresenceDot from '@/components/chat/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { useNow } from '@/composables/useNow';
import { useAuth } from '@/composables/useAuth';
import { conversationTitle, formatPresence } from '@/lib/format';
import { useChatStore } from '@/stores/chat';
import { useRealtimeStore } from '@/stores/realtime';
import type { ChatPageProps } from '@/types';

const props = defineProps<ChatPageProps>();

useAuth();

const chat = useChatStore();
const realtime = useRealtimeStore();
const { getInitials } = useInitials();
const now = useNow();

chat.initialize({
    conversations: props.conversations,
    activeConversation: props.activeConversation,
    activeMessages: props.activeMessages,
    activeHasMore: props.activeHasMore,
    activeNextCursor: props.activeNextCursor,
});

watch(
    () => chat.conversations.map((conversation) => conversation.id),
    (conversationIds) => realtime.syncSubscriptions(conversationIds),
    { immediate: true },
);

onMounted(() => realtime.start());
onBeforeUnmount(() => realtime.stop());

const title = computed(() => conversationTitle(chat.activeConversation));
const showConversation = computed(() => chat.activeConversation !== null);
const typingUsers = computed(() =>
    chat.activeConversation === null
        ? []
        : realtime.typingUsers(chat.activeConversation.id),
);
const typingLabel = computed(() => {
    if (typingUsers.value.length === 0) {
        return null;
    }

    if (typingUsers.value.length === 1) {
        const name = chat.activeConversation?.participant?.name ?? 'Someone';

        return `${name} is typing…`;
    }

    return `${typingUsers.value.length} people are typing…`;
});

const participant = computed(
    () => chat.activeConversation?.participant ?? null,
);
const participantOnline = computed(() => participant.value?.online === true);
const presenceLabel = computed(() =>
    formatPresence(participant.value, now.value),
);
</script>

<template>
    <Head :title="title" />

    <div class="flex h-full min-h-0 w-full overflow-hidden bg-background">
        <aside
            class="w-full shrink-0 border-r border-border/70 md:block md:w-[22rem] lg:w-[25rem]"
            :class="showConversation ? 'hidden' : 'block'"
        >
            <ConversationList />
        </aside>

        <section
            class="relative min-w-0 flex-1 flex-col bg-chat-canvas"
            :class="showConversation ? 'flex' : 'hidden md:flex'"
        >
            <template v-if="chat.activeConversation !== null">
                <header
                    class="z-10 flex h-16 shrink-0 items-center gap-3 border-b border-border/70 bg-background/95 px-3 sm:px-6"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="-ml-1 md:hidden"
                        aria-label="Back to conversations"
                        @click="chat.closeConversation()"
                    >
                        <ChevronLeft />
                    </Button>

                    <div class="relative shrink-0">
                        <Avatar class="size-10 ring-1 ring-border/70">
                            <AvatarImage
                                v-if="
                                    chat.activeConversation.participant
                                        ?.avatar_url
                                "
                                :src="
                                    chat.activeConversation.participant
                                        .avatar_url
                                "
                                :alt="title"
                            />
                            <AvatarFallback>{{
                                getInitials(title)
                            }}</AvatarFallback>
                        </Avatar>
                        <PresenceDot
                            v-if="participant !== null"
                            :online="participantOnline"
                            :name="title"
                            size-class="size-3"
                        />
                    </div>

                    <div class="min-w-0 flex-1">
                        <p
                            class="truncate text-[15px] font-semibold tracking-tight"
                        >
                            {{ title }}
                        </p>
                        <p
                            v-if="typingLabel !== null"
                            class="truncate text-xs font-medium text-chat-accent"
                        >
                            {{ typingLabel }}
                        </p>
                        <p
                            v-else-if="participant !== null"
                            class="truncate text-xs"
                            :class="
                                participantOnline
                                    ? 'font-medium text-emerald-600 dark:text-emerald-400'
                                    : 'text-muted-foreground'
                            "
                        >
                            @{{ participant.username }}
                            <template v-if="presenceLabel !== null">
                                · {{ presenceLabel }}
                            </template>
                        </p>
                    </div>

                    <ConnectionStatus />

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground hover:text-chat-accent"
                        aria-label="Refresh messages"
                        :disabled="chat.refreshing"
                        @click="chat.refreshMessages"
                    >
                        <Spinner v-if="chat.refreshing" />
                        <RefreshCw v-else />
                    </Button>
                </header>

                <MessageTimeline />

                <MessageComposer />
            </template>

            <div
                v-else
                class="flex h-full flex-col items-center justify-center px-6 text-center"
            >
                <div
                    class="relative mb-7 flex size-32 items-center justify-center"
                >
                    <div
                        class="absolute inset-0 rounded-full bg-chat-accent/10"
                    />
                    <div
                        class="absolute inset-3 rounded-full border border-chat-accent/15"
                    />
                    <div
                        class="relative flex size-20 items-center justify-center rounded-3xl bg-chat-accent text-white shadow-xl shadow-chat-accent/25"
                    >
                        <MessagesSquare class="size-9" />
                    </div>
                </div>
                <div class="max-w-sm">
                    <p class="text-xl font-semibold tracking-tight">
                        Your conversations, in one place
                    </p>
                    <p class="mt-2 text-sm leading-6 text-muted-foreground">
                        Select a chat to pick up where you left off, or start a
                        new conversation from the chat list.
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
