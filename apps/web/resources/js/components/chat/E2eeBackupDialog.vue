<script setup lang="ts">
import { ref } from 'vue';
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
import { useE2eeStore } from '@/stores/e2ee';

const open = defineModel<boolean>('open', { required: true });

const e2ee = useE2eeStore();
const passphrase = ref('');
const confirmation = ref('');
const busy = ref(false);
const error = ref<string | null>(null);

async function save(): Promise<void> {
    if (busy.value) {
        return;
    }

    if (passphrase.value.length < 8) {
        error.value = 'Use at least 8 characters for the recovery passphrase.';

        return;
    }

    if (passphrase.value !== confirmation.value) {
        error.value = 'The passphrases do not match.';

        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await e2ee.backupIdentity(passphrase.value);
        passphrase.value = '';
        confirmation.value = '';
        open.value = false;
        toast.success('Encryption key backed up.');
    } catch (requestError) {
        error.value =
            requestError instanceof Error
                ? requestError.message
                : 'Unable to back up the encryption key.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Back up encryption key</DialogTitle>
                <DialogDescription>
                    The backup is encrypted with your passphrase before it
                    leaves this device. Without the passphrase it cannot be
                    restored.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2">
                <Input
                    v-model="passphrase"
                    type="password"
                    autocomplete="new-password"
                    placeholder="Recovery passphrase"
                />
                <Input
                    v-model="confirmation"
                    type="password"
                    autocomplete="new-password"
                    placeholder="Confirm passphrase"
                    @keydown.enter.prevent="save"
                />
            </div>

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
                    @click="save"
                >
                    Save backup
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
