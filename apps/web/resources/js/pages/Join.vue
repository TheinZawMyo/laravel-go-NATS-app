<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { ApiError, apiRequest } from '@/lib/http';
import type { ConversationInvite, JoinPageProps } from '@/types';

const props = defineProps<JoinPageProps>();

const { getInitials } = useInitials();

const invite = ref<ConversationInvite | null>(null);
const loading = ref(true);
const joining = ref(false);
const error = ref<string | null>(null);

/**
 * The preview is fetched over the API rather than rendered server side so the
 * page can explain *why* a link is unusable (revoked, expired, exhausted) with
 * the same validation messages the join request would produce.
 */
onMounted(async () => {
    try {
        const response = await apiRequest<{ data: ConversationInvite }>(
            `/api/v1/invites/${props.token}`,
        );

        invite.value = response.data;
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? (requestError.errors.token?.[0] ?? requestError.message)
                : 'This invite link is not valid.';
    } finally {
        loading.value = false;
    }
});

async function join(): Promise<void> {
    if (joining.value) {
        return;
    }

    joining.value = true;
    error.value = null;

    try {
        const response = await apiRequest<{ data: { id: string } }>(
            `/api/v1/invites/${props.token}/join`,
            { method: 'POST' },
        );

        router.visit(`/chats/${response.data.id}`);
    } catch (requestError) {
        error.value =
            requestError instanceof ApiError
                ? (requestError.errors.token?.[0] ?? requestError.message)
                : 'Unable to join this group.';

        joining.value = false;
    }
}

function title(): string {
    return invite.value?.conversation?.title ?? 'this group';
}
</script>

<template>
    <Head title="Group invite" />

    <div class="flex min-h-dvh items-center justify-center px-4 py-10">
        <div
            class="w-full max-w-sm rounded-3xl border border-border/70 bg-background p-6 shadow-xl shadow-black/5"
        >
            <div
                v-if="loading"
                class="flex flex-col items-center gap-3 py-10 text-sm text-muted-foreground"
            >
                <Spinner />
                Loading invite…
            </div>

            <template v-else-if="invite !== null">
                <div class="flex flex-col items-center text-center">
                    <Avatar class="size-20 ring-1 ring-border/70">
                        <AvatarImage
                            v-if="invite.conversation?.avatar_url"
                            :src="invite.conversation.avatar_url"
                            :alt="title()"
                        />
                        <AvatarFallback class="text-xl">
                            {{ getInitials(title()) }}
                        </AvatarFallback>
                    </Avatar>

                    <p
                        class="mt-4 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        Group invite
                    </p>
                    <h1 class="mt-1 text-xl font-semibold tracking-tight">
                        {{ title() }}
                    </h1>
                    <p
                        v-if="invite.conversation?.description"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        {{ invite.conversation.description }}
                    </p>
                    <p
                        class="mt-2 inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <Users class="size-3.5" />
                        {{ invite.conversation?.member_count ?? 0 }} members
                    </p>
                </div>

                <p
                    v-if="error !== null"
                    class="mt-4 text-center text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <p
                    v-else-if="!invite.is_active"
                    class="mt-4 text-center text-sm text-destructive"
                >
                    This invite link can no longer be used.
                </p>

                <Button
                    type="button"
                    class="mt-6 w-full"
                    :disabled="joining || !invite.is_active"
                    @click="join"
                >
                    {{ joining ? 'Joining…' : 'Join group' }}
                </Button>
            </template>

            <template v-else>
                <div class="flex flex-col items-center text-center">
                    <div
                        class="flex size-14 items-center justify-center rounded-full bg-destructive/10 text-destructive"
                    >
                        <Users class="size-6" />
                    </div>
                    <h1 class="mt-4 text-lg font-semibold">
                        Invite unavailable
                    </h1>
                    <p class="mt-2 text-sm text-destructive">{{ error }}</p>
                </div>
            </template>

            <Button type="button" variant="ghost" class="mt-2 w-full" as-child>
                <Link href="/chats">Back to chats</Link>
            </Button>
        </div>
    </div>
</template>
