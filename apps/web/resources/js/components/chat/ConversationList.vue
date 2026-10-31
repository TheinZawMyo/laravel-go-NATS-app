<script setup lang="ts">
import { computed, ref } from 'vue';
import { Bell, BellOff, MessagesSquare, Search } from '@lucide/vue';
import ConnectionStatus from '@/components/chat/ConnectionStatus.vue';
import CreateGroupDialog from '@/components/chat/CreateGroupDialog.vue';
import NewConversationDialog from '@/components/chat/NewConversationDialog.vue';
import PeopleDialog from '@/components/chat/PeopleDialog.vue';
import PresenceDot from '@/components/chat/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
    conversationTitle,
    formatConversationTime,
    formatPresence,
    messagePreview,
} from '@/lib/format';
import { notificationState, requestNotifications } from '@/lib/notifications';
import type { NotificationState } from '@/lib/notifications';
import { useInitials } from '@/composables/useInitials';
import { useAuth } from '@/composables/useAuth';
import { useNow } from '@/composables/useNow';
import { useChatStore } from '@/stores/chat';
import UserMenuContent from '@/components/UserMenuContent.vue';
import type { Conversation } from '@/types';

const chat = useChatStore();
const { user } = useAuth();
const { getInitials } = useInitials();
const now = useNow();

const search = ref('');
const filter = ref<'all' | 'unread' | 'archived'>('all');
const notifications = ref<NotificationState>(notificationState());

async function toggleNotifications(): Promise<void> {
    notifications.value = await requestNotifications();
}

const unreadConversationCount = computed(
    () =>
        chat.conversations.filter(
            (conversation) =>
                conversation.unread_count > 0 && !conversation.archived,
        ).length,
);

const archivedCount = computed(
    () =>
        chat.conversations.filter((conversation) => conversation.archived)
            .length,
);

/**
 * Archived conversations are hidden from the default list and live behind their
 * own filter, which mirrors what archiving means in every other chat client.
 */
const visibleConversations = computed(() =>
    chat.conversations.filter((conversation) =>
        filter.value === 'archived'
            ? conversation.archived
            : !conversation.archived,
    ),
);

const filteredConversations = computed(() => {
    const term = search.value.trim().toLowerCase();

    return visibleConversations.value.filter((conversation) => {
        if (filter.value === 'unread' && conversation.unread_count === 0) {
            return false;
        }

        const name = conversationTitle(conversation).toLowerCase();
        const username = conversation.participant?.username.toLowerCase() ?? '';
        const preview = messagePreview(conversation.last_message).toLowerCase();

        return (
            term === '' ||
            name.includes(term) ||
            username.includes(term) ||
            preview.includes(term)
        );
    });
});

function initials(conversation: Conversation): string {
    return getInitials(conversationTitle(conversation));
}

function preview(conversation: Conversation): string {
    const message = conversation.last_message;

    if (message === null) {
        return 'No messages yet';
    }

    if (message.deleted_at !== null) {
        return 'Message deleted';
    }

    if (message.sender.id === user.value?.id) {
        return `You: ${messagePreview(message)}`;
    }

    return messagePreview(message);
}

/**
 * Direct chats expose the other member's presence; groups have no single
 * participant, so nothing is rendered for them.
 */
function presenceFor(conversation: Conversation): string | null {
    return formatPresence(conversation.participant, now.value);
}

function isOnline(conversation: Conversation): boolean {
    return conversation.participant?.online === true;
}

/** Groups carry their own avatar; direct chats use the other participant's. */
function avatarUrl(conversation: Conversation): string | null {
    return conversation.participant?.avatar_url ?? conversation.avatar_url;
}

function showsPresence(conversation: Conversation): boolean {
    return conversation.type === 'direct';
}

