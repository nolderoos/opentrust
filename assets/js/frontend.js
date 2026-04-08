/**
 * OpenTrust frontend interactions.
 *
 * - Smooth scroll for anchor links
 * - Scroll-spy for sticky navigation
 * - Global search across cards and table rows
 * - Table column sorting
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        initSmoothScroll();
        initScrollSpy();
        initSearch();
        initTableSort();
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

    // ── Search ─────────────────────────────────────

    function initSearch() {
        var input = document.getElementById('ot-search');
        if (!input) return;

        input.addEventListener('input', function () {
            var query = this.value.toLowerCase().trim();

            // Filter cards.
            document.querySelectorAll('.ot-card').forEach(function (card) {
                var text = card.textContent.toLowerCase();
                card.classList.toggle('ot-card--hidden', query !== '' && !text.includes(query));
            });

            // Filter table rows.
            document.querySelectorAll('.ot-table tbody tr').forEach(function (row) {
                var text = row.textContent.toLowerCase();
                row.classList.toggle('ot-table-row--hidden', query !== '' && !text.includes(query));
            });

            // Show/hide empty sections.
            document.querySelectorAll('.ot-section').forEach(function (section) {
                var visibleCards = section.querySelectorAll('.ot-card:not(.ot-card--hidden)').length;
                var visibleRows = section.querySelectorAll('.ot-table tbody tr:not(.ot-table-row--hidden)').length;
                var hasContent = visibleCards > 0 || visibleRows > 0;

                // Only hide if searching.
                if (query) {
                    section.style.display = hasContent ? '' : 'none';
                } else {
                    section.style.display = '';
                }
            });
        });
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
