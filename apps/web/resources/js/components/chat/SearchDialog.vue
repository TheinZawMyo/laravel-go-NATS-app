<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Search } from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { conversationTitle, messagePreview } from '@/lib/format';
import { ApiError, apiRequest } from '@/lib/http';
import { useChatStore } from '@/stores/chat';
import type { Conversation, MessageSearchResult, PublicUser } from '@/types';

type Tab = 'chats' | 'messages' | 'people';

const open = defineModel<boolean>('open', { default: false });

const chat = useChatStore();
const { getInitials } = useInitials();

const query = ref('');
const tab = ref<Tab>('chats');
const chats = ref<Conversation[]>([]);
const messages = ref<MessageSearchResult[]>([]);
const people = ref<PublicUser[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

let debounce: ReturnType<typeof setTimeout> | undefined;
let requestId = 0;

const tabs: { value: Tab; label: string }[] = [
    { value: 'chats', label: 'Chats' },
    { value: 'messages', label: 'Messages' },
    { value: 'people', label: 'People' },
];

const hasResults = computed(() => {
    switch (tab.value) {
        case 'chats':
            return chats.value.length > 0;
        case 'messages':
            return messages.value.length > 0;
        default:
            return people.value.length > 0;
    }
});

watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        tab.value = 'chats';
        chats.value = [];
        messages.value = [];
        people.value = [];
        error.value = null;
    }
});

watch([query, tab], ([value, activeTab], [previousQuery]) => {
    if (debounce !== undefined) {
        clearTimeout(debounce);
    }

    if (previousQuery !== value) {
        chats.value = [];
        messages.value = [];
        people.value = [];
    }

    const term = value.trim();

    if (term === '') {
        loading.value = false;

        return;
    }

    loading.value = true;
    error.value = null;

    debounce = setTimeout(() => {
        void search(term, activeTab);
    }, 250);
});

