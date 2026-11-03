<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Download, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type LastSeenVisibility = 'everyone' | 'contacts' | 'nobody';
type ProfileVisibility = 'everyone' | 'contacts';
type ContactRequestPolicy = 'everyone' | 'contacts' | 'nobody';

const props = defineProps<{
    privacy: {
        last_seen_visibility: LastSeenVisibility;
        profile_visibility: ProfileVisibility;
        contact_requests_policy: ContactRequestPolicy;
    };
}>();

const form = useForm({
    last_seen_visibility: props.privacy.last_seen_visibility,
    profile_visibility: props.privacy.profile_visibility,
    contact_requests_policy: props.privacy.contact_requests_policy,
});

const exporting = ref(false);

function submit(): void {
    form.patch('/settings/privacy', {
        preserveScroll: true,
    });
}

async function exportData(): Promise<void> {
    if (exporting.value) {
        return;
    }

    exporting.value = true;

    try {
        const response = await fetch('/api/v1/me/export', {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Export failed');
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `chat-export-${new Date().toISOString().slice(0, 10)}.json`;
        document.body.append(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);

        toast.success('Your data export is downloading.');
    } catch {
        toast.error('Unable to prepare your export right now.');
    } finally {
        exporting.value = false;
    }
}

const selectClass =
    'h-11 w-full rounded-xl border border-transparent bg-muted/60 px-3 text-sm outline-none transition-colors focus-visible:border-chat-accent/40 focus-visible:bg-background focus-visible:ring-2 focus-visible:ring-chat-accent/20';
</script>

<template>
    <Head title="Privacy settings" />

    <div class="space-y-6">
        <section
            class="rounded-2xl border border-black/5 bg-telegram-panel p-5 shadow-sm sm:p-6 dark:border-white/10"
        >
            <Heading
                variant="small"
                title="Privacy"
                description="Choose who can see your activity and contact you."
            />

            <form class="mt-5 space-y-5" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="last_seen_visibility">Last seen</Label>
                    <select
                        id="last_seen_visibility"
                        v-model="form.last_seen_visibility"
                        :class="selectClass"
                    >
                        <option value="everyone">Everyone</option>
                        <option value="contacts">My contacts</option>
                        <option value="nobody">Nobody</option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Controls whether your online status and last seen time
                        are shown to other people.
                    </p>
                    <InputError :message="form.errors.last_seen_visibility" />
                </div>

                <div class="grid gap-2">
                    <Label for="profile_visibility">Profile</Label>
                    <select
                        id="profile_visibility"
                        v-model="form.profile_visibility"
                        :class="selectClass"
                    >
                        <option value="everyone">Everyone</option>
                        <option value="contacts">My contacts</option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        People who cannot see your profile can still find you by
                        your exact username.
                    </p>
                    <InputError :message="form.errors.profile_visibility" />
                </div>

                <div class="grid gap-2">
                    <Label for="contact_requests_policy"
                        >Contact requests</Label
                    >
                    <select
                        id="contact_requests_policy"
                        v-model="form.contact_requests_policy"
                        :class="selectClass"
                    >
                        <option value="everyone">Everyone</option>
                        <option value="contacts">
                            People I share a group with
                        </option>
                        <option value="nobody">Nobody</option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Requests from other people still appear as pending, but
                        they will be rejected automatically when this is set to
                        nobody.
                    </p>
                    <InputError
                        :message="form.errors.contact_requests_policy"
                    />
                </div>

                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save privacy settings' }}
                </Button>
            </form>
        </section>

        <section
            class="rounded-2xl border border-black/5 bg-telegram-panel p-5 shadow-sm sm:p-6 dark:border-white/10"
        >
            <Heading
                variant="small"
                title="Your data"
                description="Download a copy of your account, contacts, conversations, and messages."
            />

            <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="exporting"
                    @click="exportData"
                >
                    <Download />
                    {{ exporting ? 'Preparing…' : 'Download my data' }}
                </Button>
                <p class="text-xs text-muted-foreground">
                    Exports are JSON and include only messages you authored.
                </p>
            </div>

            <div
                class="mt-6 flex items-start gap-3 rounded-xl bg-muted/50 px-4 py-3 text-sm"
            >
                <ShieldCheck class="mt-0.5 size-4 shrink-0 text-chat-accent" />
                <p class="text-muted-foreground">
                    To permanently delete your account, visit
                    <Link
                        href="/settings/profile"
                        class="font-medium text-chat-accent hover:underline"
                    >
                        Profile settings
                    </Link>
                    and use the delete account section. Deletion revokes every
                    signed-in device.
                </p>
            </div>
        </section>
    </div>
</template>
