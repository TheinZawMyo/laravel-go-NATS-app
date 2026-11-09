<script setup lang="ts">
import { computed, ref } from 'vue';
import { LockKeyhole, ShieldAlert } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useAuth } from '@/composables/useAuth';
import { ApiError } from '@/lib/http';
import { useChatStore } from '@/stores/chat';
import { useE2eeStore } from '@/stores/e2ee';

const chat = useChatStore();
const e2ee = useE2eeStore();
const { user } = useAuth();

const open = ref(false);
const passphrase = ref('');
const busy = ref(false);
const error = ref<string | null>(null);

const visible = computed(
    () =>
        chat.activeIsEncrypted &&
        (e2ee.locked || e2ee.status === 'unsupported'),
);

async function unlock(): Promise<void> {
    if (busy.value || passphrase.value === '' || user.value === null) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await e2ee.unlockWithPassphrase(user.value.id, passphrase.value);
        await chat.refreshDecryption();
        passphrase.value = '';
        open.value = false;
        toast.success('Encryption unlocked.');
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? requestError.message
                : 'Unable to unlock encryption on this device.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        v-if="visible"
        class="flex shrink-0 items-center gap-2 border-b border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs sm:px-6"
    >
        <ShieldAlert class="size-4 shrink-0 text-amber-600" />
        <p class="min-w-0 flex-1">
            <template v-if="e2ee.status === 'unsupported'">
                This browser does not support end-to-end encryption.
            </template>
            <template v-else>
                Encryption is locked on this device. Unlock it with your
                recovery passphrase to read this conversation.
            </template>
        </p>
        <Button
            v-if="e2ee.status !== 'unsupported'"
            type="button"
            size="sm"
            variant="outline"
            class="h-7 shrink-0 text-xs"
            @click="open = true"
        >
            <LockKeyhole class="size-3.5" />
            Unlock
        </Button>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Unlock encryption</DialogTitle>
                <DialogDescription>
                    Enter the recovery passphrase you used when you backed up
                    your encryption key.
                </DialogDescription>
            </DialogHeader>

            <Input
                v-model="passphrase"
                type="password"
                autocomplete="current-password"
                placeholder="Recovery passphrase"
                @keydown.enter.prevent="unlock"
            />

            <p v-if="error !== null" class="text-xs text-destructive">
                {{ error }}
            </p>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="busy"
                    @click="open = false"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    :disabled="busy || passphrase === ''"
                    @click="unlock"
                >
                    Unlock
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
