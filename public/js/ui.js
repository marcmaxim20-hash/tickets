(() => {
    'use strict';

    const prefersReducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    document.addEventListener('DOMContentLoaded', () => {
        initThemeSwitcher();
        initStickyHeader();
        initScrollAnimations();
        initSeatBars();
        initPasswordEnhancements();
        initEventSearch();
        initDismissibleAlerts();
        initCopyButtons();
        initFormSubmitFeedback();
    });

    /**
     * Adds subtle elevation shadow when scrolling past top.
     */
    function initStickyHeader() {
        const header = document.querySelector('.site-header, .admin-header');
        if (!header) return;

        const syncScroll = () => {
            header.classList.toggle('is-scrolled', window.scrollY > 10);
        };
        syncScroll();
        window.addEventListener('scroll', syncScroll, { passive: true });
    }

    /**
     * Smoothly reveals cards, sections, and grid items with staggered timing.
     */
    function initScrollAnimations() {
        if (prefersReducedMotion) return;

        const staggerGroups = document.querySelectorAll(
            '.events-grid, .pricing-grid, .kpi-grid, .account-passes-grid, .steps-grid, .scanner-grid'
        );

        staggerGroups.forEach((group) => {
            Array.from(group.children).forEach((child, index) => {
                child.style.setProperty('--stagger-delay', `${Math.min(index * 65, 390)}ms`);
                child.classList.add('reveal-item');
            });
        });

        const standaloneTargets = document.querySelectorAll(
            '.hero-card, .event-hero-banner, .card, .pass-card, .door-screen, .scan-banner'
        );

        standaloneTargets.forEach((el) => {
            if (!el.closest('.events-grid, .pricing-grid, .kpi-grid, .account-passes-grid, .scanner-grid')) {
                el.classList.add('reveal-item');
            }
        });

        if (!('IntersectionObserver' in window)) {
            document.querySelectorAll('.reveal-item').forEach((el) => el.classList.add('is-visible'));
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.08, rootMargin: '0px 0px -24px 0px' }
        );

        document.querySelectorAll('.reveal-item').forEach((el) => observer.observe(el));
    }

    /**
     * Animates seat capacity bars smoothly from 0% to their target percentage.
     */
    function initSeatBars() {
        const fills = document.querySelectorAll('.seats-bar-fill');
        if (prefersReducedMotion || fills.length === 0) return;

        fills.forEach((fill) => {
            const targetWidth = fill.style.width || '0%';
            fill.style.width = '0%';
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    fill.style.width = targetWidth;
                });
            });
        });
    }

    /**
     * Adds intuitive Show/Hide eye toggle to password inputs, plus live strength
     * and password-match indicators on Register / Reset Password forms.
     */
    function initPasswordEnhancements() {
        const passwordInputs = document.querySelectorAll('input[type="password"]:not(.gate-code-input)');

        passwordInputs.forEach((input) => {
            if (input.parentElement?.classList.contains('password-input-wrap')) return;

            const wrapper = document.createElement('div');
            wrapper.className = 'password-input-wrap';
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);

            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'password-toggle-btn';
            toggleBtn.setAttribute('aria-label', 'Show password');
            toggleBtn.title = 'Show / hide password';
            toggleBtn.innerHTML = getEyeSvg(false);

            toggleBtn.addEventListener('click', () => {
                const showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                toggleBtn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                toggleBtn.classList.toggle('is-showing', !showing);
                toggleBtn.innerHTML = getEyeSvg(!showing);
                input.focus({ preventScroll: true });
            });

            wrapper.appendChild(toggleBtn);
        });

        // Live strength & confirmation match for Register / Reset Password forms
        const newPwd = document.querySelector('input#password[minlength]');
        const confirmPwd = document.querySelector('input#password_confirm');

        if (newPwd) {
            const meter = document.createElement('div');
            meter.className = 'pwd-strength';
            meter.innerHTML = `
                <div class="pwd-strength-track"><div class="pwd-strength-fill"></div></div>
                <span class="pwd-strength-label">Min. 6 characters</span>
            `;
            const targetWrap = newPwd.closest('.password-input-wrap') || newPwd;
            targetWrap.insertAdjacentElement('afterend', meter);

            const fillEl = meter.querySelector('.pwd-strength-fill');
            const labelEl = meter.querySelector('.pwd-strength-label');

            const updateStrength = () => {
                const val = newPwd.value;
                const score = computePasswordScore(val);
                meter.dataset.level = score.level;
                fillEl.style.width = `${score.percent}%`;
                labelEl.textContent = score.text;
            };

            newPwd.addEventListener('input', updateStrength);
        }

        if (newPwd && confirmPwd) {
            const matchHint = document.createElement('div');
            matchHint.className = 'pwd-match-hint';
            const confirmWrap = confirmPwd.closest('.password-input-wrap') || confirmPwd;
            confirmWrap.insertAdjacentElement('afterend', matchHint);

            const syncMatch = () => {
                if (!confirmPwd.value) {
                    matchHint.textContent = '';
                    matchHint.dataset.state = '';
                    return;
                }
                const matches = newPwd.value === confirmPwd.value;
                matchHint.dataset.state = matches ? 'match' : 'mismatch';
                matchHint.textContent = matches ? '✓ Passwords match' : 'Passwords do not match yet';
            };

            newPwd.addEventListener('input', syncMatch);
            confirmPwd.addEventListener('input', syncMatch);
        }
    }

    function computePasswordScore(val) {
        if (!val) return { level: 'idle', percent: 0, text: 'Min. 6 characters' };
        if (val.length < 6) return { level: 'weak', percent: 25, text: `${6 - val.length} more character${6 - val.length === 1 ? '' : 's'} needed` };

        let points = 1;
        if (val.length >= 10) points++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) points++;
        if (/\d/.test(val) || /[^A-Za-z0-9]/.test(val)) points++;

        if (points <= 1) return { level: 'fair', percent: 50, text: 'Fair password' };
        if (points === 2 || points === 3) return { level: 'good', percent: 78, text: 'Good password' };
        return { level: 'strong', percent: 100, text: 'Strong password' };
    }

    function getEyeSvg(open) {
        if (open) {
            return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
        }
        return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
    }

    /**
     * Instant live search filtering on the Events showcase grid.
     */
    function initEventSearch() {
        const searchInput = document.getElementById('event-live-search');
        const cards = document.querySelectorAll('.events-grid .event-card');
        const emptySearch = document.getElementById('events-search-empty');
        const clearBtn = document.getElementById('event-search-clear');

        if (!searchInput || cards.length === 0) return;

        const filterCards = () => {
            const query = searchInput.value.trim().toLowerCase();
            let visibleCount = 0;

            cards.forEach((card) => {
                const haystack = (card.dataset.searchText || card.textContent || '').toLowerCase();
                const match = query === '' || haystack.includes(query);
                card.hidden = !match;
                if (match) visibleCount++;
            });

            if (emptySearch) {
                emptySearch.hidden = visibleCount > 0;
            }
            if (clearBtn) {
                clearBtn.hidden = query === '';
            }
        };

        searchInput.addEventListener('input', filterCards);
        clearBtn?.addEventListener('click', () => {
            searchInput.value = '';
            filterCards();
            searchInput.focus();
        });
    }

    /**
     * Adds a subtle close button to flash alerts.
     */
    function initDismissibleAlerts() {
        document.querySelectorAll('.alert').forEach((alertEl) => {
            if (alertEl.querySelector('.alert-close')) return;
            // Keep inline local fallback reset block persistent
            if (alertEl.querySelector('a.btn')) return;

            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'alert-close';
            closeBtn.setAttribute('aria-label', 'Dismiss notification');
            closeBtn.innerHTML = '&times;';
            closeBtn.addEventListener('click', () => {
                alertEl.classList.add('is-dismissing');
                setTimeout(() => alertEl.remove(), 200);
            });
            alertEl.appendChild(closeBtn);
        });
    }

    /**
     * One-click copy for QR tokens on pass pages.
     */
    function initCopyButtons() {
        document.querySelectorAll('[data-copy-text]').forEach((btn) => {
            const originalHtml = btn.innerHTML;
            btn.addEventListener('click', async () => {
                const text = btn.getAttribute('data-copy-text') || '';
                if (!text || !navigator.clipboard) return;
                try {
                    await navigator.clipboard.writeText(text);
                    btn.classList.add('is-copied');
                    btn.textContent = '✓ Copied!';
                    setTimeout(() => {
                        btn.classList.remove('is-copied');
                        btn.innerHTML = originalHtml;
                    }, 1800);
                } catch (_) {}
            });
        });
    }

    /**
     * Gives immediate visual loading state on form submission.
     */
    function initFormSubmitFeedback() {
        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                if (!form.checkValidity()) return;
                const submitBtn = form.querySelector('button[type="submit"]:not([data-no-spinner])');
                if (!submitBtn || submitBtn.classList.contains('is-loading')) return;
                submitBtn.classList.add('is-loading');
            });
        });
    }

    /**
     * Interactive site color switcher (5 curated palettes + custom color picker).
     */
    function initThemeSwitcher() {
        const root = document.documentElement;
        const switcher = document.querySelector('[data-theme-switcher]');
        if (!switcher) return;

        const trigger = switcher.querySelector('[data-theme-trigger]');
        const popover = switcher.querySelector('[data-theme-popover]');
        const optionBtns = switcher.querySelectorAll('[data-set-theme]');
        const colorInput = switcher.querySelector('#theme-custom-color');

        const applyTheme = (themeName, customHex = null) => {
            if (themeName === 'sunset' || !themeName) {
                root.removeAttribute('data-theme');
                themeName = 'sunset';
            } else {
                root.setAttribute('data-theme', themeName);
            }

            if (themeName === 'custom') {
                const hex = customHex || localStorage.getItem('swiftdesk_custom_color') || '#e11d48';
                root.style.setProperty('--custom-accent', hex);
                if (colorInput) colorInput.value = hex;
                try {
                    localStorage.setItem('swiftdesk_custom_color', hex);
                } catch (_) {}
            } else {
                root.style.removeProperty('--custom-accent');
            }

            try {
                localStorage.setItem('swiftdesk_theme', themeName);
            } catch (_) {}

            optionBtns.forEach((btn) => {
                btn.classList.toggle('is-active', btn.getAttribute('data-set-theme') === themeName);
            });
        };

        const savedTheme = (() => {
            try {
                return localStorage.getItem('swiftdesk_theme') || 'sunset';
            } catch (_) {
                return 'sunset';
            }
        })();
        const savedHex = (() => {
            try {
                return localStorage.getItem('swiftdesk_custom_color');
            } catch (_) {
                return null;
            }
        })();
        if (savedHex && colorInput) {
            colorInput.value = savedHex;
        }
        applyTheme(savedTheme, savedHex);

        const closePopover = () => {
            if (!popover || !trigger) return;
            popover.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        };

        trigger?.addEventListener('click', (e) => {
            e.stopPropagation();
            const opening = popover.hidden;
            popover.hidden = !opening;
            trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });

        popover?.addEventListener('click', (e) => e.stopPropagation());

        optionBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                const chosen = btn.getAttribute('data-set-theme') || 'sunset';
                applyTheme(chosen);
                closePopover();
            });
        });

        colorInput?.addEventListener('input', (e) => {
            applyTheme('custom', e.target.value);
        });

        document.addEventListener('click', closePopover);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closePopover();
        });
    }
})();
