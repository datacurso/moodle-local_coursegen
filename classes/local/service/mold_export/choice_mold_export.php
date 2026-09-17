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
use stdClass;

/**
 * A mod_choice mold: its option rows in mod_form shape plus every choice setting.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class choice_mold_export extends base_mold_export {
    /** @var string[] Settings columns copied verbatim. */
    private const SETTINGS = [
        'allowmultiple', 'allowupdate', 'limitanswers', 'showavailable', 'showpreview', 'showresults', 'publish',
        'showunanswered', 'includeinactive', 'display', 'timeopen', 'timeclose', 'completionsubmit',
    ];

    #[\Override]
    protected static function payload(cm_info $cm, stdClass $record): array {
        global $DB;

        $options = $DB->get_records('choice_options', ['choiceid' => $record->id], 'id ASC', 'id, text, maxanswers');
        $texts = [];
        $limits = [];
        foreach ($options as $option) {
            $texts[] = (string) $option->text;
            $limits[] = (int) $option->maxanswers;
        }
        return array_merge(
            static::common($cm, $record),
            [
                'option' => $texts,
                'limit' => $limits,
                'optionid' => array_fill(0, count($texts), 0),
                'option_repeats' => count($texts),
            ],
            static::instance_columns($record, self::SETTINGS)
        );
    }
}
