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
use external_value;
use context_system;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_section;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\service\template_instance_layout;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Builds the guided-form structure for a given template.
 */
class get_template_structure extends external_api {
    use get_template_structure_rows;
    use get_template_structure_schema;

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
        $instancesbysection = [];
        foreach (template_instance::get_records(['templateid' => $template->get('id')]) as $instance) {
            $instancesbysection[$instance->get('sectionid')][] = $instance;
        }

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
                $activities[] = [
                    'id'      => (string) $cm->id,
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

            $sections[] = [
                'id'         => (int) $section->id,
                'num'        => (int) $section->section,
                'name'       => get_section_name($course, $section),
                'behavior'   => $behavior,
                'locked'     => ($behavior === 'keep'),
                'activities' => $activities,
            ];
        }

        $nolimit = (bool) $template->get('nolimit');
        $maxsections = (int) ($template->get('maxsections') ?? 0);
        // The stored value already IS the extra allowance — how many sections
        // the professor may add ON TOP of the template's own — so the
        // template's sections never get subtracted from it.
        $remaining = $nolimit ? 0 : max(0, $maxsections);

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

}
