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
 * Render the "Course sections" review: per-section collapsible cards with a
 * report-style activity table and per-row action selects.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\output;

use local_coursegen\local\template\template_actions;

/**
 * Build the sections review from the structure of a course.
 *
 * The review is a clean structured render of its own mustache template
 * (templates/template_course_sections.mustache) — one collapsible card per
 * section, each holding a table of its activities where every row carries a
 * selection checkbox, an action select (keep intact or modify with AI) and,
 * for a row the AI modifies, an optional instruction. local/template/
 * sections_events.js binds them all after every render.
 */
class sections_config {
    /**
     * Render the course sections review for a course.
     *
     * @param array $sections Sections as returned by template_service::load_for_edit(), each activity
     *     already carrying its saved action and instruction.
     * @param int $courseid The base course id.
     * @return string Rendered HTML.
     */
    public static function render(array $sections, int $courseid): string {
        global $OUTPUT;

        $context = self::export_for_template($sections, $courseid);
        return $OUTPUT->render_from_template('local_coursegen/template_course_sections', $context);
    }

    /**
     * Build the template context from the structure of a course.
     *
     * The server render stays the single source of truth for row defaults:
     * sections_events.js seeds its state FROM the rendered selects and
     * textareas.
     *
     * @param array $sections Sections as returned by template_service::load_for_edit().
     * @param int $courseid The base course id.
     * @return array Template context.
     */
    public static function export_for_template(array $sections, int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);

        $contexts = [];
        foreach ($sections as $section) {
            $contexts[] = self::section_context($modinfo, $section);
        }

        return [
            // The collapse ids are built from course id + section number: they
            // must be deterministic AND valid CSS identifiers ({{uniqid}}
            // output can start with a digit, which silently breaks the
            // Bootstrap 4 data-target="#..." selector).
            'courseid' => $courseid,
            'sections' => $contexts,
            'hassections' => !empty($contexts),
        ];
    }

    /**
     * Build one section's card context.
     *
     * @param \course_modinfo $modinfo The base course modinfo.
     * @param array $section One section of the structure.
     * @return array
     */
    private static function section_context(\course_modinfo $modinfo, array $section): array {
        $rows = [];
        foreach ($section['activities'] as $activity) {
            $rows[] = self::row_context($modinfo, $activity);
        }
        $activitycount = count($rows);

        return [
            'id' => (int) $section['number'],
            'num' => (int) $section['number'],
            'name' => $section['name'],
            'activitycount' => $activitycount,
            'hasactivities' => $activitycount > 0,
            'rows' => $rows,
        ];
    }

    /**
     * Build one activity's row context.
     *
     * @param \course_modinfo $modinfo The base course modinfo.
     * @param array $activity One activity of the structure, with its saved action and instruction.
     * @return array
     */
    private static function row_context(\course_modinfo $modinfo, array $activity): array {
        $cmid = (int) $activity['cmid'];
        $cm = $modinfo->get_cm($cmid);
        $actions = template_row_options::activity_actions($cmid, $activity['action']);
        $activeaction = template_row_options::active_action($actions);

        // Link to the real activity in the base course; null for modules
        // with no view page of their own (label), whose names stay plain text.
        $viewurl = null;
        if ($cm->url) {
            $viewurl = $cm->url->out(false);
        }
        $name = $cm->get_formatted_name();

        return [
            'cmid' => $cmid,
            'name' => $name,
            'viewurl' => $viewurl,
            'modname' => $activity['modname'],
            'typelabel' => $activity['typename'],
            'iconurl' => $activity['iconurl'],
            'actions' => $actions,
            'isai' => $activeaction === template_actions::AI,
            'instruction' => $activity['instruction'],
        ];
    }
}
