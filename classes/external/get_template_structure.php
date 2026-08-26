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
        global $OUTPUT;

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
        $sectionsettings = [];
        foreach (template_section::get_records(['templateid' => $template->get('id')]) as $s) {
            $sectionsettings[$s->get('sectionid')] = $s->get('behavior');
        }
        $activitysettings = [];
        foreach (template_activity::get_records(['templateid' => $template->get('id')]) as $a) {
            $activitysettings[$a->get('cmid')] = $a->get('action');
        }

        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $behavior = $sectionsettings[$section->id] ?? 'custom';
            if ($behavior === 'exclude') {
                continue;
            }

            $activities = [];
            if (!empty($modinfo->sections[$section->section])) {
                foreach ($modinfo->sections[$section->section] as $cmid) {
                    $cm = $modinfo->cms[$cmid];
                    if (!$cm->uservisible) {
                        continue;
                    }
                    $action = $activitysettings[$cm->id] ?? 'modify';
                    if ($action === 'exclude') {
                        continue;
                    }
                    $activities[] = [
                        'id'      => (int) $cm->id,
                        'name'    => format_string($cm->name),
                        'modname' => $cm->modname,
                        'purpose' => self::get_purpose($cm->modname),
                        'iconhtml' => $OUTPUT->image_icon('monologo', $cm->modname, 'mod_' . $cm->modname,
                            ['class' => 'icon activityicon']),
                        'locked'  => true,
                    ];
                }
            }

            $sections[] = [
                'id'         => (int) $section->id,
                'num'        => (int) $section->section,
                'name'       => get_section_name($course, $section),
                'locked'     => ($behavior === 'keep'),
                'activities' => $activities,
            ];
        }

        $nolimit = (bool) $template->get('nolimit');
        $maxsections = (int) ($template->get('maxsections') ?? 0);
        $remaining = $nolimit ? 0 : max(0, $maxsections - count($sections));

        $allowedtypes = [];
        $raw = $template->get('allowedtypes');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $allowedtypes = $decoded;
            }
        }

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

        return [
            'nolimit' => $nolimit,
            'maxsections' => $maxsections,
            'remainingsections' => $remaining,
            'sections' => $sections,
            'allowedactivities' => $allowedactivities,
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
                    'locked' => new external_value(PARAM_BOOL, 'Whether the section is kept as-is from the template'),
                    'activities' => new external_multiple_structure(
                        new external_single_structure([
                            'id'       => new external_value(PARAM_INT, 'Course module ID'),
                            'name'     => new external_value(PARAM_TEXT, 'Activity name'),
                            'modname'  => new external_value(PARAM_ALPHANUMEXT, 'Module type name'),
                            'purpose'  => new external_value(PARAM_ALPHA, 'Activity purpose category'),
                            'iconhtml' => new external_value(PARAM_RAW, 'Rendered module icon HTML'),
                            'locked'   => new external_value(PARAM_BOOL, 'Always true — activities from the template are reference-only'),
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
