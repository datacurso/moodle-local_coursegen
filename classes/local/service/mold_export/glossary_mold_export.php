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
 * A mod_glossary mold: its settings plus the approved teacher entries as rows.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class glossary_mold_export extends base_mold_export {
    /** @var string[] Settings columns copied verbatim. */
    private const SETTINGS = [
        'displayformat', 'approvaldisplayformat', 'entbypage', 'defaultapproval', 'editalways',
        'allowduplicatedentries', 'allowcomments', 'usedynalink', 'showalphabet', 'showall', 'showspecial',
        'allowprintview', 'assessed', 'scale', 'mainglossary', 'globalglossary', 'completionentries',
    ];

    #[\Override]
    protected static function payload(cm_info $cm, stdClass $record): array {
        return array_merge(
            static::common($cm, $record),
            static::instance_columns($record, self::SETTINGS),
            ['mod_settings' => ['entries' => static::entries($cm, (int) $record->id)]]
        );
    }

    /**
     * The mold's own entries: teacher-authored and approved, in creation order.
     *
     * @param cm_info $cm
     * @param int $glossaryid
     * @return array
     */
    private static function entries(cm_info $cm, int $glossaryid): array {
        global $DB;

        $context = context_module::instance($cm->id);
        $records = $DB->get_records(
            'glossary_entries',
            ['glossaryid' => $glossaryid, 'approved' => 1, 'teacherentry' => 1],
            'id ASC',
            'id, concept, definition, definitionformat'
        );
        $entries = [];
        foreach ($records as $entry) {
            $entries[] = [
                'concept' => (string) $entry->concept,
                'definition_editor' => static::editor(
                    (string) $entry->definition,
                    (int) $entry->definitionformat,
                    $context,
                    'mod_glossary',
                    'entry',
                    (int) $entry->id
                ),
            ];
        }
        return $entries;
    }
}
