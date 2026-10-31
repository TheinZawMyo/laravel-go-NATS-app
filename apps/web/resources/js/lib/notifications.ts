/**
 * Thin wrapper around the browser Notification API.
 *
 * Notifications are strictly a foreground convenience: the server keeps the
 * durable record, so a denied or unsupported browser loses nothing but a
 * nudge while the tab is in the background.
 */
export type NotificationState =
    | 'unsupported'
    | 'default'
    | 'granted'
    | 'denied';

export function notificationState(): NotificationState {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return 'unsupported';
    }

    return Notification.permission as NotificationState;
}

export async function requestNotifications(): Promise<NotificationState> {
    const current = notificationState();

    if (current !== 'default') {
        return current;
    }

    const permission = await Notification.requestPermission();

    return permission as NotificationState;
}

export function showNotification(
    title: string,
    body: string,
    tag?: string,
): boolean {
    if (notificationState() !== 'granted') {
        return false;
    }

    try {
        new Notification(title, {
            body,
            // The tag collapses repeats from the same conversation into one
            // tray entry instead of stacking a notification per message.
            tag,
        });

        return true;
    } catch {
        return false;
    }
}
