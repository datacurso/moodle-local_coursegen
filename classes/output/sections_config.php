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
 * report-style activity table and per-row action menus.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\output;

use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;
use local_coursegen\local\service\template_instance_layout;

/**
 * Build the sections review straight from modinfo.
 *
 * This used to re-render the base course through its real course-format
 * renderer and then DOM-surgically strip every native editing affordance
 * while injecting custom dropdowns and prompt textareas. That whole approach
 * is gone: the review is now a clean structured render of its own mustache
 * template (templates/template_course_sections.mustache) — one collapsible
 * card per section, each holding a table of its activities where every row
 * carries a selection checkbox plus an action select preselected with its
 * type's default, and every section table a select-all checkbox and a bulk
 * "apply to selected" select. local/template/sections_events.js binds them
 * all after every render.
 */
class sections_config {
    /**
     * Render the course sections review for a base course.
     *
     * @param \course_modinfo $modinfo The base course modinfo.
     * @param int $templateid Existing template whose saved per-section/
     *     per-activity configuration should be preselected (0: type defaults).
     * @return string Rendered HTML.
     */
    public static function render(\course_modinfo $modinfo, int $templateid = 0): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template(
            'local_coursegen/template_course_sections',
            self::export_for_template($modinfo, $templateid)
        );
    }

    /**
     * Build the template context from modinfo.
     *
     * When editing an existing template ($templateid > 0), its saved
     * per-section behaviors and per-activity actions preselect the rendered
     * controls — the server render stays the single source of truth for row
     * defaults (sections_events.js seeds its state FROM the rendered
     * selects). Saved rows whose cmid/sectionid no longer exists in the base
     * course are simply never looked up, and activities added since the
     * template was saved fall back to their type default.
     *
     * @param \course_modinfo $modinfo The base course modinfo.
     * @param int $templateid Existing template id, 0 for a new template.
     * @return array Template context.
     */
    public static function export_for_template(\course_modinfo $modinfo, int $templateid = 0): array {
        $course = $modinfo->get_course();

        $savedbehaviors = [];
        $savedactions = [];
        $savedscopes = [];
        $savedinstances = [];
        if ($templateid > 0) {
            foreach (template_section::get_records(['templateid' => $templateid]) as $record) {
                $savedbehaviors[(int) $record->get('sectionid')] = $record->get('behavior');
            }
            foreach (template_activity::get_records(['templateid' => $templateid]) as $record) {
                $savedactions[(int) $record->get('cmid')] = $record->get('action');
                $savedscopes[(int) $record->get('cmid')] = $record->get('templatescope');
            }
            foreach (template_instance::get_records(['templateid' => $templateid]) as $record) {
                $savedinstances[(int) $record->get('sectionid')][] = $record;
            }
        }

        $sections = [];
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            $sectionid = (int) $sectioninfo->id;
            $activitiesbycmid = [];
            $realcmids = [];
            if (!empty($modinfo->sections[$sectioninfo->section])) {
                foreach ($modinfo->sections[$sectioninfo->section] as $cmid) {
                    $cm = $modinfo->get_cm($cmid);
                    if ($cm->deletioninprogress) {
                        continue;
                    }
                    $cmid = (int) $cm->id;
                    $realcmids[] = $cmid;
                    $activitiesbycmid[$cmid] = self::real_row_context($cm, $cmid, $savedactions, $savedscopes);
                }
            }

            $rows = template_instance_layout::ordered_rows($realcmids, $savedinstances[$sectionid] ?? []);
            $rows = self::render_rows($rows, $activitiesbycmid);

            $sections[] = [
                'id' => $sectionid,
                'num' => (int) $sectioninfo->section,
                'name' => get_section_name($course, $sectioninfo),
                'activitycount' => count($rows),
                'hasactivities' => !empty($rows),
                'rows' => $rows,
                'actions' => template_row_options::section_actions($sectionid, $savedbehaviors[$sectionid] ?? 'custom'),
            ];
        }

        return [
            // The collapse ids are built from course id + section id: they
            // must be deterministic AND valid CSS identifiers ({{uniqid}}
            // output can start with a digit, which silently breaks the
            // Bootstrap 4 data-target="#..." selector).
            'courseid' => (int) $course->id,
            'sections' => $sections,
            'hassections' => !empty($sections),
            // Both scope labels, composed once here rather than per activity:
            // each row's "Template" tag carries both as data attributes so
            // template_scope_modal.js can swap its text after a scope change
            // without an extra string lookup.
            'scopelabelcourse' => get_string('template_activity_scope_course', 'local_coursegen'),
            'scopelabelsection' => get_string('template_activity_scope_section', 'local_coursegen'),
        ];
    }

    /**
     * Build one real activity's row context.
     *
     * @param \cm_info $cm
     * @param int $cmid
     * @param array $savedactions Saved action per cmid.
     * @param array $savedscopes Saved templatescope per cmid.
     * @return array
     */
    private static function real_row_context(\cm_info $cm, int $cmid, array $savedactions, array $savedscopes): array {
        $actionoptions = template_row_options::activity_actions($cmid, $cm->modname, $savedactions[$cmid] ?? null);
        $istemplate = template_row_options::active_action($actionoptions) === 'template';
        $scopeoptions = template_row_options::template_scope_options($cmid, $savedscopes[$cmid] ?? 'course');

        // Link to the real activity in the base course; null for modules
        // with no view page of their own (label), whose names stay plain text.
        $viewurl = null;
        if ($cm->url) {
            $viewurl = $cm->url->out(false);
        }

        return [
            'isreal' => true,
            'isinstance' => false,
            'cmid' => $cmid,
            'name' => $cm->get_formatted_name(),
            'viewurl' => $viewurl,
            'modname' => $cm->modname,
            'typelabel' => $cm->get_module_type_name(),
            'iconurl' => $cm->get_icon_url()->out(false),
            'actions' => $actionoptions,
            // Drives the clickable "Template" tag's visibility and initial
            // label — kept in sync with the action select, and with scope
            // changes made through local/template/template_scope_modal.js,
            // by sections_events.js.
            'istemplate' => $istemplate,
            'scopelabel' => template_row_options::active_scope_label($scopeoptions),
        ];
    }

    /**
     * Turn template_instance_layout::ordered_rows()'s output into the final
     * per-row render context.
     *
     * @param array $orderedrows Return value of template_instance_layout::ordered_rows().
     * @param array $activitiesbycmid Real row contexts, keyed by cmid.
     * @return array
     */
    private static function render_rows(array $orderedrows, array $activitiesbycmid): array {
        return array_map(
            fn($entry) => $entry['type'] === 'real'
                ? $activitiesbycmid[$entry['cmid']]
                : ['isreal' => false, 'isinstance' => true] + template_row_options::instance_row_context($entry['record']),
            $orderedrows
        );
    }
}
