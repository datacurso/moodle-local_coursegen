// This file is part of Moodle - http://moodle.org/

/**
 * Sidebar component for the AI course creation page.
 *
 * @module     local_coursegen/local/courseai/sidebar
 */

/**
 * Initialize the sidebar component.
 *
 * @param {Object} state Wizard state object
 * @param {Function} [resetFn] Optional callback to reset wizard to phase 1
 */
export const initSidebar = (state, resetFn) => {
    const sidebar = document.getElementById('courseaiSidebar');
    const toggleBtn = document.getElementById('courseaiSidebarToggle');
    const btnNew = document.getElementById('courseaiBtnNew');
    const coursesHeader = document.getElementById('courseaiCoursesHeader');
    const coursesList = document.getElementById('courseaiCoursesList');
    const coursesChevron = document.getElementById('courseaiCoursesChevron');
    const btnViewAll = document.getElementById('courseaiViewAll');
    const sessionsView = document.getElementById('courseaiSessionsView');
    const sessionsBackBtn = document.getElementById('courseaiSessionsBackBtn');
    const navbarTrigger = document.getElementById('courseaiMenuTrigger');
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

    // ─── Open / close helpers ────────────────────────────────────────
    const openSidebar = () => {
        sidebar.classList.remove('collapsed');
        if (toggleBtn) {
            toggleBtn.classList.remove('collapsed');
        }
        if (backdrop) {
            backdrop.classList.add('open');
        }
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

    // ─── Navbar menu trigger ─────────────────────────────────────────
    if (navbarTrigger) {
        navbarTrigger.addEventListener('click', toggleSidebar);
    }

    // ─── Backdrop click closes sidebar ───────────────────────────────
    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    // ─── New course button ───────────────────────────────────────────
    if (btnNew) {
        btnNew.addEventListener('click', () => {
            closeSidebar();
            if (typeof resetFn === 'function') {
                resetFn();
            } else {
                window.location.href = 'aicoursecreation.php';
            }
        });
    }

    // ─── Courses section expand/collapse ─────────────────────────────
    let coursesOpen = true;

    const toggleCourses = () => {
        coursesOpen = !coursesOpen;
        if (coursesChevron) {
            coursesChevron.classList.toggle('closed', !coursesOpen);
        }
        if (coursesList) {
            coursesList.classList.toggle('closed', !coursesOpen);
            if (coursesOpen) {
                coursesList.style.maxHeight = coursesList.scrollHeight + 'px';
            }
        }
    };

    if (coursesHeader) {
        coursesHeader.addEventListener('click', toggleCourses);
    }

    if (coursesList) {
        coursesList.style.maxHeight = coursesList.scrollHeight + 'px';
        setTimeout(() => {
            coursesList.style.maxHeight = coursesList.scrollHeight + 'px';
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
