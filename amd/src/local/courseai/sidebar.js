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
 * Sidebar component for the AI course creation page.
 *
 * @module     local_coursegen/local/courseai/sidebar
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialize the sidebar component.
 */
export const initSidebar = () => {
    const sidebar = document.getElementById('courseaiSidebar');
    const toggleBtn = document.getElementById('courseaiSidebarToggle');
    const btnNew = document.getElementById('courseaiBtnNew');
    const coursesHeader = document.getElementById('courseaiCoursesHeader');
    const coursesList = document.getElementById('courseaiCoursesList');
    const coursesChevron = document.getElementById('courseaiCoursesChevron');
    const sessionsView = document.getElementById('courseaiSessionsView');
    const backdrop = document.getElementById('courseaiSidebarBackdrop');

    if (!sidebar) {
        return;
    }

    // Sidebar starts collapsed (class already in HTML template).

    // ─── Search + status filter ───────────────────────────────────────
    const searchInput = document.getElementById('courseaiSessionsSearch');
    const statusFilter = document.getElementById('courseaiSessionsStatusFilter');
    const noResultsEl = document.getElementById('courseaiSessionsNoResults');

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

    // ─── Pagination (10 per page, over the filtered set only) ─────────
    const PER_PAGE = 10;
    let currentPage = 1;
    let totalPages = 1;
    const paginationEl = document.getElementById('courseaiSessionsPagination');
    const paginationPrev = document.getElementById('courseaiPaginationPrev');
    const paginationNext = document.getElementById('courseaiPaginationNext');
    const paginationInfo = document.getElementById('courseaiPaginationInfo');

    const renderPage = (page) => {
        const cards = Array.from(document.querySelectorAll('#courseaiSessionsGrid .courseai-session-row'));
        if (!cards.length) {
            return;
        }

        const matching = cards.filter(matchesFilters);
        totalPages = Math.max(1, Math.ceil(matching.length / PER_PAGE));
        currentPage = Math.max(1, Math.min(page, totalPages));

        cards.forEach((card) => { card.style.display = 'none'; });
        matching.forEach((card, i) => {
            card.style.display = Math.floor(i / PER_PAGE) + 1 === currentPage ? '' : 'none';
        });

        if (noResultsEl) {
            noResultsEl.style.display = matching.length ? 'none' : '';
        }
        if (paginationEl) {
            paginationEl.style.display = totalPages > 1 ? 'flex' : 'none';
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

    // The sessions view (aicoursecreation.php?view=sessions) and the idle
    // form are two server-rendered states now, not a client-side toggle —
    // whichever one isn't hidden inline is the one showing. Just paginate it.
    if (sessionsView && sessionsView.style.display !== 'none') {
        renderPage(1);
    }

    let coursesOpen = true;

    const syncCoursesListHeight = () => {
        if (!coursesList || !coursesOpen) {
            return;
        }
        coursesList.style.maxHeight = coursesList.scrollHeight + 'px';
    };

    // ─── Open / close helpers ────────────────────────────────────────
    const openSidebar = () => {
        sidebar.classList.remove('collapsed');
        if (toggleBtn) {
            toggleBtn.classList.remove('collapsed');
        }
        if (backdrop) {
            backdrop.classList.add('open');
        }
        syncCoursesListHeight();
    };

    const closeSidebar = () => {
        sidebar.classList.add('collapsed');
        if (toggleBtn) {
            toggleBtn.classList.add('collapsed');
        }
        if (backdrop) {
            backdrop.classList.remove('open');
        }
    };

    // ─── Toggle ──────────────────────────────────────────────────────
    const toggleSidebar = () => {
        if (sidebar.classList.contains('collapsed')) {
            openSidebar();
        } else {
            closeSidebar();
        }
    };

    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }

    // ─── Backdrop click closes sidebar ───────────────────────────────
    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    // ─── New course button ───────────────────────────────────────────
    // No closeSidebar() here: the page navigates away immediately after, so
    // collapsing it first only shows a jarring flash of the close animation.
    // Keeps the current ?mode= (free/template) — "new course" should reset
    // the session, not the mode you'd chosen to work in.
    if (btnNew) {
        btnNew.addEventListener('click', () => {
            const currentMode = new URLSearchParams(window.location.search).get('mode');
            const url = new URL('aicoursecreation.php', window.location.href);
            if (currentMode) {
                url.searchParams.set('mode', currentMode);
            }
            window.location.href = url.toString();
        });
    }

    // ─── Courses section expand/collapse ─────────────────────────────
    const toggleCourses = () => {
        coursesOpen = !coursesOpen;
        if (coursesChevron) {
            coursesChevron.classList.toggle('closed', !coursesOpen);
        }
        if (coursesList) {
            coursesList.classList.toggle('closed', !coursesOpen);
            if (coursesOpen) {
                syncCoursesListHeight();
            }
        }
    };

    if (coursesHeader) {
        coursesHeader.addEventListener('click', toggleCourses);
    }

    if (coursesList) {
        syncCoursesListHeight();
        setTimeout(() => {
            syncCoursesListHeight();
        }, 50);
    }

    // ─── Course items ────────────────────────────────────────────────
    const courseItems = sidebar.querySelectorAll('.courseai-sidebar-course-item');
    courseItems.forEach((item) => {
        item.addEventListener('click', () => {
            const sessionid = item.dataset.sessionid;
            if (sessionid) {
                window.location.href = `aicoursecreation.php?sessionid=${sessionid}`;
            }
        });
    });
};
