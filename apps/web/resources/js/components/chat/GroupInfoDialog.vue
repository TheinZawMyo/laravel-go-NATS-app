<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { LogOut, Search, Settings2, Trash2 } from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import GroupInvitesPanel from '@/components/chat/GroupInvitesPanel.vue';
import { useAuth } from '@/composables/useAuth';
import { useInitials } from '@/composables/useInitials';
import { ApiError } from '@/lib/http';
import { useChatStore } from '@/stores/chat';
import { useContactsStore } from '@/stores/contacts';
import type { ConversationRole, PublicUser } from '@/types';

const chat = useChatStore();
const contacts = useContactsStore();
const { user } = useAuth();
const { getInitials } = useInitials();

const open = ref(false);
const name = ref('');
const description = ref('');
const historyVisible = ref(false);
const memberTerm = ref('');
const busy = ref(false);
const error = ref<string | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

const conversation = computed(() => chat.activeConversation);
const canManage = computed(() => chat.canManageGroup(conversation.value));
const canAssignRoles = computed(() => chat.canAssignRoles(conversation.value));
const isMuted = computed(() => conversation.value?.is_muted === true);
const isArchived = computed(() => conversation.value?.archived === true);

const assignableRoles: { value: ConversationRole; label: string }[] = [
    { value: 'admin', label: 'Admin' },
    { value: 'moderator', label: 'Moderator' },
    { value: 'member', label: 'Member' },
];

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    name.value = conversation.value?.title ?? '';
    description.value = conversation.value?.description ?? '';
    historyVisible.value =
        conversation.value?.history_visible_to_new_members ?? false;
    memberTerm.value = '';
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

const memberIds = computed(
    () => new Set(conversation.value?.members.map((member) => member.user.id)),
);

/** Search results minus anyone already in the group. */
const addCandidates = computed<PublicUser[]>(() =>
    contacts.searchResults.filter((person) => !memberIds.value.has(person.id)),
);

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

async function saveProfile(): Promise<void> {
    const id = conversation.value?.id;

    if (id === undefined) {
        return;
    }

    const trimmed = name.value.trim();

    if (trimmed === '') {
        return;
    }

    await run(() =>
        chat.updateGroup(id, {
            title: trimmed,
            description:
                description.value.trim() === ''
                    ? null
                    : description.value.trim(),
            historyVisibleToNewMembers: historyVisible.value,
        }),
    );
}

async function toggleMute(): Promise<void> {
    const id = conversation.value?.id;

    if (id === undefined) {
        return;
    }

    await run(() => chat.setMuted(id, !isMuted.value));
}

async function toggleArchive(): Promise<void> {
    const id = conversation.value?.id;

    if (id === undefined) {
        return;
    }

    await run(() => chat.setArchived(id, !isArchived.value));
}

async function addMember(person: PublicUser): Promise<void> {
    const id = conversation.value?.id;

    if (id === undefined) {
        return;
    }

    await run(async () => {
        await chat.addGroupMembers(id, [person.id]);
        memberTerm.value = '';
        contacts.clearSearch();
    });
}

async function removeMember(username: string): Promise<void> {
    const id = conversation.value?.id;

    if (id === undefined) {
        return;
    }

    if (
        typeof window !== 'undefined' &&
        !window.confirm(`Remove @${username} from this group?`)
    ) {
        return;
    }

    await run(() => chat.removeGroupMember(id, username));
}

function changeRole(username: string, event: Event): void {
    const id = conversation.value?.id;

    if (id === undefined) {
        return;
    }

    const role = (event.target as HTMLSelectElement).value as ConversationRole;

    void run(() => chat.changeGroupMemberRole(id, username, role));
}

async function leave(): Promise<void> {
    const id = conversation.value?.id;

    if (id === undefined) {
        return;
    }

    if (
        typeof window !== 'undefined' &&
        !window.confirm(
            'Leave this group? You will stop receiving its messages.',
        )
    ) {
        return;
    }

    await run(async () => {
        await chat.leaveGroup(id);
        open.value = false;
    });
}

