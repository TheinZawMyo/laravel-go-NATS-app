import { expect, test } from '@playwright/test';

test('guest can reach authentication and protected chat routes stay private', async ({ page }) => {
    await page.goto('/');

    await expect(
        page.getByRole('heading', { name: /conversations that feel effortless/i }),
    ).toBeVisible();
    await expect(page.getByRole('link', { name: 'Create free account' })).toBeVisible();

    await page.goto('/chats');
    await expect(page).toHaveURL(/\/login/);
});
