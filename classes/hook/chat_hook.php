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
 * Hook to load the floating chat
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_hook {
    /**
     * Hook to add AI buttons and check AI course creation
     *
     * @param before_footer_html_generation $hook The hook event.
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        self::add_activity_ai_button();
        self::add_course_ai_button();
    }

    /**
     * Check if we are in a course context
     */
    private static function is_course_context(): bool {
        global $PAGE, $COURSE;

        // Check if we are in a course page.
        if (
            $PAGE->pagelayout === 'course' ||
            $PAGE->pagelayout === 'incourse' ||
            strpos($PAGE->pagetype, 'course-') === 0 ||
            strpos($PAGE->pagetype, 'mod-') === 0
        ) {
            return true;
        }

        // Check if we have a valid course.
        if (isset($COURSE) && $COURSE->id > 1) {
            return true;
        }

        // Check context.
        $context = $PAGE->context;
        if (!$context) {
            return false;
        }
        if (
            $context->contextlevel == CONTEXT_COURSE ||
            $context->contextlevel == CONTEXT_MODULE
        ) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the site Moodle version is exactly 4.5
     */
    private static function is_moodle_45(): bool {
        global $CFG;
        if (!empty($CFG->branch)) {
            return (int)$CFG->branch === 405;
        }
        return !empty($CFG->release) && strpos((string)$CFG->release, '4.5') === 0;
    }

    /**
     * Add activity AI button
     */
    private static function add_activity_ai_button(): void {
        global $PAGE, $COURSE;
        if (self::can_create_activity()) {
            $PAGE->requires->js_call_amd('local_coursegen/activityai', 'init', [
                $COURSE->id,
                self::is_moodle_45(),
            ]);
        }
    }

    /**
     * Add course AI button
     */
    private static function add_course_ai_button(): void {
        global $PAGE;

        if (self::can_create_course()) {
            $PAGE->requires->js_call_amd('local_coursegen/add_course_ai_button', 'init', []);
        }
    }

    /**
     * Check if user can create an activity
     */
    private static function can_create_activity(): bool {
        global $COURSE;

        if (!self::is_course_context()) {
            return false;
        }

        $context = \context_course::instance($COURSE->id);

        return has_all_capabilities([
            'moodle/course:update',
            'moodle/course:manageactivities',
            'local/coursegen:createactivitywithai',
        ], $context);
    }

    /**
     * Check if user can create a course
     */
    private static function can_create_course(): bool {
        global $PAGE, $DB;

        $path = $PAGE->url->get_path();
        $iseditpage = strpos($path, '/course/edit.php') !== false;

        if (!$iseditpage) {
            return false;
        }

        // Not allowed when editing a course.
        $courseid = $PAGE->url->get_param('id');
        if ($courseid) {
            return false;
        }

        $categoryid = $PAGE->url->get_param('category');
        $categorycontext = null;
        if ($categoryid) {
            $category = $DB->get_record('course_categories', ['id' => $categoryid], '*', MUST_EXIST);
            $categorycontext = \context_coursecat::instance($category->id);
        } else {
            $category = \core_course_category::get_default();
            $categorycontext = \context_coursecat::instance($category->id);
        }

        return has_all_capabilities([
            'moodle/course:create',
            'local/coursegen:createcoursewithai',
        ], $categorycontext);
    }
}
