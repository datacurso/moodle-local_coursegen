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
 * A mod_page mold: content editor, display mode and the decoded display options.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_mold_export extends base_mold_export {
    #[\Override]
    protected static function payload(cm_info $cm, stdClass $record): array {
        $context = context_module::instance($cm->id);
        $payload = static::common($cm, $record);
        $payload['page'] = static::editor(
            (string) $record->content,
            (int) $record->contentformat,
            $context,
            'mod_page',
            'content',
            0
        );
        $payload['display'] = $record->display;
        return array_merge($payload, static::display_options((string) $record->displayoptions, (int) $record->id));
    }

    /**
     * printintro/printlastmodified as mod_form fields, out of the serialized displayoptions blob.
     *
     * @param string $displayoptions
     * @param int $pageid
     * @return array Empty (schema defaults apply) when the blob is empty or corrupt.
     */
    private static function display_options(string $displayoptions, int $pageid): array {
        if ($displayoptions === '') {
            return [];
        }
        $options = unserialize_array($displayoptions);
        if (!is_array($options)) {
            debugging("local_coursegen: page mold {$pageid} has corrupt displayoptions; defaults apply.", DEBUG_DEVELOPER);
            return [];
        }
        $exported = [];
        foreach (['printintro', 'printlastmodified'] as $key) {
            if (array_key_exists($key, $options)) {
                $exported[$key] = $options[$key];
            }
        }
        return $exported;
    }
}
