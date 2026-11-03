<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Flag,
    History,
    ShieldAlert,
    ShieldCheck,
    ShieldX,
    UserRound,
} from '@lucide/vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, apiRequest } from '@/lib/http';
import type {
    AuditLog,
    ReportReason,
    UserReport,
    UserReportStatus,
} from '@/types';

type Tab = 'reports' | 'audit';
type StatusFilter = 'open' | 'resolved' | 'dismissed' | 'all';

const tab = ref<Tab>('reports');
const statusFilter = ref<StatusFilter>('open');
const reports = ref<UserReport[]>([]);
const auditLogs = ref<AuditLog[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

const resolving = ref<UserReport | null>(null);
const resolveStatus = ref<'resolved' | 'dismissed'>('resolved');
const actionTaken = ref('');
const resolutionNote = ref('');
const submitting = ref(false);

const statusFilters: { value: StatusFilter; label: string }[] = [
    { value: 'open', label: 'Open' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'dismissed', label: 'Dismissed' },
    { value: 'all', label: 'All' },
];

const reasonLabels: Record<ReportReason, string> = {
    spam: 'Spam',
    harassment: 'Harassment',
    impersonation: 'Impersonation',
    scam: 'Scam',
    inappropriate_content: 'Inappropriate content',
    other: 'Other',
};

const openCount = computed(
    () => reports.value.filter((report) => report.status === 'open').length,
);

onMounted(() => {
    void loadReports();
});

watch(statusFilter, () => {
    void loadReports();
});

watch(tab, (value) => {
    if (value === 'audit' && auditLogs.value.length === 0) {
        void loadAuditLogs();
    }
});

async function loadReports(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const query =
            statusFilter.value === 'all' ? '' : `?status=${statusFilter.value}`;
        const response = await apiRequest<{ data: UserReport[] }>(
            `/api/v1/admin/reports${query}`,
        );
        reports.value = response.data;
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? requestError.message
                : 'Unable to load reports.';
    } finally {
        loading.value = false;
    }
}

async function loadAuditLogs(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const response = await apiRequest<{ data: AuditLog[] }>(
            '/api/v1/admin/audit-logs?limit=100',
        );
        auditLogs.value = response.data;
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? requestError.message
                : 'Unable to load the audit log.';
    } finally {
        loading.value = false;
    }
}

function openResolve(
    report: UserReport,
    status: 'resolved' | 'dismissed',
): void {
    resolving.value = report;
    resolveStatus.value = status;
    actionTaken.value = '';
    resolutionNote.value = '';
}

async function submitResolution(): Promise<void> {
    if (resolving.value === null || submitting.value) {
        return;
    }

    submitting.value = true;

    try {
        await apiRequest(
            `/api/v1/admin/reports/${resolving.value.id}/resolve`,
            {
                method: 'POST',
                body: {
                    status: resolveStatus.value,
                    action_taken:
                        actionTaken.value.trim() === ''
                            ? null
                            : actionTaken.value.trim(),
                    note:
                        resolutionNote.value.trim() === ''
                            ? null
                            : resolutionNote.value.trim(),
                },
            },
        );

        toast.success('Report updated.');
        resolving.value = null;
        await loadReports();
    } catch (requestError) {
        toast.error(
            requestError instanceof ApiError
                ? requestError.message
                : 'Unable to update this report.',
        );
    } finally {
        submitting.value = false;
    }
}

async function moderate(
    username: string | undefined,
    action: 'suspend' | 'ban' | 'reactivate',
): Promise<void> {
    if (username === undefined) {
        return;
    }

    let reason: string | null = null;

    if (action !== 'reactivate') {
        reason = window.prompt(
            `Reason for ${action === 'ban' ? 'banning' : 'suspending'} @${username} (optional)`,
        );

        if (reason === null) {
            return;
        }
    }

    if (
        !window.confirm(
            action === 'reactivate'
                ? `Reactivate @${username}?`
                : `${action === 'ban' ? 'Ban' : 'Suspend'} @${username}? Their sessions will be revoked.`,
        )
    ) {
        return;
    }

    try {
        await apiRequest(`/api/v1/admin/users/${username}/moderate`, {
            method: 'POST',
            body: { action, reason },
        });
        toast.success(
            action === 'ban'
                ? 'Account banned.'
                : action === 'suspend'
                  ? 'Account suspended.'
                  : 'Account reactivated.',
        );
        await loadReports();
    } catch (requestError) {
        toast.error(
            requestError instanceof ApiError
                ? requestError.message
                : 'Unable to moderate this account.',
        );
    }
}

