<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Pin, PinOff } from '@lucide/vue';
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
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { formatMessageTime, messagePreview } from '@/lib/format';
import { ApiError } from '@/lib/http';
import { useChatStore } from '@/stores/chat';
import type { ChatMessage } from '@/types';

const chat = useChatStore();

const open = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);

const conversationId = computed(() => chat.activeConversationId);
const pins = computed(() => chat.pinsFor(conversationId.value));
const canPin = computed(() => chat.canPin());

watch(open, (isOpen) => {
    if (!isOpen || conversationId.value === null) {
        return;
    }

    error.value = null;
    void chat.fetchPins(conversationId.value);
});

async function unpin(message: ChatMessage): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await chat.unpinMessage(message);
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? requestError.message
                : 'Unable to unpin this message.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                v-if="conversationId !== null"
                type="button"
                variant="ghost"
                size="icon"
                class="relative text-muted-foreground hover:text-chat-accent"
                aria-label="Pinned messages"
            >
                <Pin />
                <span
                    v-if="pins.length > 0"
                    class="absolute top-1 right-1 size-2 rounded-full bg-chat-accent"
                />
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Pinned messages</DialogTitle>
                <DialogDescription>
                    Messages everyone in this conversation can see.
                </DialogDescription>
            </DialogHeader>

            <p v-if="error !== null" class="text-sm text-destructive">
                {{ error }}
            </p>

            <Separator />

            <div class="max-h-[60vh] overflow-y-auto">
                <div
                    v-if="chat.loadingPins && pins.length === 0"
                    class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
                >
                    <Spinner />
                    Loading pinned messages…
                </div>

                <p
                    v-else-if="pins.length === 0"
                    class="py-10 text-center text-sm text-muted-foreground"
                >
                    Nothing pinned yet.
                </p>

                <ul v-else class="space-y-2 py-2">
                    <li
                        v-for="pin in pins"
                        :key="pin.message.id"
                        class="rounded-xl border border-border/70 px-3 py-2"
                    >
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold">
                                {{ pin.message.sender.name }}
                            </span>
                            <span class="text-[11px] text-muted-foreground">
                                {{ formatMessageTime(pin.message.created_at) }}
                            </span>
                            <Badge
                                variant="secondary"
                                class="ml-auto h-5 shrink-0 px-1.5 text-[10px]"
                            >
                                Pinned by {{ pin.pinned_by.name }}
                            </Badge>
                        </div>
                        <p
                            class="mt-1 text-sm break-words whitespace-pre-wrap"
                            :class="
                                pin.message.deleted_at !== null
                                    ? 'text-muted-foreground italic'
                                    : ''
                            "
                        >
                            {{ messagePreview(pin.message) }}
                        </p>
                        <Button
                            v-if="canPin && pin.message.deleted_at === null"
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="mt-1 h-7 px-2 text-xs text-muted-foreground hover:text-destructive"
                            :disabled="busy"
                            @click="unpin(pin.message)"
                        >
                            <PinOff />
                            Unpin
                        </Button>
                    </li>
                </ul>
            </div>
        </DialogContent>
    </Dialog>
</template>
