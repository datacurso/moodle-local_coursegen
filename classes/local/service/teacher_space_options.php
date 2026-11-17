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

namespace local_coursegen\local\service;

use local_coursegen\local\ai_activity_types;

/**
 * The activities a teacher can add in a space of a template, as the space keeps them: a JSON list of
 * {modname, instruction}, only of the types the AI can write, each type once.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class teacher_space_options {
    /**
     * The options as they are kept, or null when there are none (a space of the earlier kind).
     *
     * @param string $json What the editor sent.
     * @return string|null
     */
    public static function clean(string $json): ?string {
        $options = self::decode($json);
        return $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * The options of a space.
     *
     * @param string|null $json What the space keeps.
     * @return array[] Each {modname, instruction}.
     */
    public static function decode(?string $json): array {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $options = [];
        foreach ($decoded as $entry) {
            $modname = is_array($entry) ? (string) ($entry['modname'] ?? '') : '';
            if (!in_array($modname, ai_activity_types::MODNAMES, true) || isset($options[$modname])) {
                continue;
            }
            $options[$modname] = ['modname' => $modname, 'instruction' => trim((string) ($entry['instruction'] ?? ''))];
        }
        return array_values($options);
    }
}