function formatDate(value: string): string {
    return new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function reportStatusClass(status: UserReportStatus): string {
    switch (status) {
        case 'open':
            return 'bg-amber-500/10 text-amber-700 dark:text-amber-300';
        case 'resolved':
            return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
        default:
            return 'bg-muted text-muted-foreground';
    }
}
</script>

<template>
    <Head title="Moderation" />

    <div class="mx-auto w-full max-w-4xl px-4 py-6 sm:px-8 sm:py-10">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Moderation
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Review reports, moderate accounts, and inspect the audit
                    trail.
                </p>
            </div>
            <Badge
                v-if="openCount > 0"
                class="bg-amber-500/10 text-amber-700 dark:text-amber-300"
            >
                {{ openCount }} open report{{ openCount === 1 ? '' : 's' }}
            </Badge>
        </div>

        <div class="mt-6 flex gap-2" role="tablist">
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'reports'"
                class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition-colors"
                :class="
                    tab === 'reports'
                        ? 'bg-chat-accent text-white'
                        : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground'
                "
                @click="tab = 'reports'"
            >
                <Flag class="size-4" />
                Reports
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'audit'"
                class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition-colors"
                :class="
                    tab === 'audit'
                        ? 'bg-chat-accent text-white'
                        : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground'
                "
                @click="tab = 'audit'"
            >
                <History class="size-4" />
                Audit log
            </button>
        </div>

        <section v-if="tab === 'reports'" class="mt-5 space-y-4">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="item in statusFilters"
                    :key="item.value"
                    type="button"
                    class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors"
                    :class="
                        statusFilter === item.value
                            ? 'bg-foreground text-background'
                            : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    @click="statusFilter = item.value"
                >
                    {{ item.label }}
                </button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="ml-auto text-muted-foreground"
                    :disabled="loading"
                    @click="loadReports"
                >
                    Refresh
                </Button>
            </div>

            <div
                v-if="loading"
                class="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground"
            >
                <Spinner class="size-4" />
                Loading reports…
            </div>

            <p
                v-else-if="error !== null"
                class="rounded-2xl border border-destructive/30 bg-destructive/5 px-4 py-6 text-center text-sm text-destructive"
            >
                {{ error }}
            </p>

            <p
                v-else-if="reports.length === 0"
                class="rounded-2xl border border-black/5 bg-telegram-panel px-4 py-12 text-center text-sm text-muted-foreground dark:border-white/10"
            >
                No reports in this view.
            </p>

            <article
                v-for="report in reports"
                v-else
                :key="report.id"
                class="rounded-2xl border border-black/5 bg-telegram-panel p-5 shadow-sm dark:border-white/10"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <ShieldAlert class="size-4 text-amber-500" />
                        <span class="text-sm font-semibold">
                            {{ reasonLabels[report.reason] ?? report.reason }}
                        </span>
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize"
                            :class="reportStatusClass(report.status)"
                        >
                            {{ report.status }}
                        </span>
                    </div>
                    <span class="text-xs text-muted-foreground">
                        {{ formatDate(report.created_at) }}
                    </span>
                </div>

                <div
                    class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-muted-foreground"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <UserRound class="size-3.5" />
                        Reported
                        <span class="font-medium text-foreground">
                            @{{ report.reported_user?.username ?? 'unknown' }}
                        </span>
                    </span>
                    <span>
                        by @{{ report.reporter?.username ?? 'unknown' }}
                    </span>
                </div>

                <p
                    v-if="report.details"
                    class="mt-3 rounded-xl bg-muted/50 px-3.5 py-2.5 text-sm whitespace-pre-wrap"
                >
                    {{ report.details }}
                </p>

                <blockquote
                    v-if="report.reported_message"
                    class="mt-3 border-l-2 border-chat-accent/40 pl-3 text-sm text-muted-foreground"
                >
                    <span class="font-medium text-foreground">
                        {{ report.reported_message.sender.name }}:
                    </span>
                    {{ report.reported_message.body ?? '[attachment]' }}
                </blockquote>

                <div
                    v-if="report.status !== 'open'"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    <p v-if="report.action_taken">
                        Action: {{ report.action_taken }}
                    </p>
                    <p v-if="report.resolution_note">
                        Note: {{ report.resolution_note }}
                    </p>
                    <p v-if="report.reviewer">
                        Reviewed by @{{ report.reviewer.username }}
                        <span v-if="report.reviewed_at">
                            · {{ formatDate(report.reviewed_at) }}
                        </span>
                    </p>
                </div>

                <div
                    v-else
                    class="mt-4 flex flex-wrap items-center gap-2 border-t border-border/60 pt-4"
                >
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="openResolve(report, 'resolved')"
                    >
                        <ShieldCheck />
                        Resolve
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="openResolve(report, 'dismissed')"
                    >
                        Dismiss
                    </Button>
                    <span class="mx-1 h-5 w-px bg-border" />
                    <Button
                        size="sm"
                        variant="outline"
                        @click="
                            moderate(report.reported_user?.username, 'suspend')
                        "
                    >
                        Suspend
                    </Button>
                    <Button
                        size="sm"
                        variant="destructive"
                        @click="moderate(report.reported_user?.username, 'ban')"
                    >
                        <ShieldX />
                        Ban
                    </Button>
                </div>
            </article>
        </section>

        <section v-else class="mt-5">
            <div class="flex justify-end">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground"
                    :disabled="loading"
                    @click="loadAuditLogs"
                >
                    Refresh
                </Button>
            </div>

            <div
                v-if="loading"
                class="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground"
            >
                <Spinner class="size-4" />
                Loading audit log…
            </div>

            <p
                v-else-if="error !== null"
                class="rounded-2xl border border-destructive/30 bg-destructive/5 px-4 py-6 text-center text-sm text-destructive"
            >
                {{ error }}
            </p>

            <p
                v-else-if="auditLogs.length === 0"
                class="rounded-2xl border border-black/5 bg-telegram-panel px-4 py-12 text-center text-sm text-muted-foreground dark:border-white/10"
            >
                No audit entries yet.
            </p>

            <ul
                v-else
                class="divide-y divide-border/60 overflow-hidden rounded-2xl border border-black/5 bg-telegram-panel shadow-sm dark:border-white/10"
            >
                <li
                    v-for="entry in auditLogs"
                    :key="entry.id"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm"
                >
                    <span class="font-mono text-xs font-semibold">
                        {{ entry.action }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ entry.actor?.name ?? 'system' }}
                        <template v-if="entry.subject_type">
                            · {{ entry.subject_type.split('\\').pop() }}
                        </template>
                    </span>
                    <span class="ml-auto text-xs text-muted-foreground">
                        {{ formatDate(entry.created_at) }}
                    </span>
                    <p
                        v-if="entry.metadata"
                        class="w-full truncate font-mono text-[11px] text-muted-foreground"
                    >
                        {{ JSON.stringify(entry.metadata) }}
                    </p>
                </li>
            </ul>
        </section>
    </div>

    <Dialog
        :open="resolving !== null"
        @update:open="
            (value) => {
                if (!value) resolving = null;
            }
        "
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>
                    {{ resolveStatus === 'resolved' ? 'Resolve' : 'Dismiss' }}
                    report
                </DialogTitle>
                <DialogDescription>
                    Record what you did so other moderators can understand the
                    outcome.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="grid gap-2">
                    <Label for="action-taken">Action taken (optional)</Label>
                    <input
                        id="action-taken"
                        v-model="actionTaken"
                        maxlength="64"
                        placeholder="e.g. warning issued"
                        class="h-11 rounded-xl border border-transparent bg-muted/60 px-3 text-sm outline-none focus-visible:border-chat-accent/40 focus-visible:bg-background focus-visible:ring-2 focus-visible:ring-chat-accent/20"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="resolution-note">Note (optional)</Label>
                    <Textarea
                        id="resolution-note"
                        v-model="resolutionNote"
                        rows="4"
                        maxlength="2000"
                        placeholder="Internal note for the moderation team"
                    />
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    @click="resolving = null"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    :disabled="submitting"
                    @click="submitResolution"
                >
                    {{ submitting ? 'Saving…' : 'Save outcome' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
