<?php
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

namespace local_coursegen\hook;

use core\hook\output\before_footer_html_generation;

/**
 * Hook to add the "Create with AI" button to the My courses page.
 *
 * The button is placed client side by the local_coursegen/mycourses_ai_button
 * AMD module, because the container holding core's course action buttons
 * differs between Moodle versions (page header on 4.5/5.0, Course overview
 * block on 5.2, empty-state action bar when the user has no courses).
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mycourses_header_hook {
    /**
     * Hook entrypoint: request the AMD module that injects the button on My courses.
     *
     * The footer hook is only dispatched while a page footer is rendered, so
     * CLI scripts never reach it; AJAX and web service requests are skipped
     * explicitly.
     *
     * @param before_footer_html_generation $hook Hook object.
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        if (AJAX_SCRIPT || WS_SERVER) {
            return;
        }

        $page = $hook->renderer->get_page();

        if (!self::is_my_courses_page($page)) {
            return;
        }

        if (!self::user_can_see_button()) {
            return;
        }

        $page->requires->js_call_amd('local_coursegen/mycourses_ai_button', 'init', [[
            'url' => (new \moodle_url('/local/coursegen/aicoursecreation.php'))->out(false),
        ]]);
    }

    /**
     * Determine if the given page is the My courses page.
     *
     * @param \moodle_page $page The page being rendered.
     * @return bool
     */
    private static function is_my_courses_page(\moodle_page $page): bool {
        if (!$page->has_set_url()) {
            return false;
        }

        return str_ends_with($page->url->get_path(), '/my/courses.php');
    }

    /**
     * Check whether the current user has the capabilities required to see the
     * AI course button.
     *
     * @return bool
     */
    private static function user_can_see_button(): bool {
        $systemcontext = \context_system::instance();

        return has_all_capabilities([
            'moodle/course:create',
            'local/coursegen:createcoursewithai',
        ], $systemcontext);
    }
}
