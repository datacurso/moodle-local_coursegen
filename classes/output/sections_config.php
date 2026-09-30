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
use local_coursegen\local\models\template_space;
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

        $context = self::export_for_template($modinfo, $templateid);
        return $OUTPUT->render_from_template('local_coursegen/template_course_sections', $context);
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
        $saved = self::saved_configuration($templateid);

        $sections = [];
        $sectioninfos = $modinfo->get_section_info_all();
        foreach ($sectioninfos as $sectioninfo) {
            $sections[] = self::section_context($modinfo, $course, $sectioninfo, $saved);
        }

        $courseid = (int) $course->id;
        $scopelabelcourse = get_string('template_activity_scope_course', 'local_coursegen');
        $scopelabelsection = get_string('template_activity_scope_section', 'local_coursegen');
        $spacebadgerequired = template_row_options::space_badge_label(true);
        $spacebadgeoptional = template_row_options::space_badge_label(false);

        return [
            // The collapse ids are built from course id + section id: they
            // must be deterministic AND valid CSS identifiers ({{uniqid}}
            // output can start with a digit, which silently breaks the
            // Bootstrap 4 data-target="#..." selector).
            'courseid' => $courseid,
            'sections' => $sections,
            'hassections' => !empty($sections),
            // Both scope labels, composed once here rather than per activity:
            // each row's "Template" tag carries both as data attributes so
            // template_scope_modal.js can swap its text after a scope change
            // without an extra string lookup.
            'scopelabelcourse' => $scopelabelcourse,
            'scopelabelsection' => $scopelabelsection,
            // Both space badge labels, for the same reason: the row's badge
            // swaps between them after a requirement change without a string
            // lookup.
            'spacebadgerequired' => $spacebadgerequired,
            'spacebadgeoptional' => $spacebadgeoptional,
        ];
    }

    /**
     * Everything a template already saved, keyed for the lookups the render
     * makes: empty for a new template (id 0).
     *
     * @param int $templateid Existing template id, 0 for a new template.
     * @return array {behaviors, actions, scopes, spaces, instances}
     */
    private static function saved_configuration(int $templateid): array {
        $saved = ['behaviors' => [], 'actions' => [], 'scopes' => [], 'spaces' => [], 'instances' => []];
        if ($templateid <= 0) {
            return $saved;
        }
        $saved['behaviors'] = self::saved_behaviors($templateid);
        $activitysettings = self::saved_activity_settings($templateid);
        $saved = array_merge($saved, $activitysettings);
        $saved['instances'] = self::saved_virtual_rows($templateid);
        return $saved;
    }

    /**
     * The saved behavior of each section, keyed by section id.
     *
     * @param int $templateid
     * @return array
     */
    private static function saved_behaviors(int $templateid): array {
        $records = template_section::get_records(['templateid' => $templateid]);
        $behaviors = [];
        foreach ($records as $record) {
            $sectionid = (int) $record->get('sectionid');
            $behaviors[$sectionid] = $record->get('behavior');
        }
        return $behaviors;
    }

    /**
     * The saved action, template scope and space settings of each activity,
     * keyed by cmid.
     *
     * @param int $templateid
     * @return array {actions, scopes, spaces}
     */
    private static function saved_activity_settings(int $templateid): array {
        $records = template_activity::get_records(['templateid' => $templateid]);
        $actions = [];
        $scopes = [];
        $spaces = [];
        foreach ($records as $record) {
            $cmid = (int) $record->get('cmid');
            $actions[$cmid] = $record->get('action');
            $scopes[$cmid] = $record->get('templatescope');
            $required = (bool) $record->get('spacerequired');
            $instruction = (string) $record->get('spaceinstruction');
            $spaces[$cmid] = ['required' => $required, 'instruction' => $instruction];
        }
        return ['actions' => $actions, 'scopes' => $scopes, 'spaces' => $spaces];
    }

    /**
     * The saved template instances and spaces, grouped by section id.
     *
     * @param int $templateid
     * @return array
     */
    private static function saved_virtual_rows(int $templateid): array {
        $instances = template_instance::get_records(['templateid' => $templateid]);
        $spaces = template_space::get_records(['templateid' => $templateid]);
        $virtualrows = array_merge($instances, $spaces);
        $bysection = [];
        foreach ($virtualrows as $record) {
            $sectionid = (int) $record->get('sectionid');
            $bysection[$sectionid][] = $record;
        }
        return $bysection;
    }

    /**
     * Build one section's card context.
     *
     * @param \course_modinfo $modinfo The base course modinfo.
     * @param \stdClass $course The base course.
     * @param \section_info $sectioninfo The section.
     * @param array $saved The saved configuration (see saved_configuration()).
     * @return array
     */
    private static function section_context(
        \course_modinfo $modinfo,
        \stdClass $course,
        \section_info $sectioninfo,
        array $saved
    ): array {
        $sectionid = (int) $sectioninfo->id;
        $activitiesbycmid = self::real_rows_of_section($modinfo, $sectioninfo, $saved);
        $realcmids = array_keys($activitiesbycmid);
        $virtualrows = $saved['instances'][$sectionid] ?? [];
        $orderedrows = template_instance_layout::ordered_rows($realcmids, $virtualrows);
        $rows = self::render_rows($orderedrows, $activitiesbycmid);
        $behavior = $saved['behaviors'][$sectionid] ?? template_section::BEHAVIOR_AI_MODIFY;
        $name = get_section_name($course, $sectioninfo);
        $actions = template_row_options::section_actions($sectionid, $behavior);
        $activitycount = count($rows);

        return [
            'id' => $sectionid,
            'num' => (int) $sectioninfo->section,
            'name' => $name,
            'activitycount' => $activitycount,
            'hasactivities' => !empty($rows),
            'rows' => $rows,
            'actions' => $actions,
        ];
    }

    /**
     * Build the row context of every real activity of a section, keyed by
     * cmid, in course order and without activities being deleted.
     *
     * @param \course_modinfo $modinfo The base course modinfo.
     * @param \section_info $sectioninfo The section.
     * @param array $saved The saved configuration (see saved_configuration()).
     * @return array
     */
    private static function real_rows_of_section(\course_modinfo $modinfo, \section_info $sectioninfo, array $saved): array {
        $rows = [];
        $cmids = $modinfo->sections[$sectioninfo->section] ?? [];
        foreach ($cmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->deletioninprogress) {
                continue;
            }
            $realcmid = (int) $cm->id;
            $rows[$realcmid] = self::real_row_context($cm, $realcmid, $saved);
        }
        return $rows;
    }

    /**
     * Build one real activity's row context.
     *
     * @param \cm_info $cm
     * @param int $cmid
     * @param array $saved The saved configuration (see saved_configuration()).
     * @return array
     */
    private static function real_row_context(\cm_info $cm, int $cmid, array $saved): array {
        $savedaction = $saved['actions'][$cmid] ?? null;
        $actionoptions = template_row_options::activity_actions($cmid, $cm->modname, $savedaction);
        $activeaction = template_row_options::active_action($actionoptions);
        $savedscope = $saved['scopes'][$cmid] ?? template_activity::SCOPE_COURSE;
        $scopeoptions = template_row_options::template_scope_options($cmid, $savedscope);
        $scopelabel = template_row_options::active_scope_label($scopeoptions);
        $space = $saved['spaces'][$cmid] ?? ['required' => true, 'instruction' => ''];
        $isspace = $activeaction === template_activity::ACTION_SPACE;
        $spacebadge = template_row_options::space_badge_label($space['required']);

        // Link to the real activity in the base course; null for modules
        // with no view page of their own (label), whose names stay plain text.
        $viewurl = null;
        if ($cm->url) {
            $viewurl = $cm->url->out(false);
        }
        $icon = $cm->get_icon_url();
        $iconurl = $icon->out(false);
        $name = $cm->get_formatted_name();
        $typelabel = $cm->get_module_type_name();

        return [
            'isreal' => true,
            'isinstance' => false,
            'cmid' => $cmid,
            'name' => $name,
            'viewurl' => $viewurl,
            'modname' => $cm->modname,
            'typelabel' => $typelabel,
            'iconurl' => $iconurl,
            'actions' => $actionoptions,
            // Drives the clickable "Template" tag's visibility and initial
            // label — kept in sync with the action select, and with scope
            // changes made through local/template/template_scope_modal.js,
            // by sections_events.js.
            'istemplate' => $activeaction === template_activity::ACTION_TEMPLATE,
            'scopelabel' => $scopelabel,
            // Drives the clickable "Space" badge the same way: hidden unless
            // the row's action is "space", reopening the space settings.
            'isspace' => $isspace,
            'spacerequired' => $space['required'],
            'spacerequiredvalue' => (int) $space['required'],
            'spaceinstruction' => $space['instruction'],
            'hasspaceinstruction' => $isspace && $space['instruction'] !== '',
            'spacebadge' => $spacebadge,
        ];
    }

    /**
     * Turn template_instance_layout::ordered_rows()'s output into the final
     * per-row render context.
     *
     * The last row never gets its own trailing insertion gap: the
     * section's persistent "Add activity" row immediately follows it and
     * already covers that exact position, so a hover-reveal "+" right in
     * front of it would just be the same affordance rendered twice.
     *
     * @param array $orderedrows Return value of template_instance_layout::ordered_rows().
     * @param array $activitiesbycmid Real row contexts, keyed by cmid.
     * @return array
     */
    private static function render_rows(array $orderedrows, array $activitiesbycmid): array {
        $rows = [];
        $lastindex = count($orderedrows) - 1;
        $index = 0;
        foreach ($orderedrows as $entry) {
            if ($entry['type'] === template_instance_layout::TYPE_REAL) {
                $context = $activitiesbycmid[$entry['cmid']];
            } else if ($entry['type'] === template_instance_layout::TYPE_SPACE) {
                $context = ['isreal' => false, 'isinstance' => false, 'isvirtualspace' => true]
                    + template_row_options::space_row_context($entry['record']);
            } else {
                $context = ['isreal' => false, 'isinstance' => true]
                    + template_row_options::instance_row_context($entry['record']);
            }
            $context['islast'] = ($index === $lastindex);
            $rows[] = $context;
            $index++;
        }
        return $rows;
    }
}
