<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    Ban,
    Check,
    MessageCircle,
    Search,
    UserMinus,
    UserPlus,
    Users,
    X,
} from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { useInitials } from '@/composables/useInitials';
import { ApiError } from '@/lib/http';
import { useChatStore } from '@/stores/chat';
import { useContactsStore } from '@/stores/contacts';
import type { Contact, ContactRequest, PublicUser } from '@/types';
import type { UserRelationship } from '@/types/user';

type Tab = 'contacts' | 'requests' | 'find' | 'blocked';

const chat = useChatStore();
const contacts = useContactsStore();
const { getInitials } = useInitials();

const open = ref(false);
const tab = ref<Tab>('contacts');
const term = ref('');
const busy = ref(false);
const error = ref<string | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    error.value = null;
    term.value = '';
    contacts.clearSearch();
    void contacts.fetchAll();
});

/**
 * Typing is debounced so a fast typist does not send one search per keystroke;
 * the store also drops responses for superseded terms.
 */
watch(term, (value) => {
    if (searchTimer !== undefined) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
        void contacts.searchUsers(value);
    }, 250);
});

const tabs = computed(() => [
    {
        key: 'contacts' as Tab,
        label: 'Contacts',
        count: contacts.contacts.length,
    },
    {
        key: 'requests' as Tab,
        label: 'Requests',
        count: contacts.pendingIncomingCount,
    },
    { key: 'find' as Tab, label: 'Find people', count: 0 },
    { key: 'blocked' as Tab, label: 'Blocked', count: contacts.blocks.length },
]);

async function run(action: () => Promise<void>): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await action();
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? requestError.message
                : 'Something went wrong. Please try again.';
    } finally {
        busy.value = false;
    }
}

function displayName(contact: Contact): string {
    return contact.nickname ?? contact.user.name;
}

function incomingFor(username: string): ContactRequest | undefined {
    return contacts.incomingRequests.find(
        (request) => request.sender.username === username,
    );
}

function relationshipOf(user: PublicUser): UserRelationship {
    return user.relationship ?? contacts.relationshipFor(user.username);
}

async function startChat(username: string): Promise<void> {
    await run(async () => {
        await chat.openDirect(username);
        open.value = false;
    });
}

async function addContact(user: PublicUser): Promise<void> {
    const pending = incomingFor(user.username);

    await run(async () => {
        if (pending !== undefined) {
            await contacts.acceptRequest(pending);

            return;
        }

        await contacts.sendRequest(user.username);
    });
}

async function removeContact(username: string): Promise<void> {
    if (
        typeof window !== 'undefined' &&
        !window.confirm(`Remove @${username} from your contacts?`)
    ) {
        return;
    }

    await run(() => contacts.removeContact(username));
}

async function block(username: string): Promise<void> {
    if (
        typeof window !== 'undefined' &&
        !window.confirm(
            `Block @${username}? They will not be able to message you.`,
        )
    ) {
        return;
    }

    await run(() => contacts.blockUser(username));
}