function open(conversation: Conversation): void {
    void chat.selectConversation(conversation.id);
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-background">
        <header
            class="flex h-[76px] shrink-0 items-center justify-between border-b border-border/60 px-4 sm:px-5"
        >
            <div class="flex min-w-0 items-center gap-3">
                <DropdownMenu v-if="user !== null">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="rounded-full ring-offset-background transition-shadow outline-none focus-visible:ring-2 focus-visible:ring-chat-accent focus-visible:ring-offset-2"
                            aria-label="Account menu"
                        >
                            <Avatar class="size-10">
                                <AvatarImage
                                    v-if="user.avatar_url"
                                    :src="user.avatar_url"
                                    :alt="user.name"
                                />
                                <AvatarFallback
                                    class="bg-chat-accent/10 text-sm font-semibold text-chat-accent"
                                >
                                    {{ getInitials(user.name) }}
                                </AvatarFallback>
                            </Avatar>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="start"
                        class="w-64 rounded-2xl p-2"
                    >
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
                <div class="min-w-0">
                    <h2 class="truncate text-xl font-semibold tracking-tight">
                        Messages
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Your conversations
                    </p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <ConnectionStatus class="hidden sm:inline-flex" />
                <Button
                    v-if="notifications !== 'unsupported'"
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="hidden text-muted-foreground hover:text-chat-accent sm:inline-flex"
                    :aria-pressed="notifications === 'granted'"
                    :disabled="notifications === 'denied'"
                    :aria-label="
                        notifications === 'granted'
                            ? 'Desktop notifications are on'
                            : notifications === 'denied'
                              ? 'Desktop notifications are blocked in your browser settings'
                              : 'Enable desktop notifications'
                    "
                    :title="
                        notifications === 'denied'
                            ? 'Notifications are blocked in your browser settings'
                            : undefined
                    "
                    @click="toggleNotifications"
                >
                    <Bell v-if="notifications === 'granted'" />
                    <BellOff v-else />
                </Button>
                <PeopleDialog />
                <CreateGroupDialog />
                <NewConversationDialog />
            </div>
        </header>

        <div class="px-4 pt-4 pb-3 sm:px-5">
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-[18px] -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    class="h-11 rounded-xl border-transparent bg-muted/70 pl-10 shadow-none placeholder:text-muted-foreground/80 focus-visible:border-chat-accent/30 focus-visible:bg-background focus-visible:ring-chat-accent/20"
                    placeholder="Search messages or people"
                    aria-label="Search conversations"
                />
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2 px-4 pb-3 sm:px-5">
            <button
                type="button"
                class="rounded-full px-4 py-2 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-chat-accent focus-visible:outline-none"
                :class="
                    filter === 'all'
                        ? 'bg-chat-accent text-white shadow-sm'
                        : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground'
                "
                :aria-pressed="filter === 'all'"
                @click="filter = 'all'"
            >
                All chats
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-chat-accent focus-visible:outline-none"
                :class="
                    filter === 'unread'
                        ? 'bg-chat-accent text-white shadow-sm'
                        : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground'
                "
                :aria-pressed="filter === 'unread'"
                @click="filter = 'unread'"
            >
                Unread
                <span
                    v-if="unreadConversationCount > 0"
                    class="rounded-full px-1.5 py-0.5 text-[10px] leading-none"
                    :class="
                        filter === 'unread'
                            ? 'bg-white/20 text-white'
                            : 'bg-background text-muted-foreground'
                    "
                >
                    {{ unreadConversationCount }}
                </span>
            </button>
            <button
                type="button"
                class="rounded-full px-4 py-2 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-chat-accent focus-visible:outline-none"
                :class="
                    filter === 'archived'
                        ? 'bg-chat-accent text-white shadow-sm'
                        : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground'
                "
                :aria-pressed="filter === 'archived'"
                @click="filter = 'archived'"
            >
                Archived
                <span
                    v-if="archivedCount > 0"
                    class="ml-1 rounded-full px-1.5 py-0.5 text-[10px] leading-none"
                    :class="
                        filter === 'archived'
                            ? 'bg-white/20 text-white'
                            : 'bg-background text-muted-foreground'
                    "
                >
                    {{ archivedCount }}
                </span>
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-2 pt-1 pb-4 sm:px-3">
            <div
                v-if="
                    chat.loadingConversations && chat.conversations.length === 0
                "
                class="space-y-2 px-1 pt-2"
            >
                <div
                    v-for="index in 6"
                    :key="index"
                    class="flex items-center gap-3 rounded-lg p-2"
                >
                    <Skeleton class="size-10 shrink-0 rounded-full" />
                    <div class="min-w-0 flex-1 space-y-2">
                        <Skeleton class="h-3.5 w-2/5" />
                        <Skeleton class="h-3 w-4/5" />
                    </div>
                </div>
            </div>

            <div
                v-else-if="filteredConversations.length === 0"
                class="flex flex-col items-center justify-center gap-3 px-6 py-20 text-center"
            >
                <div
                    class="flex size-12 items-center justify-center rounded-full bg-muted"
                >
                    <MessagesSquare class="size-6 text-chat-accent" />
                </div>
                <div>
                    <p class="text-sm font-medium">
                        {{
                            search === '' && filter === 'all'
                                ? 'No conversations yet'
                                : filter === 'unread' && search === ''
                                  ? 'Nothing unread'
                                  : filter === 'archived' && search === ''
                                    ? 'No archived chats'
                                    : 'No matches'
                        }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            search === '' && filter === 'all'
                                ? 'Start a direct chat, or add contacts to find people faster.'
                                : filter === 'unread' && search === ''
                                  ? 'You’re all caught up.'
                                  : filter === 'archived' && search === ''
                                    ? 'Archived conversations show up here.'
                                    : 'Try a different name or username.'
                        }}
                    </p>
                </div>
            </div>

            <ul v-else class="space-y-1">
                <li
                    v-for="conversation in filteredConversations"
                    :key="conversation.id"
                >
                    <button
                        type="button"
                        class="group flex w-full items-center gap-3 rounded-2xl px-3 py-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-chat-accent/70 focus-visible:outline-none focus-visible:ring-inset sm:gap-3.5 sm:px-3.5"
                        :class="{
                            'bg-chat-accent text-white shadow-md shadow-chat-accent/15 hover:bg-chat-accent/95':
                                chat.activeConversationId === conversation.id,
                            'hover:bg-muted/80':
                                chat.activeConversationId !== conversation.id,
                        }"
                        :aria-current="
                            chat.activeConversationId === conversation.id
                                ? 'page'
                                : undefined
                        "
                        @click="open(conversation)"
                    >
                        <div class="relative shrink-0">
                            <Avatar
                                class="size-14 ring-1 ring-black/5 dark:ring-white/10"
                            >
                                <AvatarImage
                                    v-if="avatarUrl(conversation) !== null"
                                    :src="avatarUrl(conversation) ?? ''"
                                    :alt="conversationTitle(conversation)"
                                />
                                <AvatarFallback>
                                    {{ initials(conversation) }}
                                </AvatarFallback>
                            </Avatar>
                            <PresenceDot
                                v-if="showsPresence(conversation)"
                                :online="isOnline(conversation)"
                                :name="conversationTitle(conversation)"
                                :border-class="
                                    chat.activeConversationId ===
                                    conversation.id
                                        ? 'border-chat-accent'
                                        : 'border-background'
                                "
                            />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div
                                class="flex items-baseline justify-between gap-2"
                            >
                                <span
                                    class="truncate text-[15px]"
                                    :class="
                                        conversation.unread_count > 0
                                            ? 'font-semibold'
                                            : 'font-medium'
                                    "
                                >
                                    {{ conversationTitle(conversation) }}
                                </span>
                                <BellOff
                                    v-if="conversation.is_muted"
                                    class="size-3 shrink-0"
                                    :class="
                                        chat.activeConversationId ===
                                        conversation.id
                                            ? 'text-white/70'
                                            : 'text-muted-foreground'
                                    "
                                    aria-label="Notifications muted"
                                />
                                <span
                                    class="shrink-0 text-[11px]"
                                    :class="
                                        chat.activeConversationId ===
                                        conversation.id
                                            ? 'text-white/80'
                                            : conversation.unread_count > 0
                                              ? 'font-medium text-chat-accent'
                                              : 'text-muted-foreground'
                                    "
                                >
                                    {{
                                        formatConversationTime(
                                            conversation.last_message
                                                ?.created_at ??
                                                conversation.updated_at,
                                        )
                                    }}
                                </span>
                            </div>
                            <div
                                class="mt-0.5 flex items-center justify-between gap-2"
                            >
                                <span
                                    class="truncate text-[13px]"
                                    :class="
                                        chat.activeConversationId ===
                                        conversation.id
                                            ? 'text-white/85'
                                            : conversation.unread_count > 0
                                              ? 'font-medium text-foreground'
                                              : 'text-muted-foreground'
                                    "
                                >
                                    {{ preview(conversation) }}
                                </span>
                                <Badge
                                    v-if="conversation.unread_count > 0"
                                    class="h-5 min-w-5 shrink-0 justify-center rounded-full border-0 px-1.5 text-[10px] font-bold"
                                    :class="
                                        chat.activeConversationId ===
                                        conversation.id
                                            ? 'bg-white/20 text-white hover:bg-white/20'
                                            : 'bg-chat-accent text-white hover:bg-chat-accent'
                                    "
                                    :aria-label="`${conversation.unread_count} unread messages`"
                                >
                                    {{
                                        conversation.unread_count > 99
                                            ? '99+'
                                            : conversation.unread_count
                                    }}
                                </Badge>
                            </div>
                            <p
                                v-if="presenceFor(conversation) !== null"
                                class="mt-0.5 truncate text-[11px]"
                                :class="
                                    chat.activeConversationId ===
                                    conversation.id
                                        ? 'text-white/75'
                                        : isOnline(conversation)
                                          ? 'font-medium text-emerald-600 dark:text-emerald-400'
                                          : 'text-muted-foreground'
                                "
                            >
                                <!-- {{ presenceFor(conversation) }} -->
                            </p>
                        </div>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
