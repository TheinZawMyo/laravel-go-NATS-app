<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/composables/useInitials';
import { translationLanguages } from '@/lib/languages';
import { send } from '@/routes/verification';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const inputClass =
    'h-11 rounded-xl border-transparent bg-muted/60 px-4 shadow-none transition-colors focus-visible:border-chat-accent/40 focus-visible:bg-background focus-visible:ring-chat-accent/20';
const selectClass =
    'h-11 w-full rounded-xl border border-transparent bg-muted/60 px-3 text-sm outline-none transition-colors focus-visible:border-chat-accent/40 focus-visible:bg-background focus-visible:ring-2 focus-visible:ring-chat-accent/20';
</script>

<template>
    <Head title="Profile settings" />

    <div class="space-y-6">
        <section
            class="flex items-center gap-4 rounded-2xl border border-black/5 bg-telegram-panel p-5 shadow-sm dark:border-white/10"
        >
            <Avatar
                class="size-16 ring-2 ring-chat-accent/20 ring-offset-2 ring-offset-telegram-panel"
            >
                <AvatarImage
                    v-if="user.avatar_url"
                    :src="user.avatar_url"
                    :alt="user.name"
                />
                <AvatarFallback
                    class="bg-chat-accent text-lg font-semibold text-white"
                >
                    {{ getInitials(user.name) }}
                </AvatarFallback>
            </Avatar>

            <div class="min-w-0">
                <p class="truncate text-lg font-semibold tracking-tight">
                    {{ user.name }}
                </p>
                <p class="truncate text-sm text-muted-foreground">
                    @{{ user.username }}
                </p>
                <p
                    v-if="user.status_text"
                    class="mt-1 truncate text-xs text-chat-accent"
                >
                    {{ user.status_text }}
                </p>
            </div>
        </section>

        <section
            class="rounded-2xl border border-black/5 bg-telegram-panel p-5 shadow-sm sm:p-6 dark:border-white/10"
        >
            <Heading
                variant="small"
                title="Profile"
                description="Update your name, username, and public profile"
            />

            <Form
                v-bind="ProfileController.update.form()"
                class="mt-5 space-y-5"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name" class="text-sm font-medium">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="user.name"
                        required
                        autocomplete="name"
                        placeholder="Full name"
                        :class="inputClass"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="username" class="text-sm font-medium"
                        >Username</Label
                    >
                    <Input
                        id="username"
                        name="username"
                        :default-value="user.username"
                        required
                        autocomplete="username"
                        placeholder="your_username"
                        :class="inputClass"
                    />
                    <p class="text-xs text-muted-foreground">
                        3-30 characters. Lowercase letters, numbers, dashes, and
                        underscores.
                    </p>
                    <InputError :message="errors.username" />
                </div>

                <div class="grid gap-2">
                    <Label for="email" class="text-sm font-medium"
                        >Email address</Label
                    >
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        :default-value="user.email"
                        required
                        autocomplete="username"
                        placeholder="Email address"
                        :class="inputClass"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="status_text" class="text-sm font-medium"
                        >Status</Label
                    >
                    <Input
                        id="status_text"
                        name="status_text"
                        :default-value="user.status_text ?? ''"
                        autocomplete="off"
                        placeholder="Available"
                        maxlength="100"
                        :class="inputClass"
                    />
                    <InputError :message="errors.status_text" />
                </div>

                <div class="grid gap-2">
                    <Label for="bio" class="text-sm font-medium">Bio</Label>
                    <Textarea
                        id="bio"
                        name="bio"
                        :default-value="user.bio ?? ''"
                        placeholder="Tell people a little about yourself"
                        rows="4"
                        maxlength="500"
                        class="rounded-xl border-transparent bg-muted/60 px-4 py-3 shadow-none focus-visible:border-chat-accent/40 focus-visible:bg-background focus-visible:ring-chat-accent/20"
                    />
                    <InputError :message="errors.bio" />
                </div>

                <div class="grid gap-2">
                    <Label for="translation_language" class="text-sm font-medium"
                        >Translation language</Label
                    >
                    <select
                        id="translation_language"
                        name="translation_language"
                        :class="selectClass"
                    >
                        <option
                            value=""
                            :selected="user.translation_language === null"
                        >
                            No default — ask per message
                        </option>
                        <option
                            v-for="language in translationLanguages"
                            :key="language.code"
                            :value="language.code"
                            :selected="
                                user.translation_language === language.code
                            "
                        >
                            {{ language.name }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Messages are translated into this language by default.
                        You can still choose another language for each message.
                    </p>
                    <InputError :message="errors.translation_language" />
                </div>

                <div
                    v-if="page.props.mustVerifyEmail && !user.email_verified_at"
                    class="rounded-xl bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300"
                >
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="font-medium underline underline-offset-4 hover:text-amber-900 dark:hover:text-amber-100"
                    >
                        Click here to re-send the verification email.
                    </Link>

                    <p
                        v-if="page.props.status === 'verification-link-sent'"
                        class="mt-2 font-medium"
                    >
                        A new verification link has been sent to your email
                        address.
                    </p>
                </div>

                <div
                    class="flex items-center justify-end border-t border-black/5 pt-5 dark:border-white/10"
                >
                    <Button
                        :disabled="processing"
                        class="h-10 rounded-xl bg-chat-accent px-6 font-semibold text-white shadow-md shadow-chat-accent/20 hover:bg-chat-accent/90"
                        data-test="update-profile-button"
                    >
                        Save changes
                    </Button>
                </div>
            </Form>
        </section>

        <section
            class="rounded-2xl border border-black/5 bg-telegram-panel p-5 shadow-sm sm:p-6 dark:border-white/10"
        >
            <DeleteUser />
        </section>
    </div>
</template>
