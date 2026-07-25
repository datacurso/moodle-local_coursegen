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

use aiprovider_datacurso\httpclient\ai_course_api;
use core\hook\output\before_footer_html_generation;
use local_coursegen\ai_course;

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
        self::check_ai_course_creation();
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
     * Check if the current user can generate images with AI in the given context.
     *
     * Requires both the admin master switch and the per-user capability.
     *
     * @param \context $context The context to check the capability in.
     * @return bool
     */
    /**
     * Check if the current user can generate images with AI for activities.
     *
     * Requires both the admin master switch for activity images and the per-user capability.
     *
     * @param \context $context The context to check the capability in.
     * @return bool
     */
    private static function can_generate_activity_images(\context $context): bool {
        return get_config('local_coursegen', 'enable_activity_image_generation')
            && has_capability('local/coursegen:generateactivityimages', $context);
    }

    /**
     * Add activity AI button
     */
    private static function add_activity_ai_button(): void {
        global $PAGE, $COURSE;
        if (self::can_create_activity()) {
            $coursecontext = \context_course::instance($COURSE->id);
            $PAGE->requires->js_call_amd('local_coursegen/add_activity_ai_button', 'init', [
                $COURSE->id,
                self::is_moodle_45(),
                self::can_generate_activity_images($coursecontext),
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
     * Check if course is being created with AI and open modal if needed
     */
    private static function check_ai_course_creation(): void {
        global $PAGE, $COURSE, $CFG, $SESSION;

        // Check if we are on course/view.php page.
        $path = $PAGE->url->get_path();
        $iscourseviewpage = strpos($path, '/course/view.php') !== false;
        if (!$iscourseviewpage) {
            return;
        }

        // Check if we have a valid course ID.
        if (!isset($COURSE) || $COURSE->id <= 1) {
            return;
        }

        // Get course session from database.
        $session = ai_course::get_course_session($COURSE->id);
        // If no session exists, return.
        if (!$session) {
            return;
        }

        // Check if session is in planning or creating status (1 or 2).
        if ($session->status == 1 || $session->status == 2) {
            if (!isset($SESSION->local_coursegen_modal_shown)) {
                $SESSION->local_coursegen_modal_shown = [];
            }

            $shown = $SESSION->local_coursegen_modal_shown[$COURSE->id] ?? false;

            if ($shown) {
                ai_course::update_session_status($session->id, 4);
                return;
            }

            $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
            $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;

            $client = new ai_course_api(null, $baseurl, $baseurleu);
            $streamingurl = $client->get_streaming_url_for_session($session->session_id);

            $PAGE->requires->js_call_amd('local_coursegen/add_course_ai_modal', 'init', [
                [
                    'streamingurl' => $streamingurl,
                    'courseid' => $COURSE->id,
                ],
            ]);

            $SESSION->local_coursegen_modal_shown[$COURSE->id] = true;
        }
    }

    /**
     * Check if user can create an activity
     */
    private static function can_create_activity(): bool {
        global $COURSE;

        if (!get_config('local_coursegen', 'enable_activity_ai')) {
            return false;
        }

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
     * Check if a course is empty (only has the default Announcements forum or no modules at all).
     *
     * A course is considered empty when it has no user-created content — only the
     * Announcements forum (type "news") that Moodle automatically adds to section 0
     * on course creation, and no content in any numbered section.
     *
     * Uses forum_get_course_forum() to obtain the exact instance ID of the
     * one-per-course "news" forum and compares it against the only module present.
     *
     * @param int $courseid The course ID to check.
     * @return bool True if the course has no real content.
     */
    private static function is_course_empty(int $courseid): bool {
        global $CFG;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $modinfo = get_fast_modinfo($courseid);
        $cms = $modinfo->get_cms();

        if (empty($cms)) {
            return true;
        }

        if (count($cms) > 1) {
            return false;
        }

        // Exactly one module — verify it is the default Announcements news forum.
        $cm = reset($cms);
        if ($cm->modname !== 'forum' || $cm->sectionnum !== 0) {
            return false;
        }

        // forum_get_course_forum returns the one-per-course "news" forum record
        // by querying: course = $courseid AND type = 'news', ordered by id ASC.
        $newsforum = forum_get_course_forum($courseid, 'news');
        if (!$newsforum) {
            return false;
        }

        return (int) $cm->instance === (int) $newsforum->id;
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

        $courseid = $PAGE->url->get_param('id');

        if ($courseid) {
            // Allow on existing courses only if the admin setting and content check pass.
            if (!get_config('local_coursegen', 'enable_empty_course_ai')) {
                return false;
            }
            if (!self::is_course_empty((int) $courseid)) {
                return false;
            }

            $coursecontext = \context_course::instance($courseid);
            return has_all_capabilities([
                'moodle/course:update',
                'local/coursegen:createcoursewithai',
            ], $coursecontext);
        }

        // New course creation.
        if (!get_config('local_coursegen', 'enable_course_ai')) {
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
