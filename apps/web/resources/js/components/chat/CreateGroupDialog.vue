<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Check, Search, Users } from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/composables/useInitials';
import { ApiError } from '@/lib/http';
import { useChatStore } from '@/stores/chat';
import { useContactsStore } from '@/stores/contacts';
import type { PublicUser } from '@/types';

const chat = useChatStore();
const contacts = useContactsStore();
const { getInitials } = useInitials();

const open = ref(false);
const title = ref('');
const description = ref('');
const memberTerm = ref('');
const historyVisible = ref(false);
const selected = ref<string[]>([]);
const submitting = ref(false);
const error = ref<string | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    title.value = '';
    description.value = '';
    memberTerm.value = '';
    historyVisible.value = false;
    selected.value = [];
    error.value = null;
    contacts.clearSearch();
    void contacts.fetchAll();
});

watch(memberTerm, (value) => {
    if (searchTimer !== undefined) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
        void contacts.searchUsers(value);
    }, 250);
});

/**
 * Contacts make the common case (adding people you already know) one click,
 * and search covers everyone else without a second list to scroll.
 */
const candidates = computed<PublicUser[]>(() => {
    const people = new Map<string, PublicUser>();

    contacts.contacts.forEach((contact) =>
        people.set(contact.user.id, contact.user),
    );
    contacts.searchResults.forEach((user) => people.set(user.id, user));

    const term = memberTerm.value.trim().toLowerCase();

    return [...people.values()].filter(
        (user) =>
            term === '' ||
            user.name.toLowerCase().includes(term) ||
            user.username.toLowerCase().includes(term),
    );
});

function toggle(userId: string): void {
    selected.value = selected.value.includes(userId)
        ? selected.value.filter((id) => id !== userId)
        : [...selected.value, userId];
}

async function submit(): Promise<void> {
    const trimmed = title.value.trim();

    if (trimmed === '' || submitting.value) {
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        await chat.createGroup({
            title: trimmed,
            description:
                description.value.trim() === ''
                    ? null
                    : description.value.trim(),
            memberIds: selected.value,
            historyVisibleToNewMembers: historyVisible.value,
        });

        open.value = false;
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? (requestError.errors.title?.[0] ?? requestError.message)
                : 'Unable to create this group.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="text-muted-foreground hover:text-chat-accent"
                aria-label="New group"
            >
                <Users />
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>New group</DialogTitle>
                <DialogDescription>
                    Name the group and choose who joins it.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="group-title">Group name</Label>
                    <Input
                        id="group-title"
                        v-model="title"
                        autocomplete="off"
                        placeholder="Design team"
                    />
                </div>

                <div class="space-y-2">
                    <Label for="group-description"
                        >Description (optional)</Label
                    >
                    <Textarea
                        id="group-description"
                        v-model="description"
                        rows="2"
                        placeholder="What is this group about?"
                    />
                </div>

                <div class="space-y-2">
                    <Label>Members</Label>
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="memberTerm"
                            class="pl-9"
                            autocomplete="off"
                            placeholder="Search people"
                            aria-label="Search people to add"
                        />
                    </div>

                    <div
                        class="max-h-52 overflow-y-auto rounded-xl border border-border/70"
                    >
                        <p
                            v-if="candidates.length === 0"
                            class="px-3 py-6 text-center text-xs text-muted-foreground"
                        >
                            No people found. Search by name or username.
                        </p>
                        <ul v-else class="divide-y divide-border/60">
                            <li v-for="person in candidates" :key="person.id">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-muted/60"
                                    :aria-pressed="selected.includes(person.id)"
                                    @click="toggle(person.id)"
                                >
                                    <Avatar class="size-8 shrink-0">
                                        <AvatarImage
                                            v-if="person.avatar_url"
                                            :src="person.avatar_url"
                                            :alt="person.name"
                                        />
                                        <AvatarFallback>
                                            {{ getInitials(person.name) }}
                                        </AvatarFallback>
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
                                    <span
                                        class="flex size-5 shrink-0 items-center justify-center rounded-full border"
                                        :class="
                                            selected.includes(person.id)
                                                ? 'border-chat-accent bg-chat-accent text-white'
                                                : 'border-border text-transparent'
                                        "
                                    >
                                        <Check class="size-3" />
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{ selected.length }} selected
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox
                        id="group-history"
                        :model-value="historyVisible"
                        @update:model-value="historyVisible = $event === true"
                    />
                    <Label
                        for="group-history"
                        class="text-sm font-normal text-muted-foreground"
                    >
                        New members can read earlier messages
                    </Label>
                </div>

                <p v-if="error !== null" class="text-sm text-destructive">
                    {{ error }}
                </p>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="submitting || title.trim() === ''"
                >
                    {{ submitting ? 'Creating…' : 'Create group' }}
                </Button>
            </form>
        </DialogContent>
    </Dialog>
</template>