async function unblock(username: string): Promise<void> {
    await run(() => contacts.unblockUser(username));
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="relative text-muted-foreground hover:text-chat-accent"
                aria-label="People"
            >
                <Users />
                <span
                    v-if="contacts.pendingIncomingCount > 0"
                    class="absolute top-1.5 right-1.5 size-2 rounded-full bg-chat-accent"
                    aria-hidden="true"
                />
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>People</DialogTitle>
                <DialogDescription>
                    Your contacts, pending requests and blocked accounts.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="option in tabs"
                    :key="option.key"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-chat-accent focus-visible:outline-none"
                    :class="
                        tab === option.key
                            ? 'bg-chat-accent text-white shadow-sm'
                            : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    :aria-pressed="tab === option.key"
                    @click="tab = option.key"
                >
                    {{ option.label }}
                    <span
                        v-if="option.count > 0"
                        class="rounded-full px-1.5 text-[10px] leading-4"
                        :class="
                            tab === option.key
                                ? 'bg-white/20 text-white'
                                : 'bg-background text-muted-foreground'
                        "
                    >
                        {{ option.count }}
                    </span>
                </button>
            </div>

            <p v-if="error !== null" class="text-sm text-destructive">
                {{ error }}
            </p>

            <Separator />

            <div class="max-h-[58vh] space-y-3 overflow-y-auto pr-1">
                <template v-if="tab === 'contacts'">
                    <p
                        v-if="contacts.contacts.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        No contacts yet. Use “Find people” to send a request.
                    </p>
                    <ul v-else class="space-y-1">
                        <li
                            v-for="contact in contacts.contacts"
                            :key="contact.id"
                            class="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-muted/60"
                        >
                            <Avatar class="size-10 shrink-0">
                                <AvatarImage
                                    v-if="contact.user.avatar_url"
                                    :src="contact.user.avatar_url"
                                    :alt="displayName(contact)"
                                />
                                <AvatarFallback>
                                    {{ getInitials(displayName(contact)) }}
                                </AvatarFallback>
                            </Avatar>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ displayName(contact) }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    @{{ contact.user.username }}
                                </p>
                            </div>
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                class="text-muted-foreground hover:text-chat-accent"
                                :aria-label="`Message ${contact.user.username}`"
                                :disabled="busy"
                                @click="startChat(contact.user.username)"
                            >
                                <MessageCircle />
                            </Button>
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                class="text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove ${contact.user.username}`"
                                :disabled="busy"
                                @click="removeContact(contact.user.username)"
                            >
                                <UserMinus />
                            </Button>
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                class="text-muted-foreground hover:text-destructive"
                                :aria-label="`Block ${contact.user.username}`"
                                :disabled="busy"
                                @click="block(contact.user.username)"
                            >
                                <Ban />
                            </Button>
                        </li>
                    </ul>
                </template>

                <template v-else-if="tab === 'requests'">
                    <section class="space-y-1">
                        <h3
                            class="px-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Incoming
                        </h3>
                        <p
                            v-if="contacts.incomingRequests.length === 0"
                            class="px-2 py-3 text-sm text-muted-foreground"
                        >
                            No pending requests.
                        </p>
                        <ul v-else class="space-y-1">
                            <li
                                v-for="request in contacts.incomingRequests"
                                :key="request.id"
                                class="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-muted/60"
                            >
                                <Avatar class="size-10 shrink-0">
                                    <AvatarImage
                                        v-if="request.sender.avatar_url"
                                        :src="request.sender.avatar_url"
                                        :alt="request.sender.name"
                                    />
                                    <AvatarFallback>
                                        {{ getInitials(request.sender.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">
                                        {{ request.sender.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        @{{ request.sender.username }}
                                    </p>
                                    <p
                                        v-if="request.message"
                                        class="truncate text-xs text-muted-foreground italic"
                                    >
                                        “{{ request.message }}”
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    size="icon-sm"
                                    variant="ghost"
                                    class="text-muted-foreground hover:text-chat-accent"
                                    :aria-label="`Accept request from ${request.sender.username}`"
                                    :disabled="busy"
                                    @click="
                                        run(() =>
                                            contacts.acceptRequest(request),
                                        )
                                    "
                                >
                                    <Check />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon-sm"
                                    variant="ghost"
                                    class="text-muted-foreground hover:text-destructive"
                                    :aria-label="`Reject request from ${request.sender.username}`"
                                    :disabled="busy"
                                    @click="
                                        run(() =>
                                            contacts.rejectRequest(request),
                                        )
                                    "
                                >
                                    <X />
                                </Button>
                            </li>
                        </ul>
                    </section>

                    <section class="mt-4 space-y-1">
                        <h3
                            class="px-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Sent
                        </h3>
                        <p
                            v-if="contacts.outgoingRequests.length === 0"
                            class="px-2 py-3 text-sm text-muted-foreground"
                        >
                            You have no outgoing requests.
                        </p>
                        <ul v-else class="space-y-1">
                            <li
                                v-for="request in contacts.outgoingRequests"
                                :key="request.id"
                                class="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-muted/60"
                            >
                                <Avatar class="size-10 shrink-0">
                                    <AvatarImage
                                        v-if="request.receiver.avatar_url"
                                        :src="request.receiver.avatar_url"
                                        :alt="request.receiver.name"
                                    />
                                    <AvatarFallback>
                                        {{ getInitials(request.receiver.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">
                                        {{ request.receiver.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        @{{ request.receiver.username }} ·
                                        Pending
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    class="text-muted-foreground hover:text-destructive"
                                    :disabled="busy"
                                    @click="
                                        run(() =>
                                            contacts.cancelRequest(request),
                                        )
                                    "
                                >
                                    Cancel
                                </Button>
                            </li>
                        </ul>
                    </section>
                </template>

                <template v-else-if="tab === 'find'">
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="term"
                            class="pl-9"
                            autocomplete="off"
                            placeholder="Search by name or @username"
                            aria-label="Search people"
                        />
                    </div>

                    <p
                        v-if="term.trim() === ''"
                        class="px-2 py-8 text-center text-sm text-muted-foreground"
                    >
                        Search for someone to message or add to your contacts.
                    </p>
                    <p
                        v-else-if="contacts.searching"
                        class="px-2 py-6 text-center text-sm text-muted-foreground"
                    >
                        Searching…
                    </p>
                    <p
                        v-else-if="contacts.searchResults.length === 0"
                        class="px-2 py-8 text-center text-sm text-muted-foreground"
                    >
                        No matches for “{{ term }}”.
                    </p>
                    <ul v-else class="space-y-1">
                        <li
                            v-for="person in contacts.searchResults"
                            :key="person.id"
                            class="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-muted/60"
                        >
                            <Avatar class="size-10 shrink-0">
                                <AvatarImage
                                    v-if="person.avatar_url"
                                    :src="person.avatar_url"
                                    :alt="person.name"
                                />
                                <AvatarFallback>
                                    {{ getInitials(person.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ person.name }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    @{{ person.username }}
                                </p>
                            </div>
                            <Badge
                                v-if="relationshipOf(person) === 'contact'"
                                variant="secondary"
                                class="shrink-0"
                            >
                                Contact
                            </Badge>
                            <Badge
                                v-else-if="
                                    relationshipOf(person) === 'request_sent'
                                "
                                variant="secondary"
                                class="shrink-0"
                            >
                                Requested
                            </Badge>
                            <Button
                                v-if="relationshipOf(person) !== 'request_sent'"
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                class="text-muted-foreground hover:text-chat-accent"
                                :aria-label="`Message ${person.username}`"
                                :disabled="busy"
                                @click="startChat(person.username)"
                            >
                                <MessageCircle />
                            </Button>
                            <Button
                                v-if="
                                    relationshipOf(person) === 'none' ||
                                    relationshipOf(person) ===
                                        'request_received'
                                "
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                class="text-muted-foreground hover:text-chat-accent"
                                :aria-label="`Add ${person.username} as a contact`"
                                :disabled="busy"
                                @click="addContact(person)"
                            >
                                <UserPlus />
                            </Button>
                        </li>
                    </ul>
                </template>

                <template v-else>
                    <p
                        v-if="contacts.blocks.length === 0"
                        class="px-2 py-8 text-center text-sm text-muted-foreground"
                    >
                        You have not blocked anyone.
                    </p>
                    <ul v-else class="space-y-1">
                        <li
                            v-for="blocked in contacts.blocks"
                            :key="blocked.id"
                            class="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-muted/60"
                        >
                            <Avatar class="size-10 shrink-0">
                                <AvatarImage
                                    v-if="blocked.user.avatar_url"
                                    :src="blocked.user.avatar_url"
                                    :alt="blocked.user.name"
                                />
                                <AvatarFallback>
                                    {{ getInitials(blocked.user.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ blocked.user.name }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    @{{ blocked.user.username }}
                                </p>
                                <p
                                    v-if="blocked.reason"
                                    class="truncate text-xs text-muted-foreground italic"
                                >
                                    {{ blocked.reason }}
                                </p>
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                class="text-muted-foreground hover:text-chat-accent"
                                :disabled="busy"
                                @click="unblock(blocked.user.username)"
                            >
                                Unblock
                            </Button>
                        </li>
                    </ul>
                </template>
            </div>
        </DialogContent>
    </Dialog>
</template>