function roleLabel(role: ConversationRole): string {
    return role.charAt(0).toUpperCase() + role.slice(1);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                v-if="conversation !== null && conversation.type === 'group'"
                type="button"
                variant="ghost"
                size="icon"
                class="text-muted-foreground hover:text-chat-accent"
                aria-label="Group info"
            >
                <Settings2 />
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ conversation?.title ?? 'Group' }}</DialogTitle>
                <DialogDescription>
                    {{ conversation?.members.length ?? 0 }} members
                    <template v-if="conversation?.description">
                        · {{ conversation.description }}
                    </template>
                </DialogDescription>
            </DialogHeader>

            <p v-if="error !== null" class="text-sm text-destructive">
                {{ error }}
            </p>

            <div class="max-h-[65vh] space-y-5 overflow-y-auto pr-1">
                <section v-if="canManage" class="space-y-3">
                    <h3 class="text-sm font-semibold">Group profile</h3>
                    <Input
                        v-model="name"
                        autocomplete="off"
                        aria-label="Group name"
                        placeholder="Group name"
                    />
                    <Textarea
                        v-model="description"
                        rows="2"
                        aria-label="Group description"
                        placeholder="Description"
                    />
                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="group-history-edit"
                            :model-value="historyVisible"
                            @update:model-value="
                                historyVisible = $event === true
                            "
                        />
                        <Label
                            for="group-history-edit"
                            class="text-sm font-normal text-muted-foreground"
                        >
                            New members can read earlier messages
                        </Label>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="busy || name.trim() === ''"
                        @click="saveProfile"
                    >
                        Save changes
                    </Button>
                </section>

                <Separator />

                <section class="space-y-3">
                    <h3 class="text-sm font-semibold">Notifications</h3>
                    <label
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>Mute this group</span>
                        <Checkbox
                            :model-value="isMuted"
                            aria-label="Mute group"
                            :disabled="busy"
                            @update:model-value="toggleMute"
                        />
                    </label>
                    <div
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>Archive conversation</span>
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            :disabled="busy"
                            @click="toggleArchive"
                        >
                            {{ isArchived ? 'Unarchive' : 'Archive' }}
                        </Button>
                    </div>
                </section>

                <Separator />

                <section class="space-y-2">
                    <h3 class="text-sm font-semibold">
                        Members
                        <span class="font-normal text-muted-foreground">
                            · {{ conversation?.members.length ?? 0 }}
                        </span>
                    </h3>
                    <ul class="space-y-1">
                        <li
                            v-for="member in conversation?.members ?? []"
                            :key="member.user.id"
                            class="flex items-center gap-3 rounded-xl px-1 py-2 hover:bg-muted/60"
                        >
                            <Avatar class="size-9 shrink-0">
                                <AvatarImage
                                    v-if="member.user.avatar_url"
                                    :src="member.user.avatar_url"
                                    :alt="member.user.name"
                                />
                                <AvatarFallback>
                                    {{ getInitials(member.user.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ member.user.name }}
                                    <span
                                        v-if="member.user.id === user?.id"
                                        class="text-muted-foreground"
                                    >
                                        (you)
                                    </span>
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    @{{ member.user.username }}
                                </p>
                            </div>

                            <select
                                v-if="
                                    canAssignRoles &&
                                    member.role !== 'owner' &&
                                    member.user.id !== user?.id
                                "
                                class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                                :value="member.role"
                                :aria-label="`Role for ${member.user.username}`"
                                :disabled="busy"
                                @change="
                                    changeRole(member.user.username, $event)
                                "
                            >
                                <option
                                    v-for="option in assignableRoles"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <Badge v-else variant="secondary" class="shrink-0">
                                {{ roleLabel(member.role) }}
                            </Badge>

                            <Button
                                v-if="
                                    canManage &&
                                    member.role !== 'owner' &&
                                    member.user.id !== user?.id
                                "
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                class="text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove ${member.user.username}`"
                                :disabled="busy"
                                @click="removeMember(member.user.username)"
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    </ul>
                </section>

                <template v-if="canManage && conversation !== null">
                    <Separator />

                    <section class="space-y-2">
                        <h3 class="text-sm font-semibold">Add members</h3>
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

                        <p
                            v-if="
                                memberTerm.trim() !== '' &&
                                addCandidates.length === 0
                            "
                            class="px-1 py-3 text-xs text-muted-foreground"
                        >
                            {{
                                contacts.searching
                                    ? 'Searching…'
                                    : 'No one to add.'
                            }}
                        </p>

                        <ul v-else class="space-y-1">
                            <li
                                v-for="person in addCandidates"
                                :key="person.id"
                                class="flex items-center gap-3 rounded-xl px-1 py-2 hover:bg-muted/60"
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
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    :disabled="busy"
                                    @click="addMember(person)"
                                >
                                    Add
                                </Button>
                            </li>
                        </ul>
                    </section>

                    <Separator />

                    <GroupInvitesPanel :conversation-id="conversation.id" />

                    <Separator />
                </template>

                <Button
                    type="button"
                    variant="destructive"
                    class="w-full"
                    :disabled="busy"
                    @click="leave"
                >
                    <LogOut />
                    Leave group
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
