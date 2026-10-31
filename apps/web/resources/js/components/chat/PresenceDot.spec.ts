import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PresenceDot from './PresenceDot.vue';

describe('PresenceDot', () => {
    it('renders an accessible dot while the member is online', () => {
        const wrapper = mount(PresenceDot, {
            props: { online: true, name: 'Alice' },
        });

        const dot = wrapper.find('[role="img"]');

        expect(dot.exists()).toBe(true);
        expect(dot.attributes('aria-label')).toBe('Alice is online');
    });

    it('renders nothing while the member is offline', () => {
        const wrapper = mount(PresenceDot, {
            props: { online: false, name: 'Alice' },
        });

        expect(wrapper.find('[role="img"]').exists()).toBe(false);
    });

    it('accepts the size and border overrides used on active rows', () => {
        const wrapper = mount(PresenceDot, {
            props: {
                online: true,
                name: 'Alice',
                sizeClass: 'size-3',
                borderClass: 'border-chat-accent',
            },
        });

        const classes = wrapper.find('[role="img"]').classes();

        expect(classes).toContain('size-3');
        expect(classes).toContain('border-chat-accent');
        expect(classes).not.toContain('size-3.5');
    });
});
