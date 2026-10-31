import type { ChatMessage, Conversation, PublicUser } from '@/types';

const timeFormatter = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
});

const dayFormatter = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
});

const dateFormatter = new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});

const weekdayFormatter = new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    month: 'short',
    day: 'numeric',
});

const weekdayYearFormatter = new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
});

function startOfDay(date: Date): number {
    return new Date(
        date.getFullYear(),
        date.getMonth(),
        date.getDate(),
    ).getTime();
}

export function formatMessageTime(value: string | null): string {
    if (value === null) {
        return '';
    }

    return timeFormatter.format(new Date(value));
}

export function formatConversationTime(value: string | null): string {
    if (value === null) {
        return '';
    }

    const date = new Date(value);
    const now = new Date();

    if (date.toDateString() === now.toDateString()) {
        return timeFormatter.format(date);
    }

    if (date.getFullYear() === now.getFullYear()) {
        return dayFormatter.format(date);
    }

    return dateFormatter.format(date);
}

/**
 * Presence is a client-side nicety: the server only stores the raw timestamp,
 * so the wording is derived here to stay relative to the viewer's clock. The
 * `nowMs` argument lets a ticking component recompute labels on an interval.
 */
export function formatLastSeen(
    value: string | null,
    nowMs: number = Date.now(),
): string {
    if (value === null) {
        return 'Offline';
    }

    const seen = new Date(value);

    if (Number.isNaN(seen.getTime())) {
        return 'Offline';
    }

    const seconds = Math.max(0, Math.round((nowMs - seen.getTime()) / 1000));

    if (seconds < 60) {
        return 'Last seen just now';
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `Last seen ${minutes}m ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `Last seen ${hours}h ago`;
    }

    const days = Math.round(hours / 24);

    if (days < 7) {
        return `Last seen ${days}d ago`;
    }

    return `Last seen ${dateFormatter.format(seen)}`;
}

/**
 * One label for a member's live state, used by every presence surface so the
 * conversation list and the chat header never disagree.
 */
export function formatPresence(
    user: Pick<PublicUser, 'online' | 'last_seen_at'> | null,
    nowMs: number = Date.now(),
): string | null {
    if (user === null) {
        return null;
    }

    if (user.online === true) {
        return 'Online';
    }

    return formatLastSeen(user.last_seen_at, nowMs);
}

/**
 * A stable, timezone aware key for the calendar day a message belongs to. Two
 * messages share a key exactly when they need the same date divider.
 */
export function messageDayKey(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return `${date.getFullYear()}-${date.getMonth() + 1}-${date.getDate()}`;
}

/**
 * The divider wording for a day: "Today" and "Yesterday" stay short, older
 * days get the weekday, and another year is spelled out.
 */
export function formatMessageDay(
    value: string,
    nowMs: number = Date.now(),
): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const now = new Date(nowMs);
    // Rounding absorbs the 23/25 hour days produced by daylight saving shifts.
    const elapsedDays = Math.round(
        (startOfDay(now) - startOfDay(date)) / 86_400_000,
    );

    if (elapsedDays === 0) {
        return 'Today';
    }

    if (elapsedDays === 1) {
        return 'Yesterday';
    }

    return date.getFullYear() === now.getFullYear()
        ? weekdayFormatter.format(date)
        : weekdayYearFormatter.format(date);
}

export function conversationTitle(conversation: Conversation | null): string {
    if (conversation === null) {
        return 'Chats';
    }

    return (
        conversation.title ??
        conversation.participant?.name ??
        conversation.participant?.username ??
        'Conversation'
    );
}

export function messagePreview(message: ChatMessage | null): string {
    if (message === null) {
        return 'No messages yet';
    }

    if (message.deleted_at !== null) {
        return 'Message deleted';
    }

    const body = message.body?.trim();

    return body && body.length > 0 ? body : 'No messages yet';
}
