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
 * Whether the sidebar is pinned open or closed is a per-user preference
 * (local_coursegen_user_preferences() in lib.php), not browser storage: the
 * page reads it server-side before the first render (aicoursecreation.php
 * sets the "sidebar-closed" class from it directly), so there is nothing to
 * restore here and no flash of the wrong state. This module only writes the
 * preference back after the user acts.
 *
 * @module     local_coursegen/local/courseai/sidebar
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setUserPreference} from 'core_user/repository';

const SIDEBAR_PINNED_PREFERENCE = 'local_coursegen_sidebar_pinned';

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

    // ─── Pin / float / close ─────────────────────────────────────────
    // The toggle in the top bar owns the sidebar's state:
    //  - pinned:   a column of the layout; clicking the toggle closes it.
    //  - closed:   slid out; the toggle shows the menu glyph. Hovering it
    //              floats the sidebar over the content without moving
    //              anything; leaving hides it again. Clicking pins it.
    // The pinned state is remembered per user (see the module docblock);
    // the class on this element already reflects it on arrival.
    const layout = document.getElementById('courseaiAppLayout');
    const toggleWrap = document.getElementById('courseaiSidebarToggleWrap');
    const HOVER_OPEN_MS = 120;
    const HOVER_CLOSE_MS = 220;
    const FLOAT_OUT_MS = 240;
    let hoverTimer = null;
    let floatOutTimer = null;

    const isClosed = () => layout?.classList.contains('sidebar-closed') ?? false;
    const isFloating = () => layout?.classList.contains('sidebar-floating') ?? false;

    // Hold the "no transitions" class for one frame so a state that leaves
    // the normal flow (floating) can land in its rest state without the
    // rest state's own slide replaying.
    const snap = () => {
        if (!layout) {
            return;
        }
        layout.classList.add('sidebar-snap');
        requestAnimationFrame(() => requestAnimationFrame(() => layout.classList.remove('sidebar-snap')));
    };

    const syncToggle = () => {
        if (!toggleBtn) {
            return;
        }
        const closed = isClosed();
        const label = closed ? toggleBtn.dataset.labelOpen : toggleBtn.dataset.labelClose;
        toggleBtn.setAttribute('aria-expanded', String(!closed || isFloating()));
        if (label) {
            toggleBtn.setAttribute('aria-label', label);
            // The native tooltip would sit on top of the floating panel, so
            // it is only offered while there is nothing under it.
            toggleBtn.title = isFloating() ? '' : `${label} [`;
        }
    };

    const setFloating = (on) => {
        if (!layout || !isClosed()) {
            return;
        }
        clearTimeout(floatOutTimer);
        if (on) {
            const resuming = layout.classList.contains('sidebar-floating-out');
            layout.classList.remove('sidebar-floating-out');
            layout.classList.add('sidebar-floating');
            if (resuming) {
                snap();
            }
        } else if (isFloating()) {
            layout.classList.remove('sidebar-floating');
            layout.classList.add('sidebar-floating-out');
            floatOutTimer = setTimeout(() => {
                snap();
                layout.classList.remove('sidebar-floating-out');
            }, FLOAT_OUT_MS);
        }
        syncToggle();
    };

    const setPinned = (pinned) => {
        if (!layout) {
            return;
        }
        clearTimeout(floatOutTimer);
        clearTimeout(hoverTimer);
        layout.classList.remove('sidebar-floating', 'sidebar-floating-out');
        layout.classList.toggle('sidebar-closed', !pinned);
        if (backdrop) {
            backdrop.classList.toggle('open', pinned);
        }
        if (pinned) {
            syncCoursesListHeight();
        }
        syncToggle();
        setUserPreference(SIDEBAR_PINNED_PREFERENCE, pinned ? 1 : 0).catch(() => {
            // The preference failed to save; the sidebar still behaves
            // correctly for the rest of this visit, it just will not be
            // remembered on the next one.
        });
    };

    const closeSidebar = () => setPinned(false);
    const toggleSidebar = () => setPinned(isClosed());

    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }

    const scheduleFloatClose = () => {
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(() => setFloating(false), HOVER_CLOSE_MS);
    };
    if (toggleWrap) {
        toggleWrap.addEventListener('mouseenter', () => {
            clearTimeout(hoverTimer);
            if (isClosed()) {
                hoverTimer = setTimeout(() => setFloating(true), HOVER_OPEN_MS);
            }
        });
        toggleWrap.addEventListener('mouseleave', scheduleFloatClose);
    }
    sidebar.addEventListener('mouseenter', () => clearTimeout(hoverTimer));
    sidebar.addEventListener('mouseleave', scheduleFloatClose);

    document.addEventListener('keydown', (e) => {
        const target = document.activeElement;
        const typing = target && (/^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName) || target.isContentEditable);
        if (e.key === '[' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
            e.preventDefault();
            toggleSidebar();
        }
        if (e.key === 'Escape' && isFloating()) {
            setFloating(false);
        }
    });

    syncToggle();

    // ─── Backdrop click closes sidebar ───────────────────────────────
    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    // ─── New course button ───────────────────────────────────────────
    // A clean page, no query string: the creation mode is chosen in the
    // composer (mode_switch partial), so nothing from the current page
    // carries over. No closeSidebar() here: the page navigates away at
    // once, so collapsing first only flashes the close animation.
    if (btnNew) {
        btnNew.addEventListener('click', () => {
            window.location.href = new URL('aicoursecreation.php', window.location.href).toString();
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