async function search(term: string, activeTab: Tab): Promise<void> {
    const current = ++requestId;

    try {
        if (activeTab === 'chats') {
            const response = await apiRequest<{ data: Conversation[] }>(
                `/api/v1/search/conversations?q=${encodeURIComponent(term)}`,
            );

            if (current === requestId) {
                chats.value = response.data;
            }
        } else if (activeTab === 'messages') {
            const response = await apiRequest<{ data: MessageSearchResult[] }>(
                `/api/v1/search/messages?q=${encodeURIComponent(term)}`,
            );

            if (current === requestId) {
                messages.value = response.data;
            }
        } else {
            const response = await apiRequest<{ data: PublicUser[] }>(
                `/api/v1/users?q=${encodeURIComponent(term.replace(/^@/, ''))}`,
            );

            if (current === requestId) {
                people.value = response.data;
            }
        }
    } catch (requestError) {
        if (current === requestId) {
            error.value =
                requestError instanceof ApiError
                    ? requestError.message
                    : 'Search is unavailable right now.';
        }
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

function openChat(conversation: Conversation): void {
    open.value = false;
    void chat.selectConversation(conversation.id);
}

function openMessage(message: MessageSearchResult): void {
    open.value = false;
    void chat.selectConversation(message.conversation_id);
}

async function openPerson(person: PublicUser): Promise<void> {
    open.value = false;

    try {
        await chat.openDirect(person.username);
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? requestError.message
                : 'Unable to open this conversation.';
        open.value = true;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-0 p-0 sm:max-w-2xl">
            <DialogHeader class="border-b border-border/70 px-5 py-4">
                <DialogTitle>Search</DialogTitle>
                <DialogDescription>
                    Find conversations, messages, and people.
                </DialogDescription>
            </DialogHeader>

            <div class="border-b border-border/70 px-5 py-3">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="query"
                        autofocus
                        autocomplete="off"
                        class="h-11 rounded-xl border-transparent bg-muted/60 pl-9 shadow-none focus-visible:border-chat-accent/40 focus-visible:bg-background focus-visible:ring-chat-accent/20"
                        placeholder="Search chats, messages, or people"
                        aria-label="Search"
                    />
                </div>

                <div class="mt-3 flex gap-2">
                    <button
                        v-for="item in tabs"
                        :key="item.value"
                        type="button"
                        class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors"
                        :class="
                            tab === item.value
                                ? 'bg-chat-accent text-white'
                                : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground'
                        "
                        :aria-pressed="tab === item.value"
                        @click="tab = item.value"
                    >
                        {{ item.label }}
                    </button>
                </div>
            </div>

            <div class="max-h-[55vh] min-h-40 overflow-y-auto px-2 py-2">
                <div
                    v-if="loading"
                    class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
                >
                    <Spinner class="size-4" />
                    Searching…
                </div>

                <p
                    v-else-if="error !== null"
                    class="px-3 py-8 text-center text-sm text-destructive"
                >
                    {{ error }}
                </p>

                <div
                    v-else-if="query.trim() === ''"
                    class="px-3 py-10 text-center text-sm text-muted-foreground"
                >
                    Type a name, username, or phrase to begin.
                </div>

                <div
                    v-else-if="!hasResults"
                    class="px-3 py-10 text-center text-sm text-muted-foreground"
                >
                    No results.
                </div>

                <template v-else>
                    <ul v-if="tab === 'chats'" class="space-y-1">
                        <li
                            v-if="chats.length === 0"
                            class="px-3 py-8 text-center text-sm text-muted-foreground"
                        >
                            No matching chats.
                        </li>
                        <li
                            v-for="conversation in chats"
                            :key="conversation.id"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-muted/80"
                                @click="openChat(conversation)"
                            >
                                <Avatar class="size-10 shrink-0">
                                    <AvatarImage
                                        v-if="
                                            conversation.participant
                                                ?.avatar_url ??
                                            conversation.avatar_url
                                        "
                                        :src="
                                            conversation.participant
                                                ?.avatar_url ??
                                            conversation.avatar_url ??
                                            ''
                                        "
                                        :alt="conversationTitle(conversation)"
                                    />
                                    <AvatarFallback>{{
                                        getInitials(
                                            conversationTitle(conversation),
                                        )
                                    }}</AvatarFallback>
                                </Avatar>
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-medium"
                                    >
                                        {{ conversationTitle(conversation) }}
                                    </span>
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            messagePreview(
                                                conversation.last_message,
                                            )
                                        }}
                                    </span>
                                </span>
                                <Badge
                                    v-if="conversation.type === 'group'"
                                    variant="secondary"
                                    >Group</Badge
                                >
                            </button>
                        </li>
                    </ul>

                    <ul v-else-if="tab === 'messages'" class="space-y-1">
                        <li
                            v-if="messages.length === 0"
                            class="px-3 py-8 text-center text-sm text-muted-foreground"
                        >
                            No matching messages.
                        </li>
                        <li v-for="message in messages" :key="message.id">
                            <button
                                type="button"
                                class="flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-muted/80"
                                @click="openMessage(message)"
                            >
                                <Avatar class="mt-0.5 size-9 shrink-0">
                                    <AvatarImage
                                        v-if="message.sender.avatar_url"
                                        :src="message.sender.avatar_url"
                                        :alt="message.sender.name"
                                    />
                                    <AvatarFallback>{{
                                        getInitials(message.sender.name)
                                    }}</AvatarFallback>
                                </Avatar>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="truncate text-sm font-medium"
                                        >
                                            {{ message.sender.name }}
                                        </span>
                                        <span
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            in
                                            {{
                                                message.conversation.title ??
                                                'conversation'
                                            }}
                                        </span>
                                    </span>
                                    <span
                                        class="mt-0.5 line-clamp-2 block text-sm text-muted-foreground"
                                    >
                                        {{ message.body }}
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>

                    <ul v-else class="space-y-1">
                        <li
                            v-if="people.length === 0"
                            class="px-3 py-8 text-center text-sm text-muted-foreground"
                        >
                            No matching people.
                        </li>
                        <li v-for="person in people" :key="person.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-muted/80"
                                @click="openPerson(person)"
                            >
                                <Avatar class="size-10 shrink-0">
                                    <AvatarImage
                                        v-if="person.avatar_url"
                                        :src="person.avatar_url"
                                        :alt="person.name"
                                    />
                                    <AvatarFallback>{{
                                        getInitials(person.name)
                                    }}</AvatarFallback>
                                </Avatar>
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-medium"
                                    >
                                        {{ person.name }}
                                    </span>
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                    >
                                        @{{ person.username }}
                                    </span>
                                </span>
                                <Badge
                                    v-if="person.relationship === 'contact'"
                                    variant="secondary"
                                    >Contact</Badge
                                >
                            </button>
                        </li>
                    </ul>
                </template>
            </div>

            <div class="flex justify-end border-t border-border/70 px-5 py-3">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="open = false"
                >
                    Close
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
