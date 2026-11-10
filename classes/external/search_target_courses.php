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

/**
 * External API for searching the courses an AI-generated course may be imported into.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use aiprovider_datacurso\local\plugin_settings;
use context_coursecat;
use context_helper;
use context_system;
use core_course_category;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursegen\local\service\import_permissions;
use moodle_exception;

/**
 * Lists the courses inside a category tree that the current user may import into.
 *
 * Importing replaces the target through a copy, so a course is offered only
 * when the user holds the full import capability set on it (import_permissions),
 * the same set the import itself requires (design #9539 §7-§8).
 * The feature is gated by the enableimportexisting setting as a second line
 * of defence behind the UI, which hides the option when the setting is off.
 *
 * No audit event is emitted: the call reads course names only and modifies
 * no state.
 */
class search_target_courses extends external_api {
    /** @var int Smallest accepted result size. */
    const LIMIT_MIN = 1;

    /** @var int Largest accepted result size. */
    const LIMIT_MAX = 50;

    /** @var int Result size when the caller gives none. */
    const LIMIT_DEFAULT = 20;

    /**
     * @var int Largest number of courses whose permissions one search checks.
     *
     * The text filter runs in the database; the permission checks, one per course, are what grows
     * with a large category, so they stop here even when fewer courses than the limit matched.
     */
    const SCAN_LIMIT = 500;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category whose tree is searched'),
            'query' => new external_value(PARAM_TEXT, 'Text matched against fullname and shortname', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Maximum number of courses returned', VALUE_DEFAULT, self::LIMIT_DEFAULT),
        ]);
    }

    /**
     * Search the courses the user may import into within a category tree.
     *
     * @param int $categoryid Category id.
     * @param string $query Search text, empty for all.
     * @param int $limit Maximum number of courses returned, clamped to 1..50.
     * @return array List of courses (id, fullname, shortname, categoryid, displayname).
     * @throws \moodle_exception plugindisabled or plugindisabled_admin when the plugin is disabled.
     */
    public static function execute(int $categoryid, string $query = '', int $limit = self::LIMIT_DEFAULT): array {
        plugin_settings::require_enabled('local_coursegen');

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'query' => $query,
            'limit' => $limit,
        ]);

        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('local/coursegen:createcoursewithai', $systemcontext);

        if (empty(get_config('local_coursegen', 'enableimportexisting'))) {
            throw new moodle_exception('error_import_existing_disabled', 'local_coursegen');
        }

        $category = core_course_category::get($params['categoryid'], MUST_EXIST, true);
        require_capability('moodle/course:create', context_coursecat::instance($category->id));

        $needle = \core_text::strtolower(trim($params['query']));
        $limit = max(self::LIMIT_MIN, min(self::LIMIT_MAX, (int) $params['limit']));

        $courses = [];
        $scanned = 0;
        $candidates = self::candidates($category, $needle);
        foreach ($candidates as $course) {
            if (++$scanned > self::SCAN_LIMIT) {
                break;
            }
            context_helper::preload_from_record($course);
            if (!self::is_visible_to_user($course) || !import_permissions::can_import_into($course)) {
                continue;
            }
            $coursecontext = \context_course::instance($course->id);
            $fullname = format_string($course->fullname, true, ['context' => $coursecontext]);
            $courses[] = [
                'id' => (int) $course->id,
                'fullname' => $fullname,
                'shortname' => $course->shortname,
                'categoryid' => (int) $course->category,
                'displayname' => $fullname . ' (' . $course->shortname . ')',
            ];
            if (count($courses) >= $limit) {
                break;
            }
        }
        $candidates->close();

        return $courses;
    }

    /**
     * The courses of a category tree whose fullname or shortname contains the text, in course order.
     *
     * The filter runs in the database, so a large category is never loaded whole.
     *
     * @param core_course_category $category Root of the tree.
     * @param string $needle Lower-cased, trimmed search text, empty for all.
     * @return \moodle_recordset Course records (id, fullname, shortname, category, visible) with their context.
     */
    private static function candidates(core_course_category $category, string $needle): \moodle_recordset {
        global $DB;

        $ctxfields = context_helper::get_preload_record_columns_sql('ctx');
        $where = '(cc.id = :categoryid OR ' . $DB->sql_like('cc.path', ':path') . ') AND c.id <> :siteid';
        $params = [
            'categoryid' => $category->id,
            'path' => $DB->sql_like_escape($category->path) . '/%',
            'siteid' => SITEID,
            'contextlevel' => CONTEXT_COURSE,
        ];
        if ($needle !== '') {
            $where .= ' AND (' . $DB->sql_like('c.fullname', ':fullname', false) . ' OR '
                . $DB->sql_like('c.shortname', ':shortname', false) . ')';
            $params['fullname'] = '%' . $DB->sql_like_escape($needle) . '%';
            $params['shortname'] = '%' . $DB->sql_like_escape($needle) . '%';
        }

        return $DB->get_recordset_sql(
            "SELECT c.id, c.fullname, c.shortname, c.category, c.visible, {$ctxfields}
               FROM {course} c
               JOIN {course_categories} cc ON cc.id = c.category
               JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel
              WHERE {$where}
           ORDER BY c.sortorder",
            $params
        );
    }

    /**
     * Whether the user can see the course: always when visible, with viewhiddencourses when hidden.
     *
     * @param \stdClass $course Course record (id, visible).
     * @return bool
     */
    private static function is_visible_to_user(\stdClass $course): bool {
        return !empty($course->visible)
            || has_capability('moodle/course:viewhiddencourses', \context_course::instance($course->id));
    }

    /**
     * Returns description of method return value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course id'),
                'fullname' => new external_value(PARAM_TEXT, 'Course fullname, formatted'),
                'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
                'categoryid' => new external_value(PARAM_INT, 'Category the course belongs to'),
                'displayname' => new external_value(PARAM_TEXT, 'Fullname followed by the shortname in brackets'),
            ]),
            'Courses the user may import into'
        );
    }
}
