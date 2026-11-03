<script setup lang="ts">
import { ref, watch } from 'vue';
import { Flag } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, apiRequest } from '@/lib/http';
import type { ReportReason } from '@/types';

const props = defineProps<{
    username?: string | null;
    messageId?: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const reasons: { value: ReportReason; label: string }[] = [
    { value: 'spam', label: 'Spam' },
    { value: 'harassment', label: 'Harassment or bullying' },
    { value: 'impersonation', label: 'Impersonation' },
    { value: 'scam', label: 'Scam or fraud' },
    { value: 'inappropriate_content', label: 'Inappropriate content' },
    { value: 'other', label: 'Something else' },
];

const reason = ref<ReportReason>('spam');
const details = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);
const submitted = ref(false);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    reason.value = 'spam';
    details.value = '';
    error.value = null;
    submitted.value = false;
});

async function submit(): Promise<void> {
    if (submitting.value) {
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        await apiRequest('/api/v1/reports', {
            method: 'POST',
            body: {
                username: props.username ?? null,
                message_id: props.messageId ?? null,
                reason: reason.value,
                details:
                    details.value.trim() === '' ? null : details.value.trim(),
            },
        });

        submitted.value = true;
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? (Object.values(requestError.errors)[0]?.[0] ??
                  requestError.message)
                : 'Unable to send this report.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle
                    >Report {{ messageId ? 'message' : 'user' }}</DialogTitle
                >
                <DialogDescription>
                    Reports are reviewed by moderators. The person you report is
                    not told who reported them.
                </DialogDescription>
            </DialogHeader>

            <div v-if="submitted" class="space-y-4">
                <div
                    class="flex items-center gap-3 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
                >
                    <Flag class="size-4 shrink-0" />
                    Thanks. Your report has been queued for review.
                </div>
                <DialogFooter>
                    <Button type="button" @click="open = false">Done</Button>
                </DialogFooter>
            </div>

            <form v-else class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="report-reason">Reason</Label>
                    <select
                        id="report-reason"
                        v-model="reason"
                        class="h-11 w-full rounded-xl border border-transparent bg-muted/60 px-3 text-sm shadow-none outline-none focus-visible:border-chat-accent/40 focus-visible:bg-background focus-visible:ring-2 focus-visible:ring-chat-accent/20"
                    >
                        <option
                            v-for="item in reasons"
                            :key="item.value"
                            :value="item.value"
                        >
                            {{ item.label }}
                        </option>
                    </select>
                </div>

                <div class="space-y-2">
                    <Label for="report-details">Details (optional)</Label>
                    <Textarea
                        id="report-details"
                        v-model="details"
                        rows="4"
                        maxlength="2000"
                        placeholder="Describe what happened"
                        class="rounded-xl border-transparent bg-muted/60 px-4 py-3 shadow-none focus-visible:border-chat-accent/40 focus-visible:bg-background focus-visible:ring-chat-accent/20"
                    />
                </div>

                <p v-if="error !== null" class="text-sm text-destructive">
                    {{ error }}
                </p>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="submitting">
                        {{ submitting ? 'Sending…' : 'Send report' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
