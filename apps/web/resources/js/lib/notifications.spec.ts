import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    notificationState,
    requestNotifications,
    showNotification,
} from './notifications';

type ShownNotification = {
    title: string;
    options?: NotificationOptions;
};

function stubNotification(
    permission: NotificationPermission,
    requestResult: NotificationPermission = permission,
): { shown: ShownNotification[]; requestPermission: ReturnType<typeof vi.fn> } {
    const shown: ShownNotification[] = [];
    const requestPermission = vi.fn(async () => requestResult);

    class FakeNotification {
        public static permission = permission;

        public static requestPermission = requestPermission;

        public constructor(title: string, options?: NotificationOptions) {
            shown.push({ title, options });
        }
    }

    vi.stubGlobal('Notification', FakeNotification);

    return { shown, requestPermission };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('notifications', () => {
    it('reports unsupported when the browser lacks the API', () => {
        delete (window as { Notification?: unknown }).Notification;

        expect(notificationState()).toBe('unsupported');
        expect(showNotification('Ada', 'Hello')).toBe(false);
    });

    it('reflects the current browser permission', () => {
        stubNotification('denied');

        expect(notificationState()).toBe('denied');
    });

    it('only asks the browser while the permission is undecided', async () => {
        const { requestPermission } = stubNotification('default', 'granted');

        await expect(requestNotifications()).resolves.toBe('granted');
        expect(requestPermission).toHaveBeenCalledTimes(1);
    });

    it('does not re-prompt once a permission was recorded', async () => {
        const { requestPermission } = stubNotification('denied');

        await expect(requestNotifications()).resolves.toBe('denied');
        expect(requestPermission).not.toHaveBeenCalled();
    });

    it('shows a tagged notification when permission is granted', () => {
        const { shown } = stubNotification('granted');

        expect(showNotification('Ada', 'Hello', 'conversation-1')).toBe(true);
        expect(shown).toEqual([
            {
                title: 'Ada',
                options: { body: 'Hello', tag: 'conversation-1' },
            },
        ]);
    });

    it('stays silent when permission was not granted', () => {
        const { shown } = stubNotification('default');

        expect(showNotification('Ada', 'Hello', 'conversation-1')).toBe(false);
        expect(shown).toHaveLength(0);
    });
});
