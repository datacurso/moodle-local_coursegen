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

use local_coursegen\local\models\template_space;

/**
 * The activities a teacher adds in the spaces of a template when they create a course: one per space, of the types the
 * admin allowed there, written by the AI with the admin's instruction for that type and the teacher's own.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class teacher_space_choices {
    /** The placeholder course module ids of the activities of the spaces start here, below every instance's. */
    const CMID_BASE = 1000000000;

    /**
     * The choices, checked against the spaces of the template.
     *
     * @param int $templateid
     * @param array $picked Each {uid, modname, instruction}, as the creation screen sent them.
     * @return array[] Space uid => {space: template_space, modname, instruction, admininstruction}.
     * @throws \moodle_exception When a choice is not allowed in its space or a required space has none.
     */
    public static function validated(int $templateid, array $picked): array {
        $spaces = self::spaces_of($templateid);
        $chosen = [];
        foreach ($picked as $entry) {
            $uid = (string) ($entry['uid'] ?? '');
            $space = $spaces[$uid] ?? null;
            $modname = (string) ($entry['modname'] ?? '');
            $option = $space ? self::option_of($space, $modname) : null;
            if ($option === null || isset($chosen[$uid])) {
                throw new \moodle_exception('courseai_template_choice_space_invalid', 'local_coursegen');
            }
            $chosen[$uid] = [
                'space' => $space,
                'modname' => $modname,
                'instruction' => trim((string) ($entry['instruction'] ?? '')),
                'admininstruction' => $option['instruction'],
            ];
        }
        foreach ($spaces as $uid => $space) {
            if ((int) $space->get('required') === 1 && !isset($chosen[$uid])) {
                throw new \moodle_exception('courseai_template_choice_space_missing_error', 'local_coursegen');
            }
        }
        return $chosen;
    }

    /**
     * The activities of the spaces, as activities of the generation: written freely by the AI, named by it, in the
     * place of their space.
     *
     * @param array[] $chosen What {@see self::validated()} returned.
     * @param array $sectionnums Section id => section number of the template course.
     * @return array[] Activity entries of the init payload.
     */
    public static function payload_entries(array $chosen, array $sectionnums): array {
        $entries = [];
        $counts = [];
        foreach ($chosen as $uid => $choice) {
            $space = $choice['space'];
            $modname = $choice['modname'];
            $counts[$modname] = ($counts[$modname] ?? 0) + 1;
            $prompt = trim($choice['admininstruction'] . "\n\n" . $choice['instruction']);
            $entries[] = [
                'resource_type' => $modname,
                'uid' => (string) $uid,
                'cmid' => self::cmid_of($space),
                'parameters' => [
                    'name' => get_string('modulename', 'mod_' . $modname) . ' ' . $counts[$modname],
                    'section' => $sectionnums[(int) $space->get('sectionid')] ?? 0,
                ],
                'template_behavior' => [
                    'action' => 'instance',
                    'useasreference' => true,
                    'prompt' => $prompt,
                    'template_source_cmid' => 0,
                    'name_from_ai' => true,
                ],
            ];
        }
        return $entries;
    }

    /**
     * The placeholder course module id of the activity of a space in the generation.
     *
     * @param template_space $space
     * @return int
     */
    public static function cmid_of(template_space $space): int {
        return -(self::CMID_BASE + (int) $space->get('id'));
    }

    /**
     * The spaces of the template that offer activities to the teacher, by uid.
     *
     * @param int $templateid
     * @return array<string,template_space>
     */
    public static function spaces_of(int $templateid): array {
        $spaces = [];
        foreach (template_space::get_records(['templateid' => $templateid]) as $space) {
            if (teacher_space_options::decode($space->get('options'))) {
                $spaces[(string) $space->get('uid')] = $space;
            }
        }
        return $spaces;
    }

    /**
     * The option of a space for a type, or null when the space does not offer it.
     *
     * @param template_space $space
     * @param string $modname
     * @return array|null {modname, instruction}
     */
    private static function option_of(template_space $space, string $modname): ?array {
        foreach (teacher_space_options::decode($space->get('options')) as $option) {
            if ($option['modname'] === $modname) {
                return $option;
            }
        }
        return null;
    }
}
