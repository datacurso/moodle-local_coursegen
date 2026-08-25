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
    const btnViewAll = document.getElementById('courseaiViewAll');
    const sessionsView = document.getElementById('courseaiSessionsView');
    const sessionsBackBtn = document.getElementById('courseaiSessionsBackBtn');
    const backdrop = document.getElementById('courseaiSidebarBackdrop');
    const mainContainer = document.getElementById('courseaiWorkspace');

    if (!sidebar) {
        return;
    }

    // Sidebar starts collapsed (class already in HTML template).

    // ─── Pagination (10 per page) ────────────────────────────────────
    const PER_PAGE = 10;
    let currentPage = 1;
    let totalPages = 1;
    const paginationEl = document.getElementById('courseaiSessionsPagination');
    const paginationPrev = document.getElementById('courseaiPaginationPrev');
    const paginationNext = document.getElementById('courseaiPaginationNext');
    const paginationInfo = document.getElementById('courseaiPaginationInfo');

    const renderPage = (page) => {
        const cards = document.querySelectorAll('#courseaiSessionsGrid .courseai-session-card');
        if (!cards.length) {
            return;
        }
        totalPages = Math.ceil(cards.length / PER_PAGE);
        currentPage = Math.max(1, Math.min(page, totalPages));

        cards.forEach((card, i) => {
            card.style.display = Math.floor(i / PER_PAGE) + 1 === currentPage ? '' : 'none';
        });

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

    // ─── View switching ──────────────────────────────────────────────
    const showSessionsView = () => {
        closeSidebar();   // close sidebar when switching to sessions view
        if (mainContainer) {
            mainContainer.style.display = 'none';
        }
        if (sessionsView) {
            sessionsView.style.display = '';
        }
        currentPage = 1;
        renderPage(1);
    };

    const showMainView = () => {
        if (sessionsView) {
            sessionsView.style.display = 'none';
        }
        if (mainContainer) {
            mainContainer.style.display = '';
        }
    };

    if (btnViewAll) {
        btnViewAll.addEventListener('click', showSessionsView);
    }

    if (sessionsBackBtn) {
        sessionsBackBtn.addEventListener('click', showMainView);
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
    if (btnNew) {
        btnNew.addEventListener('click', () => {
            closeSidebar();
            window.location.href = 'aicoursecreation.php';
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
                closeSidebar();
                window.location.href = `aicoursecreation.php?sessionid=${sessionid}`;
            }
        });
    });
};
