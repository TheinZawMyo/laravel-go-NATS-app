<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Check, Copy, Link2, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/http';
import { useChatStore } from '@/stores/chat';
import { formatConversationTime } from '@/lib/format';
import type { ConversationInvite } from '@/types';

const props = defineProps<{ conversationId: string }>();

const chat = useChatStore();

const busy = ref(false);
const copiedToken = ref<string | null>(null);
const error = ref<string | null>(null);

onMounted(() => {
    void chat.fetchInvites(props.conversationId);
});

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
                : 'Unable to update invite links.';
    } finally {
        busy.value = false;
    }
}

async function createInvite(): Promise<void> {
    await run(async () => {
        await chat.createInvite(props.conversationId);
    });
}

async function copy(invite: ConversationInvite): Promise<void> {
    if (typeof navigator === 'undefined' || navigator.clipboard === undefined) {
        return;
    }

    await navigator.clipboard.writeText(invite.url);
    copiedToken.value = invite.token;
}

function useLimit(invite: ConversationInvite): string {
    return invite.max_uses === null
        ? `${invite.uses} used`
        : `${invite.uses}/${invite.max_uses} used`;
}
</script>

<template>
    <section class="space-y-2">
        <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold">Invite links</h3>
                <p class="text-xs text-muted-foreground">
                    Anyone with a link can join this group.
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="secondary"
                :disabled="busy"
                @click="createInvite"
            >
                <Link2 />
                Create
            </Button>
        </div>

        <p v-if="error !== null" class="text-xs text-destructive">
            {{ error }}
        </p>

        <p
            v-if="(chat.invites[conversationId] ?? []).length === 0"
            class="rounded-xl border border-dashed border-border/70 px-3 py-4 text-center text-xs text-muted-foreground"
        >
            No invite links yet.
        </p>

        <ul v-else class="space-y-2">
            <li
                v-for="invite in chat.invites[conversationId] ?? []"
                :key="invite.token"
                class="rounded-xl border border-border/70 px-3 py-2"
            >
                <div class="flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate text-xs">
                        {{ invite.url }}
                    </code>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        class="text-muted-foreground hover:text-chat-accent"
                        :aria-label="
                            copiedToken === invite.token
                                ? 'Link copied'
                                : 'Copy invite link'
                        "
                        @click="copy(invite)"
                    >
                        <Check
                            v-if="copiedToken === invite.token"
                            class="text-emerald-600"
                        />
                        <Copy v-else />
                    </Button>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        class="text-muted-foreground hover:text-destructive"
                        aria-label="Revoke invite link"
                        :disabled="busy"
                        @click="
                            run(() =>
                                chat.revokeInvite(conversationId, invite.id),
                            )
                        "
                    >
                        <Trash2 />
                    </Button>
                </div>
                <p class="mt-1 text-[11px] text-muted-foreground">
                    {{ useLimit(invite) }}
                    <template v-if="invite.expires_at !== null">
                        · expires
                        {{ formatConversationTime(invite.expires_at) }}
                    </template>
                    <template v-if="!invite.is_active"> · inactive</template>
                </p>
            </li>
        </ul>
    </section>
</template>
