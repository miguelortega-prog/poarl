const focusableSelectors = [
    'a[href]:not([tabindex="-1"])',
    'area[href]:not([tabindex="-1"])',
    'input:not([disabled]):not([tabindex="-1"])',
    'select:not([disabled]):not([tabindex="-1"])',
    'textarea:not([disabled]):not([tabindex="-1"])',
    'button:not([disabled]):not([tabindex="-1"])',
    '[tabindex]:not([tabindex="-1"])',
    '[contenteditable]:not([contenteditable="false"])',
].join(',');

const collectFocusable = (root) => {
    if (! root) {
        return [];
    }

    return Array.from(root.querySelectorAll(focusableSelectors)).filter((element) => {
        if (element.hasAttribute('disabled')) {
            return false;
        }

        const style = window.getComputedStyle(element);

        return ! element.hasAttribute('aria-hidden')
            && style.display !== 'none'
            && style.visibility !== 'hidden';
    });
};

const findTrapRoot = (el) => {
    return el.closest('.jetstream-modal') ?? el;
};

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    if (! Alpine) {
        return;
    }

    Alpine.directive('trap', (el, { modifiers, expression }, { cleanup, effect, evaluateLater }) => {
        const evaluate = evaluateLater(expression);

        let active = false;
        let releaseInert = () => {};
        let releaseNoScroll = () => {};
        let restoreTabIndex = () => {};

        const trapRoot = findTrapRoot(el);

        const ensureFocusableContainer = () => {
            if (el.hasAttribute('tabindex')) {
                return;
            }

            el.setAttribute('tabindex', '-1');

            restoreTabIndex = () => {
                el.removeAttribute('tabindex');
            };
        };

        const focusFirstElement = () => {
            const focusable = collectFocusable(el);

            if (focusable.length > 0) {
                focusable[0].focus({ preventScroll: true });

                return;
            }

            if (el instanceof HTMLElement) {
                el.focus({ preventScroll: true });
            }
        };

        const handleFocusIn = (event) => {
            if (! active) {
                return;
            }

            if (el.contains(event.target)) {
                return;
            }

            focusFirstElement();
        };

        const handleKeydown = (event) => {
            if (! active || event.key !== 'Tab') {
                return;
            }

            const focusable = collectFocusable(el);

            if (focusable.length === 0) {
                event.preventDefault();
                focusFirstElement();

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
            if (! modifiers.includes('inert') || ! trapRoot?.parentElement) {
                return () => {};
            }

            const siblings = Array.from(trapRoot.parentElement.children).filter((child) => child !== trapRoot);

            siblings.forEach((sibling) => sibling.setAttribute('inert', 'true'));

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
            const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;

            document.documentElement.style.overflow = 'hidden';

            if (scrollbarWidth > 0) {
                document.documentElement.style.paddingRight = `${scrollbarWidth}px`;
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

            ensureFocusableContainer();

            releaseInert = applyInert();
            releaseNoScroll = applyNoScroll();

            document.addEventListener('focusin', handleFocusIn, true);
            el.addEventListener('keydown', handleKeydown, true);

            queueMicrotask(focusFirstElement);
        };

        const deactivate = () => {
            if (! active) {
                return;
            }

            active = false;

            document.removeEventListener('focusin', handleFocusIn, true);
            el.removeEventListener('keydown', handleKeydown, true);

            releaseInert();
            releaseNoScroll();
            restoreTabIndex();

            releaseInert = () => {};
            releaseNoScroll = () => {};
            restoreTabIndex = () => {};
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
