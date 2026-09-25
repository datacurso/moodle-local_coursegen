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
 * External API for the professor-facing template guided form: the template's
 * section/activity structure (with the admin-defined lock state applied) and
 * the catalog of activity types the professor may add.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_system;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_section;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\service\template_export_service;
use local_coursegen\local\service\template_instance_layout;
use local_coursegen\output\template_row_options;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Builds the guided-form structure for a given template.
 */
class get_template_structure extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'Template ID'),
        ]);
    }

    /**
     * Return the template's structure, locked/reference state and allowed activity catalog.
     *
     * @param int $templateid Template ID.
     * @return array
     */
    public static function execute($templateid) {
        $params = self::validate_parameters(self::execute_parameters(), ['templateid' => $templateid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $template = template::get_record(['id' => $params['templateid']]);
        if (!$template) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }

        $course  = get_course($template->get('courseid'));
        $modinfo = get_fast_modinfo($course);

        // Index the saved per-section/per-activity settings by their live IDs.
        $sectionsettings = self::section_settings_by_id($template);
        $activitysettings = self::activity_settings_by_cmid($template);
        $instancesbysection = self::instances_by_section($template);

        $sections = self::build_sections($course, $modinfo, $sectionsettings, $activitysettings, $instancesbysection);

        $nolimit = (bool) $template->get('nolimit');
        $maxsections = (int) ($template->get('maxsections') ?? 0);
        // The stored value already IS the extra allowance — how many sections
        // the professor may add ON TOP of the template's own — so the
        // template's sections never get subtracted from it.
        $remaining = 0;
        if (!$nolimit) {
            $remaining = max(0, $maxsections);
        }

        $allowedtypes = self::allowed_types($template);
        $allowedactivities = self::allowed_activities($allowedtypes);

        return [
            'nolimit' => $nolimit,
            'maxsections' => $maxsections,
            'remainingsections' => $remaining,
            'sections' => $sections,
            'allowedactivities' => $allowedactivities,
        ];
    }

    /**
     * Section id => saved behavior, for this template.
     *
     * @param template $template
     * @return array
     */
    private static function section_settings_by_id(template $template): array {
        $sectionsettings = [];
        foreach (template_section::get_records(['templateid' => $template->get('id')]) as $s) {
            $sectionsettings[$s->get('sectionid')] = $s->get('behavior');
        }
        return $sectionsettings;
    }

    /**
     * Cmid => saved action, for this template.
     *
     * @param template $template
     * @return array
     */
    private static function activity_settings_by_cmid(template $template): array {
        $activitysettings = [];
        foreach (template_activity::get_records(['templateid' => $template->get('id')]) as $a) {
            $activitysettings[$a->get('cmid')] = $a->get('action');
        }
        return $activitysettings;
    }

    /**
     * Section id => its virtual instances, for this template.
     *
     * @param template $template
     * @return array
     */
    private static function instances_by_section(template $template): array {
        $instancesbysection = [];
        foreach (template_instance::get_records(['templateid' => $template->get('id')]) as $instance) {
            $instancesbysection[$instance->get('sectionid')][] = $instance;
        }
        return $instancesbysection;
    }

    /**
     * Every section the guided form shows, with its rows.
     *
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param array $sectionsettings
     * @param array $activitysettings
     * @param array $instancesbysection
     * @return array
     */
    private static function build_sections(
        $course,
        $modinfo,
        array $sectionsettings,
        array $activitysettings,
        array $instancesbysection
    ): array {
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $behavior = $sectionsettings[$section->id] ?? 'aimodify';
            if ($behavior === 'exclude') {
                continue;
            }

            // Interleave over ALL real cmids — including rows filtered out
            // below (hidden actions, not uservisible) — so an instance
            // anchored to a filtered-out row keeps its anchor's slot: once
            // the hidden anchor is dropped, the instance renders exactly
            // where that anchor would have been (immediately after the
            // nearest preceding visible row, or at the section start when
            // there is none). Orphan anchors append at the section end,
            // the same rule the admin review follows.
            $rows = template_instance_layout::ordered_rows(
                $modinfo->sections[$section->section] ?? [],
                $instancesbysection[$section->id] ?? []
            );

            $locked = false;
            if ($behavior === 'keep') {
                $locked = true;
            }
            $sections[] = [
                'id'         => (int) $section->id,
                'num'        => (int) $section->section,
                'name'       => get_section_name($course, $section),
                'behavior'   => $behavior,
                'locked'     => $locked,
                'activities' => self::section_activities($rows, $modinfo, $activitysettings),
            ];
        }
        return $sections;
    }

    /**
     * One section's activity rows: its kept real activities and its virtual instances.
     *
     * @param array $rows
     * @param \course_modinfo $modinfo
     * @param array $activitysettings
     * @return array
     */
    private static function section_activities(array $rows, $modinfo, array $activitysettings): array {
        $activities = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'instance') {
                $activities[] = self::instance_row($row['record']);
                continue;
            }
            $cm = $modinfo->cms[$row['cmid']];
            if (!$cm->uservisible) {
                continue;
            }
            // Mirror the admin's action mapping: keep (with unset
            // defaulting to keep, and a legacy saved "modify"
            // normalising to keep) stays visible and locked; reference,
            // template (mold) and exclude rows never reach the
            // professor at all.
            $action = $activitysettings[$cm->id] ?? 'keep';
            if ($action === 'modify') {
                $action = 'keep';
            }
            if ($action !== 'keep') {
                continue;
            }
            $activities[] = self::real_activity_row($cm, $action);
        }
        return $activities;
    }

    /**
     * One kept real activity's row.
     *
     * @param \cm_info $cm
     * @param string $action
     * @return array
     */
    private static function real_activity_row($cm, string $action): array {
        global $OUTPUT;

        return [
            'id'      => (int) $cm->id,
            'name'    => format_string($cm->name),
            'modname' => $cm->modname,
            'purpose' => self::get_purpose($cm->modname),
            'typelabel' => '',
            'iconhtml' => $OUTPUT->image_icon('monologo', $cm->modname, 'mod_' . $cm->modname,
                ['class' => 'icon activityicon']),
            'locked'  => true,
            'action'  => $action,
            'isinstance' => false,
            'aigenerated' => false,
            'generationcmid' => 0,
            'generationuid' => '',
        ];
    }

    /**
     * The template's own list of allowed activity type names.
     *
     * @param template $template
     * @return array
     */
    private static function allowed_types(template $template): array {
        $raw = $template->get('allowedtypes');
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    /**
     * The allowed activity catalog, sorted by display name.
     *
     * @param array $allowedtypes
     * @return array
     */
    private static function allowed_activities(array $allowedtypes): array {
        global $OUTPUT;

        $allowedactivities = [];
        foreach ($allowedtypes as $modname) {
            if (!\core_component::is_valid_plugin_name('mod', $modname)) {
                continue;
            }
            $allowedactivities[] = [
                'modname'     => $modname,
                'displayname' => get_string('pluginname', 'mod_' . $modname),
                'purpose'     => self::get_purpose($modname),
                'iconhtml'    => $OUTPUT->image_icon('monologo', $modname, 'mod_' . $modname, ['class' => 'icon activityicon']),
            ];
        }
        usort($allowedactivities, fn($a, $b) => strcasecmp($a['displayname'], $b['displayname']));
        return $allowedactivities;
    }

    /**
     * Build one virtual-instance activity row ("generate an activity here,
     * molded on one of the template's model activities").
     *
     * Id scheme: the row id is the NEGATIVE of the tpl_instance record id.
     * Every other id in this response is a cmid (always positive), so a
     * negative id can never collide with one, stays stable across reloads,
     * and remains a usable client-side key; the client re-seeds its own
     * placeholder-id counter below the smallest received id so
     * professor-added rows cannot collide either (see state.js).
     *
     * Everything renders from the row's own snapshots (name, typelabel,
     * modname) — sourcecmid is never dereferenced. The icon resolves from
     * the snapshot modname through the exact same monologo rule as the
     * admin review (template_row_options::instance_icon_url()), and an
     * empty snapshot yields an empty iconhtml, not a broken image.
     *
     * @param template_instance $instance
     * @return array
     */
    private static function instance_row(template_instance $instance): array {
        $modname = (string) $instance->get('modname');
        $iconurl = template_row_options::instance_icon_url($instance->get('modname'));
        $iconhtml = '';
        if ($iconurl !== '') {
            $iconhtml = \html_writer::empty_tag('img', ['src' => $iconurl, 'class' => 'icon activityicon', 'alt' => '']);
        }
        $purpose = MOD_PURPOSE_OTHER;
        if ($modname !== '') {
            $purpose = self::get_purpose($modname);
        }
        return [
            'id'      => -((int) $instance->get('id')),
            'name'    => format_string($instance->get('name')),
            'modname' => $modname,
            'purpose' => $purpose,
            'typelabel' => format_string($instance->get('typelabel')),
            'iconhtml' => $iconhtml,
            'locked'  => true,
            'action'  => '',
            'isinstance' => true,
            'aigenerated' => true,
            // The id this row will answer to in the generation's progress
            // events, so the live view can mark THIS activity when its own
            // content lands. Same value template_export_service sends.
            'generationcmid' => template_export_service::instance_cmid((int) $instance->get('id')),
            // The name this row answers to in the generation's answer, which is
            // what a preview of it is asked for by. Read from the instance
            // itself, which is also where the payload reads it, so the link on
            // this page and the answer it opens can never name it differently.
            'generationuid' => template_export_service::instance_uid($instance),
        ];
    }

    /**
     * Resolve a module's Moodle "purpose" (content, assessment, collaboration...).
     *
     * @param string $modname Module name.
     * @return string
     */
    private static function get_purpose(string $modname): string {
        return plugin_supports('mod', $modname, FEATURE_MOD_PURPOSE, MOD_PURPOSE_OTHER);
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'nolimit' => new external_value(PARAM_BOOL, 'Whether the section limit is disabled'),
            'maxsections' => new external_value(PARAM_INT, 'Maximum total sections allowed'),
            'remainingsections' => new external_value(PARAM_INT, 'Additional sections the professor may still add'),
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'id'     => new external_value(PARAM_INT, 'Section ID'),
                    'num'    => new external_value(PARAM_INT, 'Section number'),
                    'name'   => new external_value(PARAM_TEXT, 'Section name'),
                    'behavior' => new external_value(PARAM_ALPHA, 'Admin-configured section behavior (custom/keep)'),
                    'locked' => new external_value(PARAM_BOOL, 'Whether the section is kept as-is from the template'),
                    'activities' => new external_multiple_structure(
                        new external_single_structure([
                            'id'       => new external_value(PARAM_INT,
                                'Course module ID; NEGATIVE (-recordid) for virtual instance rows'),
                            'name'     => new external_value(PARAM_TEXT, 'Activity name'),
                            'modname'  => new external_value(PARAM_ALPHANUMEXT,
                                'Module type name; may be empty on an instance row with no snapshot'),
                            'purpose'  => new external_value(PARAM_ALPHA, 'Activity purpose category'),
                            'typelabel' => new external_value(PARAM_TEXT,
                                'Snapshotted type label for instance rows; empty for real activities'),
                            'iconhtml' => new external_value(PARAM_RAW, 'Rendered module icon HTML; may be empty'),
                            'locked'   => new external_value(PARAM_BOOL, 'Always true — activities from the template are reference-only'),
                            'action'   => new external_value(PARAM_ALPHA,
                                'Resolved admin action ("keep"); empty for virtual instance rows'),
                            'isinstance' => new external_value(PARAM_BOOL, 'Whether this is a virtual instance row'),
                            'aigenerated' => new external_value(PARAM_BOOL,
                                'Whether AI will generate this activity in the new course (drives the badge)'),
                            'generationcmid' => new external_value(PARAM_INT,
                                'Id this row answers to in the generation progress events; 0 when it is not generated'),
                            'generationuid' => new external_value(PARAM_ALPHANUMEXT,
                                'Name this row answers to in the generation answer; empty when it is not generated'),
                        ])
                    ),
                ])
            ),
            'allowedactivities' => new external_multiple_structure(
                new external_single_structure([
                    'modname'     => new external_value(PARAM_ALPHANUMEXT, 'Module type name'),
                    'displayname' => new external_value(PARAM_TEXT, 'Human-readable module name'),
                    'purpose'     => new external_value(PARAM_ALPHA, 'Activity purpose category'),
                    'iconhtml'    => new external_value(PARAM_RAW, 'Rendered module icon HTML'),
                ])
            ),
        ]);
    }
}
