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
 * Puts the files of the teacher into the activities a run wrote.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generated_reference_files {
    /**
     * The generated activities with the token of every reference replaced.
     *
     * @param array[] $activities The activity entries of the result.
     * @param array<string,string> $urlbyslot Place named by the payload => address of its file.
     * @return array[]
     * @throws \moodle_exception When a token has no file.
     */
    public static function apply(array $activities, array $urlbyslot): array {
        foreach ($activities as $index => $activity) {
            $activities[$index] = self::apply_to_activity($activity, $urlbyslot);
        }
        return $activities;
    }

    /**
     * One activity entry with the tokens of its parameters replaced.
     *
     * @param array $activity
     * @param array<string,string> $urlbyslot
     * @return array
     */
    public static function apply_to_activity(array $activity, array $urlbyslot): array {
        $parameters = $activity['parameters'] ?? [];
        $name = self::name_of($activity);
        $activity['parameters'] = reference_parameters_resolver::resolve($parameters, $urlbyslot, $name);
        return $activity;
    }

    /**
     * What an activity is called, for the error.
     *
     * @param array $activity
     * @return string
     */
    private static function name_of(array $activity): string {
        $parameters = $activity['parameters'] ?? [];
        $name = $parameters['name'] ?? '';
        if ($name !== '') {
            return (string) $name;
        }
        $uid = $activity['uid'] ?? '';
        return (string) $uid;
    }
}
