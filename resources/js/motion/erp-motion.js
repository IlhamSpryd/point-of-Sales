import gsap from 'gsap';

/**
 * Apeiron Systems — Centralized GSAP Motion Controller (`erp-motion.js`)
 *
 * Principles:
 * 1. Respects `prefers-reduced-motion: reduce` (`gsap.set` with 0s duration).
 * 2. Cleans up active `gsap.context()` on `livewire:navigating` to prevent SPA memory leaks.
 * 3. Only animates GPU-composited properties (`x`, `y`, `scale`, `opacity`) with `overwrite: 'auto'`.
 * 4. Caps stagger counts (max 24 items) and never attaches continuous tickers to dense ERP tables.
 */

const MOTION_TOKENS = {
    duration: {
        micro: 0.12,
        fast: 0.2,
        medium: 0.32,
    },
    ease: {
        out: 'power3.out',
        inOut: 'power2.inOut',
        panel: 'power2.out',
    },
    stagger: 0.04,
    maxStaggerItems: 24,
};

let activePageContext = null;
let directivesRegistered = false;

export function prefersReducedMotion() {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function resolveElement(elOrSelector) {
    if (!elOrSelector) return null;
    if (typeof elOrSelector === 'string') {
        return document.querySelector(elOrSelector);
    }
    return elOrSelector;
}

export function cleanupMotionContext() {
    if (activePageContext) {
        activePageContext.revert();
        activePageContext = null;
    }
}

export function animatePageEnter(root = document) {
    const scope = root instanceof Element || root === document ? root : document;
    const pageEls = Array.from(scope.querySelectorAll('[data-gsap="page-enter"]'));
    const kpiCards = Array.from(scope.querySelectorAll('[data-gsap="kpi-card"]')).slice(0, MOTION_TOKENS.maxStaggerItems);
    const kdsCards = Array.from(scope.querySelectorAll('[data-gsap="kds-card"]')).slice(0, MOTION_TOKENS.maxStaggerItems);

    if (pageEls.length === 0 && kpiCards.length === 0 && kdsCards.length === 0) {
        return;
    }

    if (prefersReducedMotion()) {
        gsap.set([...pageEls, ...kpiCards, ...kdsCards], { opacity: 1, y: 0, scale: 1, clearProps: 'transform' });
        return;
    }

    cleanupMotionContext();

    activePageContext = gsap.context(() => {
        if (pageEls.length > 0) {
            gsap.fromTo(
                pageEls,
                { opacity: 0, y: 8 },
                {
                    opacity: 1,
                    y: 0,
                    duration: MOTION_TOKENS.duration.fast,
                    ease: MOTION_TOKENS.ease.out,
                    overwrite: 'auto',
                    clearProps: 'transform',
                }
            );
        }

        if (kpiCards.length > 0) {
            gsap.fromTo(
                kpiCards,
                { opacity: 0, y: 10 },
                {
                    opacity: 1,
                    y: 0,
                    duration: MOTION_TOKENS.duration.fast + 0.04,
                    stagger: MOTION_TOKENS.stagger,
                    ease: MOTION_TOKENS.ease.out,
                    overwrite: 'auto',
                    clearProps: 'transform',
                }
            );
        }

        if (kdsCards.length > 0) {
            gsap.fromTo(
                kdsCards,
                { opacity: 0, scale: 0.98, y: 6 },
                {
                    opacity: 1,
                    scale: 1,
                    y: 0,
                    duration: MOTION_TOKENS.duration.fast,
                    stagger: 0.03,
                    ease: MOTION_TOKENS.ease.out,
                    overwrite: 'auto',
                    clearProps: 'transform',
                }
            );
        }
    }, scope === document ? document.body : scope);
}

export function animateAuthScreen() {
    const allAnimElems = document.querySelectorAll('.js-anim');
    if (allAnimElems.length === 0) return;

    const panelRight = document.getElementById('anim-panel-right');

    if (prefersReducedMotion()) {
        gsap.set(allAnimElems, { opacity: 1, y: 0, clearProps: 'transform' });
        if (panelRight) gsap.set(panelRight, { opacity: 1 });
        return;
    }

    cleanupMotionContext();

    activePageContext = gsap.context(() => {
        gsap.set(allAnimElems, { opacity: 0, y: 10 });
        if (panelRight) {
            gsap.set(panelRight, { opacity: 0 });
        }

        const tl = gsap.timeline({ defaults: { ease: MOTION_TOKENS.ease.out, overwrite: 'auto' } });

        if (panelRight) {
            tl.to(panelRight, { opacity: 1, duration: 0.42, ease: MOTION_TOKENS.ease.panel }, 0);
        }

        const heading = document.getElementById('anim-heading');
        if (heading) {
            tl.to(heading, { opacity: 1, y: 0, duration: MOTION_TOKENS.duration.medium }, 0.06);
        }

        const formGroupItems = [
            document.getElementById('anim-social'),
            document.getElementById('anim-divider'),
            document.getElementById('anim-field-email'),
            document.getElementById('anim-field-password'),
            document.getElementById('anim-submit'),
            document.getElementById('anim-footer-link'),
        ].filter(Boolean);

        if (formGroupItems.length > 0) {
            tl.to(
                formGroupItems,
                {
                    opacity: 1,
                    y: 0,
                    duration: 0.24,
                    stagger: MOTION_TOKENS.stagger,
                    clearProps: 'transform',
                },
                0.12
            );
        }
    }, document.body);
}

export function pulseHighlight(elOrSelector) {
    const el = resolveElement(elOrSelector);
    if (!el || prefersReducedMotion()) return;

    gsap.fromTo(
        el,
        { scale: 0.96 },
        {
            scale: 1,
            duration: 0.18,
            ease: 'back.out(2)',
            overwrite: 'auto',
            clearProps: 'transform',
        }
    );
}

export function shakeElement(elOrSelector) {
    const el = resolveElement(elOrSelector);
    if (!el || prefersReducedMotion()) return;

    gsap.fromTo(
        el,
        { x: -5 },
        {
            x: 0,
            duration: 0.24,
            ease: 'elastic.out(1.2, 0.3)',
            overwrite: 'auto',
            clearProps: 'transform',
        }
    );
}

export function registerGsapAlpineDirectives(AlpineInstance) {
    if (!AlpineInstance || directivesRegistered) return;
    directivesRegistered = true;

    // 1. x-gsap-modal: animates dialog panel when shown
    AlpineInstance.directive('gsap-modal', (el, { expression }, { effect, evaluateLater }) => {
        if (!expression) {
            if (!prefersReducedMotion()) {
                gsap.fromTo(
                    el,
                    { opacity: 0, scale: 0.97, y: 8 },
                    { opacity: 1, scale: 1, y: 0, duration: MOTION_TOKENS.duration.fast, ease: MOTION_TOKENS.ease.out, overwrite: 'auto', clearProps: 'transform' }
                );
            }
            return;
        }

        const getOpen = evaluateLater(expression);
        effect(() => {
            getOpen((isOpen) => {
                if (isOpen && !prefersReducedMotion()) {
                    const target = el.querySelector('[role="dialog"]') || el;
                    gsap.fromTo(
                        target,
                        { opacity: 0, scale: 0.97, y: 8 },
                        { opacity: 1, scale: 1, y: 0, duration: MOTION_TOKENS.duration.fast, ease: MOTION_TOKENS.ease.out, overwrite: 'auto', clearProps: 'transform' }
                    );
                }
            });
        });
    });

    // 2. x-gsap-drawer: animates slide-over drawer when shown
    AlpineInstance.directive('gsap-drawer', (el, { expression, modifiers }, { effect, evaluateLater }) => {
        const isLeft = (modifiers && modifiers.includes('left')) || el.classList.contains('left-0');
        const fromX = isLeft ? -20 : 20;

        if (!expression) {
            if (!prefersReducedMotion()) {
                gsap.fromTo(
                    el,
                    { opacity: 0.85, x: fromX },
                    { opacity: 1, x: 0, duration: 0.22, ease: MOTION_TOKENS.ease.panel, overwrite: 'auto', clearProps: 'transform' }
                );
            }
            return;
        }

        const getOpen = evaluateLater(expression);
        effect(() => {
            getOpen((isOpen) => {
                if (isOpen && !prefersReducedMotion()) {
                    gsap.fromTo(
                        el,
                        { opacity: 0.85, x: fromX },
                        { opacity: 1, x: 0, duration: 0.22, ease: MOTION_TOKENS.ease.panel, overwrite: 'auto', clearProps: 'transform' }
                    );
                }
            });
        });
    });

    // 3. x-gsap-stagger: staggers direct children on mount (capped at 24 children)
    AlpineInstance.directive('gsap-stagger', (el) => {
        const children = Array.from(el.children).slice(0, MOTION_TOKENS.maxStaggerItems);
        if (children.length === 0) return;

        if (prefersReducedMotion()) {
            gsap.set(children, { opacity: 1, y: 0, clearProps: 'transform' });
            return;
        }

        gsap.fromTo(
            children,
            { opacity: 0, y: 8 },
            {
                opacity: 1,
                y: 0,
                duration: MOTION_TOKENS.duration.fast,
                stagger: MOTION_TOKENS.stagger,
                ease: MOTION_TOKENS.ease.out,
                overwrite: 'auto',
                clearProps: 'transform',
            }
        );
    });

    // 4. x-gsap-collapse: smooth height expansion/collapse
    AlpineInstance.directive('gsap-collapse', (el, { expression }, { effect, evaluateLater }) => {
        if (!expression) return;
        const getExpanded = evaluateLater(expression);
        let initialized = false;

        effect(() => {
            getExpanded((expanded) => {
                if (prefersReducedMotion() || !initialized) {
                    el.style.height = expanded ? 'auto' : '0px';
                    el.style.overflow = expanded ? 'visible' : 'hidden';
                    el.style.opacity = expanded ? '1' : '0';
                    initialized = true;
                    return;
                }

                if (expanded) {
                    el.style.overflow = 'hidden';
                    gsap.fromTo(
                        el,
                        { height: 0, opacity: 0 },
                        {
                            height: 'auto',
                            opacity: 1,
                            duration: MOTION_TOKENS.duration.fast,
                            ease: MOTION_TOKENS.ease.out,
                            overwrite: 'auto',
                            onComplete: () => {
                                el.style.overflow = 'visible';
                            },
                        }
                    );
                } else {
                    el.style.overflow = 'hidden';
                    gsap.to(el, {
                        height: 0,
                        opacity: 0,
                        duration: 0.16,
                        ease: 'power2.in',
                        overwrite: 'auto',
                    });
                }
            });
        });
    });

    // 5. x-gsap-counter: smoothly animates numbers/KPIs/currency to target values
    AlpineInstance.directive('gsap-counter', (el, { expression, modifiers }, { effect, evaluateLater }) => {
        const mods = modifiers || [];
        const formatValue = (val) => {
            const num = Number(val) || 0;
            if (mods.includes('currency') || mods.includes('idr') || mods.includes('rp')) {
                return 'Rp ' + Math.round(num).toLocaleString('id-ID');
            }
            if (mods.includes('decimal') || mods.includes('float')) {
                return num.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            return Math.round(num).toLocaleString('id-ID');
        };

        const updateCounter = (targetVal) => {
            const targetNum = Number(targetVal) || 0;
            if (prefersReducedMotion()) {
                el.textContent = formatValue(targetNum);
                el.setAttribute('data-counter-val', String(targetNum));
                return;
            }

            const currentVal = parseFloat(el.getAttribute('data-counter-val')) || 0;
            const obj = { val: currentVal };

            gsap.to(obj, {
                val: targetNum,
                duration: 0.5,
                ease: MOTION_TOKENS.ease.out,
                overwrite: 'auto',
                onUpdate: () => {
                    el.textContent = formatValue(obj.val);
                    el.setAttribute('data-counter-val', String(obj.val));
                },
                onComplete: () => {
                    el.textContent = formatValue(targetNum);
                    el.setAttribute('data-counter-val', String(targetNum));
                },
            });
        };

        if (expression) {
            const getValue = evaluateLater(expression);
            effect(() => {
                getValue((val) => {
                    updateCounter(val);
                });
            });
        } else {
            const initialText = el.textContent.replace(/[^\d.-]/g, '');
            const initialVal = parseFloat(initialText) || 0;
            el.setAttribute('data-counter-val', '0');
            updateCounter(initialVal);
        }
    });
}

export const erpMotion = {
    prefersReducedMotion,
    animatePageEnter,
    animateAuthScreen,
    pulseHighlight,
    pulseElement: pulseHighlight,
    shakeElement,
    cleanup: cleanupMotionContext,
    cleanupMotionContext,
    registerGsapAlpineDirectives,
};

export const ApeironMotion = erpMotion;

export function initErpMotion() {
    if (typeof window === 'undefined') return;

    window.erpMotion = erpMotion;
    window.ApeironMotion = erpMotion;

    document.addEventListener('alpine:init', () => {
        if (window.Alpine) {
            registerGsapAlpineDirectives(window.Alpine);
        }
    });

    if (window.Alpine) {
        registerGsapAlpineDirectives(window.Alpine);
    }

    document.addEventListener('DOMContentLoaded', () => {
        animatePageEnter(document);
    });

    document.addEventListener('livewire:navigating', () => {
        cleanupMotionContext();
    });

    document.addEventListener('livewire:navigated', () => {
        animatePageEnter(document);
    });
}

export default erpMotion;
