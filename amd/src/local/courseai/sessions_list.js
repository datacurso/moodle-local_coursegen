// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The sidebar's sessions list: search, status filter, and pagination (10
 * per page, over the filtered set only). The sessions view
 * (aicoursecreation.php?view=sessions) and the idle form are two
 * server-rendered states now, not a client-side toggle - whichever one
 * isn't hidden inline is the one showing; this module just paginates it.
 *
 * @module     local_coursegen/local/courseai/sessions_list
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const PER_PAGE = 10;

/**
 * Wire the sessions list's search, filter and pagination controls.
 */
export const wireSessionsList = () => {
    const sessionsView = document.getElementById('courseaiSessionsView');
    const searchInput = document.getElementById('courseaiSessionsSearch');
    const statusFilter = document.getElementById('courseaiSessionsStatusFilter');
    const noResultsEl = document.getElementById('courseaiSessionsNoResults');
    const paginationEl = document.getElementById('courseaiSessionsPagination');
    const paginationPrev = document.getElementById('courseaiPaginationPrev');
    const paginationNext = document.getElementById('courseaiPaginationNext');
    const paginationInfo = document.getElementById('courseaiPaginationInfo');

    let currentPage = 1;
    let totalPages = 1;

    const matchesFilters = (card) => {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const status = statusFilter?.value || '';
        if (status && card.dataset.status !== status) {
            return false;
        }
        if (query && !(card.dataset.title || '').toLowerCase().includes(query)) {
            return false;
        }
        return true;
    };

    /**
     * Hide every card; the current page's matches are shown afterwards.
     *
     * @param {Array} cards
     */
    const hideAllCards = (cards) => {
        cards.forEach((card) => {
            card.style.display = 'none';
        });
    };

    /**
     * Show only the matching cards that fall on the current page.
     *
     * @param {Array} matching
     */
    const showCurrentPageCards = (matching) => {
        matching.forEach((card, i) => {
            let display = 'none';
            if (Math.floor(i / PER_PAGE) + 1 === currentPage) {
                display = '';
            }
            card.style.display = display;
        });
    };

    const renderPage = (page) => {
        const cards = Array.from(document.querySelectorAll('#courseaiSessionsGrid .courseai-session-row'));
        if (!cards.length) {
            return;
        }

        const matching = cards.filter(matchesFilters);
        totalPages = Math.max(1, Math.ceil(matching.length / PER_PAGE));
        currentPage = Math.max(1, Math.min(page, totalPages));

        hideAllCards(cards);
        showCurrentPageCards(matching);

        if (noResultsEl) {
            let noResultsDisplay = '';
            if (matching.length) {
                noResultsDisplay = 'none';
            }
            noResultsEl.style.display = noResultsDisplay;
        }
        if (paginationEl) {
            let paginationDisplay = 'none';
            if (totalPages > 1) {
                paginationDisplay = 'flex';
            }
            paginationEl.style.display = paginationDisplay;
        }
        if (paginationInfo) {
            paginationInfo.textContent = `${currentPage} / ${totalPages}`;
        }
        if (paginationPrev) {
            paginationPrev.disabled = currentPage <= 1;
        }
        if (paginationNext) {
            paginationNext.disabled = currentPage >= totalPages;
        }
    };

    if (paginationPrev) {
        paginationPrev.addEventListener('click', () => renderPage(currentPage - 1));
    }
    if (paginationNext) {
        paginationNext.addEventListener('click', () => renderPage(currentPage + 1));
    }
    if (searchInput) {
        searchInput.addEventListener('input', () => renderPage(1));
    }
    if (statusFilter) {
        statusFilter.addEventListener('change', () => renderPage(1));
    }

    if (sessionsView && sessionsView.style.display !== 'none') {
        renderPage(1);
    }
};
