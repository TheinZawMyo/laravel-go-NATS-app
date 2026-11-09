<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    Archive,
    ArchiveRestore,
    BellOff,
    BellRing,
    Flag,
    KeyRound,
    LockKeyhole,
    MoreVertical,
    ShieldCheck,
} from '@lucide/vue';
import { toast } from 'vue-sonner';
import E2eeBackupDialog from '@/components/chat/E2eeBackupDialog.vue';
import ReportDialog from '@/components/chat/ReportDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ApiError } from '@/lib/http';
import { useChatStore } from '@/stores/chat';
import { useE2eeStore } from '@/stores/e2ee';

const chat = useChatStore();
const e2ee = useE2eeStore();

const busy = ref(false);
const open = ref(false);
const reportOpen = ref(false);
const backupOpen = ref(false);

const conversation = computed(() => chat.activeConversation);
const isMuted = computed(() => conversation.value?.is_muted === true);
const isArchived = computed(() => conversation.value?.archived === true);
const reportUsername = computed(() =>
    conversation.value?.type === 'direct'
        ? (conversation.value.participant?.username ?? null)
        : null,
);

async function run(action: () => Promise<void>): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;

    try {
        await action();
    } catch (requestError) {
        toast.error(
            requestError instanceof ApiError
                ? requestError.message
                : 'Something went wrong. Please try again.',
        );
    } finally {
        busy.value = false;
        open.value = false;
    }
}

async function toggleMute(): Promise<void> {
    const conversationId = conversation.value?.id;

    if (conversationId === undefined) {
        return;
    }

    await run(() => chat.setMuted(conversationId, !isMuted.value));
}

async function toggleArchive(): Promise<void> {
    const conversationId = conversation.value?.id;

    if (conversationId === undefined) {
        return;
    }

    await run(() => chat.setArchived(conversationId, !isArchived.value));
}

async function enableEncryption(): Promise<void> {
    await run(() => chat.enableActiveEncryption());
}
</script>

<template>
    <DropdownMenu v-model:open="open">
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="text-muted-foreground hover:text-chat-accent"
                aria-label="Conversation actions"
            >
                <MoreVertical />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-52">
            <DropdownMenuItem :disabled="busy" @select="toggleMute">
                <BellRing v-if="isMuted" />
                <BellOff v-else />
                {{ isMuted ? 'Unmute' : 'Mute notifications' }}
            </DropdownMenuItem>
            <DropdownMenuItem :disabled="busy" @select="toggleArchive">
                <ArchiveRestore v-if="isArchived" />
                <Archive v-else />
                {{ isArchived ? 'Unarchive' : 'Archive conversation' }}
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="conversation?.e2ee_enabled === true"
                disabled
            >
                <ShieldCheck />
                End-to-end encrypted
            </DropdownMenuItem>
            <DropdownMenuItem
                v-else-if="conversation !== null"
                :disabled="busy"
                @select="enableEncryption"
            >
                <LockKeyhole />
                Enable encryption
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="e2ee.ready"
                :disabled="busy"
                @select="backupOpen = true"
            >
                <KeyRound />
                Back up encryption key
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="reportUsername !== null"
                :disabled="busy"
                @select="reportOpen = true"
            >
                <Flag />
                Report user
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <ReportDialog v-model:open="reportOpen" :username="reportUsername" />
    <E2eeBackupDialog v-model:open="backupOpen" />
</template>
