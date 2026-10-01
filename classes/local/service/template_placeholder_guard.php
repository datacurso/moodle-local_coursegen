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

namespace local_coursegen\local\service;

use cm_info;
use local_coursegen\local\models\template_activity;

/**
 * Refuses a template activity that has no placeholder.
 *
 * A template is the mold other activities are generated from, so one with no
 * placeholder says nothing the AI service could write in its place, and the AI
 * service rejects it. This is where the plugin refuses it first, with a
 * message that tells the user what to add, both when a template is saved and
 * when a course is generated from one that was saved before this rule.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_placeholder_guard {
    /**
     * Refuse every activity row of a save that is a template with no placeholder.
     *
     * @param array $sections The sections of a save, each with its activities array.
     * @throws \moodle_exception When a template row has an activity with no placeholder.
     */
    public static function assert_sections(array $sections): void {
        foreach ($sections as $section) {
            self::assert_rows($section['activities']);
        }
    }

    /**
     * Refuse an activity by its course module id.
     *
     * @param int $cmid
     * @throws \moodle_exception When the activity does not exist or has no placeholder.
     */
    public static function assert_cmid(int $cmid): void {
        $cm = self::find_cm($cmid);
        if ($cm === null) {
            throw new \moodle_exception('invalidcoursemodule', 'error');
        }
        self::assert_activity($cm);
    }

    /**
     * Refuse an activity that has no placeholder.
     *
     * @param cm_info $cm
     * @throws \moodle_exception When the activity has no placeholder.
     */
    public static function assert_activity(cm_info $cm): void {
        $found = template_placeholder_detector::has_placeholder($cm);
        if ($found) {
            return;
        }
        throw self::refusal($cm);
    }

    /**
     * Refuse an activity that has no placeholder in parameters already built for it.
     *
     * @param cm_info $cm
     * @param array $parameters As template_activity_export::parameters_for() gives them.
     * @throws \moodle_exception When the parameters hold no placeholder.
     */
    public static function assert_parameters(cm_info $cm, array $parameters): void {
        $modname = (string) $cm->modname;
        $found = template_placeholder_detector::parameters_have_placeholder($modname, $parameters);
        if ($found) {
            return;
        }
        throw self::refusal($cm);
    }

    /**
     * Refuse every row of one section that is a template with no placeholder.
     *
     * @param array $rows The activity rows of a section.
     */
    private static function assert_rows(array $rows): void {
        foreach ($rows as $row) {
            self::assert_row($row);
        }
    }

    /**
     * Refuse one activity row when it is a template with no placeholder.
     *
     * A row whose activity is gone is not an activity of the course any more
     * and the export leaves it out, so there is no mold to refuse.
     *
     * @param array $row One activity row of a save.
     */
    private static function assert_row(array $row): void {
        if ($row['action'] !== template_activity::ACTION_TEMPLATE) {
            return;
        }
        $cmid = (int) $row['cmid'];
        $cm = self::find_cm($cmid);
        if ($cm === null) {
            return;
        }
        self::assert_activity($cm);
    }

    /**
     * The activity of a course module id, or null when there is none.
     *
     * @param int $cmid
     * @return cm_info|null
     */
    private static function find_cm(int $cmid): ?cm_info {
        global $DB;
        $record = $DB->get_record('course_modules', ['id' => $cmid], 'id, course');
        if (!$record) {
            return null;
        }
        $modinfo = get_fast_modinfo($record->course);
        $cms = $modinfo->get_cms();
        $cm = $cms[$cmid] ?? null;
        return $cm;
    }

    /**
     * The exception that tells the user to add a placeholder to the activity.
     *
     * @param cm_info $cm
     * @return \moodle_exception
     */
    private static function refusal(cm_info $cm): \moodle_exception {
        $name = (string) $cm->name;
        return new \moodle_exception('template_activity_placeholder_required', 'local_coursegen', '', $name);
    }
}
