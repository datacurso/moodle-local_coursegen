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

namespace local_coursegen\local\reference;

/**
 * Puts the file of the teacher where the service left the token of a reference.
 *
 * It reads every text of the parameters of a generated activity, whatever the
 * module, so no module has to be listed. A token that is left after the
 * replacement stops the run: no token ever reaches a course or a preview.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_parameters_resolver {
    /**
     * The parameters of an activity with its tokens replaced.
     *
     * @param array $parameters
     * @param array<string,string> $urlbyslot Place named by the payload => address of its file.
     * @param string $activityname The activity, for the error.
     * @return array
     * @throws \moodle_exception When a token has no file.
     */
    public static function resolve(array $parameters, array $urlbyslot, string $activityname): array {
        $token = new reference_file_token($urlbyslot);
        return self::resolve_node($parameters, $token, $activityname);
    }

    /**
     * One node of the parameters, with everything under it.
     *
     * @param array $node
     * @param reference_file_token $token
     * @param string $activityname
     * @return array
     */
    private static function resolve_node(array $node, reference_file_token $token, string $activityname): array {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::resolve_node($value, $token, $activityname);
                continue;
            }
            if (is_string($value)) {
                $node[$key] = self::resolve_text($value, $token, $activityname);
            }
        }
        return $node;
    }

    /**
     * One text, with its tokens replaced.
     *
     * @param string $text
     * @param reference_file_token $token
     * @param string $activityname
     * @return string
     * @throws \moodle_exception When a token remains.
     */
    private static function resolve_text(string $text, reference_file_token $token, string $activityname): string {
        $resolved = $token->replace($text);
        $remaining = reference_file_token::first_remaining($resolved);
        if ($remaining !== null) {
            $details = (object) ['activity' => $activityname, 'slot' => $remaining];
            throw new \moodle_exception('referenceunresolved', 'local_coursegen', '', $details);
        }
        return $resolved;
    }
}
