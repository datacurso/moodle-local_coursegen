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
 * A mod_workshop mold: settings, the four editors, its accumulative criteria and the phase it sits in.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_mold_export extends base_mold_export {
    /** @var string[] Settings columns copied verbatim. */
    private const SETTINGS = [
        'strategy', 'grade', 'gradinggrade', 'gradedecimals', 'nattachments', 'submissiontypetext',
        'submissiontypefile', 'submissionfiletypes', 'maxbytes', 'latesubmissions', 'useselfassessment',
        'overallfeedbackmode', 'overallfeedbackfiles', 'useexamples', 'examplesmode', 'submissionstart',
        'submissionend', 'assessmentstart', 'assessmentend', 'phaseswitchassessment',
    ];

    /** @var string[] Workshop phase constants to the tokens workshop_settings accepts. */
    private const PHASE_TOKENS = [
        10 => 'setup', // \workshop::PHASE_SETUP.
        20 => 'submission', // \workshop::PHASE_SUBMISSION.
        30 => 'assessment', // \workshop::PHASE_ASSESSMENT.
        40 => 'evaluation', // \workshop::PHASE_EVALUATION.
    ];

    #[\Override]
    protected static function payload(cm_info $cm, stdClass $record): array {
        $context = context_module::instance($cm->id);
        $payload = array_merge(static::common($cm, $record), static::instance_columns($record, self::SETTINGS));

        // The two "grade to pass" values are not workshop columns: they live on the grade items.
        foreach (['submissiongradepass' => 0, 'gradinggradepass' => 1] as $key => $itemnumber) {
            $gradepass = static::grade_pass('workshop', (int) $record->id, $itemnumber);
            if ($gradepass !== null) {
                $payload[$key] = $gradepass;
            }
        }

        foreach (['instructauthors', 'instructreviewers', 'conclusion'] as $field) {
            $payload[$field . 'editor'] = static::editor(
                (string) ($record->$field ?? ''),
                (int) ($record->{$field . 'format'} ?? FORMAT_HTML),
                $context,
                'mod_workshop',
                $field,
                0
            );
        }

        $modsettings = [];
        if ($record->strategy === 'accumulative') {
            $modsettings['criteria'] = static::criteria((int) $record->id);
        } else {
            debugging(
                "local_coursegen: workshop mold {$record->id} uses strategy '{$record->strategy}'; " .
                'only accumulative criteria are exported.',
                DEBUG_DEVELOPER
            );
        }
        $modsettings['initial_phase'] = self::PHASE_TOKENS[(int) $record->phase] ?? null;
        $payload['mod_settings'] = $modsettings;
        return $payload;
    }

    /**
     * The accumulative assessment form's dimensions, in display order.
     *
     * @param int $workshopid
     * @return array
     */
    private static function criteria(int $workshopid): array {
        global $DB;

        $rows = $DB->get_records('workshopform_accumulative', ['workshopid' => $workshopid], 'sort ASC, id ASC');
        $criteria = [];
        foreach ($rows as $row) {
            $criteria[] = [
                'description' => (string) $row->description,
                'max_points' => (int) $row->grade,
                'weight' => (int) $row->weight,
            ];
        }
        return $criteria;
    }
}
