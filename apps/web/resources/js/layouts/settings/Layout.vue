<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronRight,
    Eye,
    LogOut,
    Palette,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import { Toaster } from '@/components/ui/sonner';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useInitials } from '@/composables/useInitials';
import { toUrl } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { index as chats } from '@/routes/chats';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const navItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
        icon: UserRound,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Privacy',
        href: '/settings/privacy',
        icon: Eye,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
];

const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();
const { isCurrentOrParentUrl } = useCurrentUrl();

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <div
        class="flex h-dvh w-full overflow-hidden bg-telegram-bg text-foreground"
    >
        <aside
            class="hidden w-[21rem] shrink-0 flex-col border-r border-black/5 bg-telegram-panel md:flex dark:border-white/10"
        >
            <header
                class="flex h-16 shrink-0 items-center gap-3 border-b border-black/5 px-5 dark:border-white/10"
            >
                <Link
                    :href="chats()"
                    class="flex size-9 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-telegram-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-chat-accent focus-visible:outline-none"
                    aria-label="Back to chats"
                >
                    <ArrowLeft class="size-5" />
                </Link>
                <h1 class="text-lg font-semibold tracking-tight">Settings</h1>
            </header>

            <div
                v-if="user !== null"
                class="border-b border-black/5 p-4 dark:border-white/10"
            >
                <div
                    class="flex items-center gap-3 rounded-2xl bg-muted/50 p-3"
                >
                    <Avatar
                        class="size-12 ring-1 ring-black/5 dark:ring-white/10"
                    >
                        <AvatarImage
                            v-if="user.avatar_url"
                            :src="user.avatar_url"
                            :alt="user.name"
                        />
                        <AvatarFallback
                            class="bg-chat-accent/10 font-semibold text-chat-accent"
                        >
                            {{ getInitials(user.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">
                            {{ user.name }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            @{{ user.username }}
                        </p>
                    </div>
                </div>
            </div>

            <nav
                class="flex-1 space-y-1 overflow-y-auto p-3"
                aria-label="Settings"
            >
                <Link
                    v-for="item in navItems"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    class="group flex items-center gap-3 rounded-2xl px-3 py-2.5 transition-colors focus-visible:ring-2 focus-visible:ring-chat-accent focus-visible:outline-none"
                    :class="
                        isCurrentOrParentUrl(item.href)
                            ? 'bg-chat-accent/10 text-chat-accent'
                            : 'text-foreground hover:bg-telegram-hover'
                    "
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-full transition-colors"
                        :class="
                            isCurrentOrParentUrl(item.href)
                                ? 'bg-chat-accent text-white'
                                : 'bg-muted text-muted-foreground group-hover:text-foreground'
                        "
                    >
                        <component :is="item.icon" class="size-4" />
                    </span>
                    <span class="flex-1 text-sm font-medium">
                        {{ item.title }}
                    </span>
                    <ChevronRight
                        class="size-4 shrink-0"
                        :class="
                            isCurrentOrParentUrl(item.href)
                                ? 'text-chat-accent'
                                : 'text-muted-foreground'
                        "
                    />
                </Link>
            </nav>

            <footer class="border-t border-black/5 p-3 dark:border-white/10">
                <Link
                    :href="logout()"
                    as="button"
                    class="flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left text-sm font-medium text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-destructive focus-visible:outline-none"
                    data-test="logout-button"
                    @click="handleLogout"
                >
                    <span
                        class="flex size-9 items-center justify-center rounded-full bg-muted"
                    >
                        <LogOut class="size-4" />
                    </span>
                    Log out
                </Link>
            </footer>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="flex h-16 shrink-0 items-center gap-3 border-b border-black/5 bg-telegram-panel px-4 md:hidden dark:border-white/10"
            >
                <Link
                    :href="chats()"
                    class="flex size-9 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-telegram-hover hover:text-foreground"
                    aria-label="Back to chats"
                >
                    <ArrowLeft class="size-5" />
                </Link>
                <h1 class="text-lg font-semibold tracking-tight">Settings</h1>
            </header>

            <nav
                class="flex shrink-0 gap-2 overflow-x-auto border-b border-black/5 bg-telegram-panel px-4 py-3 md:hidden dark:border-white/10"
                aria-label="Settings"
            >
                <Link
                    v-for="item in navItems"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    class="rounded-full px-4 py-2 text-xs font-semibold whitespace-nowrap transition-colors"
                    :class="
                        isCurrentOrParentUrl(item.href)
                            ? 'bg-chat-accent text-white shadow-sm'
                            : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                >
                    {{ item.title }}
                </Link>
            </nav>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div
                    class="mx-auto w-full max-w-2xl px-4 py-6 sm:px-8 sm:py-10"
                >
                    <slot />
                </div>
            </div>
        </div>

        <Toaster />
    </div>
</template>
