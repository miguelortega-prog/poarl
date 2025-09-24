const focusableSelectors = [
    'a[href]:not([tabindex="-1"])',
    'area[href]:not([tabindex="-1"])',
    'input:not([disabled]):not([tabindex="-1"])',
    'select:not([disabled]):not([tabindex="-1"])',
    'textarea:not([disabled]):not([tabindex="-1"])',
    'button:not([disabled]):not([tabindex="-1"])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const collectFocusable = (root) => {
    return Array.from(root.querySelectorAll(focusableSelectors)).filter(
        (element) => ! element.hasAttribute('disabled') && ! element.getAttribute('aria-hidden')
    );
};

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    if (! Alpine) {
        return;
    }

    Alpine.directive('trap', (el, { expression, modifiers }, { cleanup, effect, evaluateLater }) => {
        const evaluate = evaluateLater(expression);

        let active = false;
        let previousFocused = null;
        let releaseInert = () => {};
        let releaseNoScroll = () => {};

        const enforceFocus = (event) => {
            if (! active) {
                return;
            }

            if (el.contains(event.target)) {
                return;
            }

            const focusable = collectFocusable(el);

            if (focusable.length === 0) {
                return;
            }

            focusable[0].focus({ preventScroll: true });
        };

        const handleKeydown = (event) => {
            if (! active || event.key !== 'Tab') {
                return;
            }

            const focusable = collectFocusable(el);

            if (focusable.length === 0) {
                event.preventDefault();
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            const current = document.activeElement;

            if (event.shiftKey) {
                if (current === first || ! el.contains(current)) {
                    event.preventDefault();
                    last.focus({ preventScroll: true });
                }

                return;
            }

            if (current === last) {
                event.preventDefault();
                first.focus({ preventScroll: true });
            }
        };

        const applyInert = () => {
            if (! modifiers.includes('inert')) {
                return () => {};
            }

            const siblings = Array.from(document.body.children).filter((child) => ! child.contains(el));
            siblings.forEach((sibling) => sibling.setAttribute('inert', ''));

            return () => {
                siblings.forEach((sibling) => sibling.removeAttribute('inert'));
            };
        };

        const applyNoScroll = () => {
            if (! modifiers.includes('noscroll')) {
                return () => {};
            }

            const originalOverflow = document.documentElement.style.overflow;
            const originalPaddingRight = document.documentElement.style.paddingRight;

            const scrollBarWidth = window.innerWidth - document.documentElement.clientWidth;

            document.documentElement.style.overflow = 'hidden';

            if (scrollBarWidth > 0) {
                document.documentElement.style.paddingRight = `${scrollBarWidth}px`;
            }

            return () => {
                document.documentElement.style.overflow = originalOverflow;
                document.documentElement.style.paddingRight = originalPaddingRight;
            };
        };

        const activate = () => {
            if (active) {
                return;
            }

            active = true;
            previousFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

            releaseInert = applyInert();
            releaseNoScroll = applyNoScroll();

            document.addEventListener('focusin', enforceFocus, true);
            el.addEventListener('keydown', handleKeydown, true);

            const focusable = collectFocusable(el);
            if (focusable.length > 0) {
                focusable[0].focus({ preventScroll: true });
            } else {
                el.focus({ preventScroll: true });
            }
        };

        const deactivate = () => {
            if (! active) {
                return;
            }

            active = false;

            document.removeEventListener('focusin', enforceFocus, true);
            el.removeEventListener('keydown', handleKeydown, true);

            releaseInert();
            releaseNoScroll();

            if (previousFocused && previousFocused.focus) {
                previousFocused.focus({ preventScroll: true });
            }
        };

        effect(() => {
            evaluate((value) => {
                if (Boolean(value)) {
                    activate();
                } else {
                    deactivate();
                }
            });
        });

        cleanup(() => {
            deactivate();
        });
    });
});
