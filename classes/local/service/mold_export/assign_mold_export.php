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

namespace local_coursegen\local\service\mold_export;

use cm_info;
use context_module;
use stdClass;

/**
 * A mod_assign mold: settings, both editors, the flattened plugin config, grade pass and the active rubric.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_mold_export extends base_mold_export {
    /** @var string[] Settings columns copied verbatim. */
    private const SETTINGS = [
        'alwaysshowdescription', 'allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate', 'grade',
        'submissiondrafts', 'requiresubmissionstatement', 'maxattempts', 'attemptreopenmethod', 'teamsubmission',
        'requireallteammemberssubmit', 'preventsubmissionnotingroup', 'blindmarking', 'hidegrader',
        'markingworkflow', 'markingallocation', 'markinganonymous', 'sendnotifications', 'sendlatenotifications',
        'sendstudentnotifications', 'completionsubmit',
    ];

    /** @var string[] assign_plugin_config names whose mod_form field is named differently. */
    private const CONFIG_NAMES = [
        'filetypeslist' => 'filetypes',
        'maxfilesubmissions' => 'maxfiles',
        'maxsubmissionsizebytes' => 'maxsizebytes',
        'wordlimitenabled' => 'wordlimit_enabled',
    ];

    #[\Override]
    protected static function payload(cm_info $cm, stdClass $record): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $context = context_module::instance($cm->id);
        $payload = array_merge(static::common($cm, $record), static::instance_columns($record, self::SETTINGS));
        $payload['activityeditor'] = static::editor(
            (string) ($record->activity ?? ''),
            (int) ($record->activityformat ?? FORMAT_HTML),
            $context,
            'mod_assign',
            ASSIGN_ACTIVITYATTACHMENT_FILEAREA,
            0
        );
        $payload = array_merge($payload, static::plugin_config((int) $record->id));

        $gradepass = static::grade_pass('assign', (int) $record->id, 0);
        if ($gradepass !== null) {
            $payload['gradepass'] = $gradepass;
        }

        $manager = get_grading_manager($context, 'mod_assign', 'submissions');
        $method = (string) $manager->get_active_method();
        $payload['advancedgradingmethod_submissions'] = $method;
        if ($method === 'rubric') {
            $rubric = static::rubric($manager);
            if ($rubric !== null) {
                $payload['mod_settings'] = ['rubric' => $rubric];
            }
        }
        return $payload;
    }

    /**
     * Every submission/feedback plugin setting as "{subtype}_{plugin}_{field}", the way mod_form posts it.
     *
     * @param int $assignid
     * @return array
     */
    private static function plugin_config(int $assignid): array {
        global $DB;

        $config = [];
        $rows = $DB->get_records('assign_plugin_config', ['assignment' => $assignid], 'subtype, plugin, name');
        foreach ($rows as $row) {
            $name = self::CONFIG_NAMES[$row->name] ?? $row->name;
            $config["{$row->subtype}_{$row->plugin}_{$name}"] = $row->value;
        }
        return $config;
    }

    /**
     * The active rubric definition in the shape the service's AssignRubricAI schema declares.
     *
     * @param \grading_manager $manager
     * @return array|null Null when no usable definition exists.
     */
    private static function rubric(\grading_manager $manager): ?array {
        $controller = $manager->get_controller('rubric');
        if (!$controller->is_form_defined()) {
            return null;
        }
        $definition = $controller->get_definition();
        $criteria = [];
        foreach ($definition->rubric_criteria ?? [] as $criterion) {
            $levels = [];
            foreach ($criterion['levels'] ?? [] as $level) {
                $levels[] = ['definition' => (string) $level['definition'], 'points' => (float) $level['score']];
            }
            if (count($levels) < 2) {
                // The service's rubric schema needs at least two levels per criterion.
                debugging(
                    "local_coursegen: rubric criterion '{$criterion['description']}' has fewer than two levels; skipped.",
                    DEBUG_DEVELOPER
                );
                continue;
            }
            usort($levels, static fn(array $a, array $b): int => $a['points'] <=> $b['points']);
            $criteria[] = ['description' => (string) $criterion['description'], 'levels' => $levels];
        }
        if (empty($criteria)) {
            debugging('local_coursegen: assign mold rubric has no usable criteria; rubric omitted.', DEBUG_DEVELOPER);
            return null;
        }
        return ['name' => (string) $definition->name, 'criteria' => $criteria];
    }
}
