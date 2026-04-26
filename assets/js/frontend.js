/**
 * OpenTrust frontend interactions.
 *
 * - Smooth scroll for anchor links
 * - Scroll-spy for sticky navigation
 * - Table column sorting
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        initSmoothScroll();
        initScrollSpy();
        initTableSort();
        initDpCards();
        initVersionHistory();
        initClampToggles();
    }

    // ── Smooth Scroll ──────────────────────────────

    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                var id = this.getAttribute('href');
                if (!id || id === '#') return;

                var target = document.querySelector(id);
                if (!target) return;

                e.preventDefault();

                var nav = document.querySelector('.ot-nav');
                var offset = nav ? nav.offsetHeight + 16 : 16;

                window.scrollTo({
                    top: target.getBoundingClientRect().top + window.pageYOffset - offset,
                    behavior: 'smooth'
                });

                // Update URL without scrolling.
                history.pushState(null, '', id);
            });
        });
    }

    // ── Scroll Spy ─────────────────────────────────

    function initScrollSpy() {
        var navLinks = document.querySelectorAll('[data-ot-nav]');
        if (!navLinks.length) return;

        var sections = [];
        navLinks.forEach(function (link) {
            var id = link.getAttribute('href');
            if (id) {
                var section = document.querySelector(id);
                if (section) sections.push({ el: section, link: link });
            }
        });

        if (!sections.length) return;

        var nav = document.querySelector('.ot-nav');
        var offset = nav ? nav.offsetHeight + 32 : 32;

        function update() {
            var scrollY = window.pageYOffset + offset;
            var active = sections[0];

            for (var i = 0; i < sections.length; i++) {
                if (sections[i].el.offsetTop <= scrollY) {
                    active = sections[i];
                }
            }

            navLinks.forEach(function (l) { l.classList.remove('ot-nav__link--active'); });
            if (active) active.link.classList.add('ot-nav__link--active');
        }

        window.addEventListener('scroll', throttle(update, 100), { passive: true });
        update();
    }

    // ── Table Sort ─────────────────────────────────

    function initTableSort() {
        document.querySelectorAll('.ot-table th[data-ot-sort]').forEach(function (th) {
            th.addEventListener('click', function () {
                sortTable(this);
            });
        });
    }

    function sortTable(th) {
        var table = th.closest('table');
        var tbody = table.querySelector('tbody');
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var colIndex = Array.from(th.parentNode.children).indexOf(th);
        var ascending = th.getAttribute('data-ot-sort-dir') !== 'asc';

        // Clear sort direction on siblings.
        th.parentNode.querySelectorAll('[data-ot-sort]').forEach(function (sibling) {
            if (sibling !== th) sibling.removeAttribute('data-ot-sort-dir');
        });

        rows.sort(function (a, b) {
            var aText = (a.children[colIndex] || {}).textContent || '';
            var bText = (b.children[colIndex] || {}).textContent || '';
            aText = aText.trim().toLowerCase();
            bText = bText.trim().toLowerCase();
            return ascending ? aText.localeCompare(bText) : bText.localeCompare(aText);
        });

        th.setAttribute('data-ot-sort-dir', ascending ? 'asc' : 'desc');

        var fragment = document.createDocumentFragment();
        rows.forEach(function (row) { fragment.appendChild(row); });
        tbody.appendChild(fragment);
    }

    // ── Data Practice Cards ─────────────────────────

    function initDpCards() {
        // Card header toggle — expand/collapse details.
        document.querySelectorAll('[data-ot-dp-toggle]').forEach(function (head) {
            function toggle() {
                var detailId = head.getAttribute('data-ot-dp-toggle');
                var detail = document.getElementById(detailId);
                if (!detail) return;

                var expanded = head.getAttribute('aria-expanded') === 'true';
                head.setAttribute('aria-expanded', String(!expanded));
                detail.hidden = expanded;
            }

            head.addEventListener('click', toggle);
            head.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggle();
                }
            });
        });

        // "View N more" buttons — show overflow items.
        document.querySelectorAll('[data-ot-dp-more]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var card = this.closest('.ot-dp-card');
                var overflow = card.querySelector('.ot-dp-card__list--overflow');
                if (overflow) {
                    overflow.hidden = false;
                }
                this.classList.add('ot-dp-card__more--hidden');
            });
        });
    }

    // ── Version History Toggle ─────────────────────

    function initVersionHistory() {
        var toggle = document.querySelector('[data-ot-version-toggle]');
        if (!toggle) return;

        var list = toggle.nextElementSibling;
        if (!list) return;

        toggle.addEventListener('click', function () {
            var expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', String(!expanded));
            list.hidden = expanded;
        });
    }

    // ── Clamp Toggles (subprocessor table) ─────────

    function initClampToggles() {
        document.querySelectorAll('.ot-table__clamp-text').forEach(function (el) {
            // Show the "more" button only if text is actually truncated.
            if (el.scrollHeight > el.clientHeight + 1) {
                var btn = el.nextElementSibling;
                if (btn && btn.hasAttribute('data-ot-clamp-toggle')) {
                    btn.style.display = 'inline';
                }
            }
        });

        document.querySelectorAll('[data-ot-clamp-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = this.previousElementSibling;
                var expanded = text.classList.toggle('ot-table__clamp-text--expanded');
                this.textContent = expanded ? 'less' : 'more';
            });
        });
    }

    // ── Utilities ──────────────────────────────────

    function throttle(fn, delay) {
        var last = 0;
        return function () {
            var now = Date.now();
            if (now - last >= delay) {
                last = now;
                fn.apply(this, arguments);
            }
        };
    }

})();
