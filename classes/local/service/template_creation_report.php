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

/**
 * Tells the teacher, in plain words, what the course made from a template is missing.
 *
 * A course is created even when one activity, one file or some parts of an activity could not be made: the
 * teacher gets the course and a note for each thing that was left out, instead of a course that is not created
 * because of a single failure. Nothing here changes what was built; it only describes it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_creation_report {
    /** @var string Prefix of the settings that count the parts the generator could not write, e.g. skipped_chapters. */
    private const SKIPPED_PREFIX = 'skipped_';

    /**
     * The notes about what the course is missing, as one text; empty when nothing is missing.
     *
     * @param array $created The result of the course creation.
     * @param string[] $failedfiles Names of the files that could not be put in their resource.
     * @param array $activities The activities the AI wrote, as the result describes them.
     * @return string
     */
    public static function warnings(array $created, array $failedfiles, array $activities): string {
        $failed = $created['activityerrors'] ?? [];
        $notes = self::failed_activities((array) $failed);
        $filenotes = self::unapplied_files($failedfiles);
        $skippednotes = self::skipped_parts($activities);
        $all = array_merge($notes, $filenotes, $skippednotes);
        return implode(' ', $all);
    }

    /**
     * One note for each activity that could not be created.
     *
     * @param array $errors The activity errors of the creation.
     * @return string[]
     */
    private static function failed_activities(array $errors): array {
        $notes = [];
        foreach ($errors as $error) {
            $rawtitle = $error['title'] ?? '';
            $title = trim((string) $rawtitle);
            if ($title === '') {
                $type = $error['resource_type'] ?? '';
                $title = (string) $type;
            }
            $notes[] = get_string('templateactivitynotcreated', 'local_coursegen', $title);
        }
        return $notes;
    }

    /**
     * The note about the files that could not be put in their resource, when there are any.
     *
     * @param string[] $failedfiles
     * @return string[]
     */
    private static function unapplied_files(array $failedfiles): array {
        if ($failedfiles === []) {
            return [];
        }
        $names = implode(', ', $failedfiles);
        return [get_string('templatefilesnotapplied', 'local_coursegen', $names)];
    }

    /**
     * One note for each activity that came back with parts the generator could not write.
     *
     * @param array $activities
     * @return string[]
     */
    private static function skipped_parts(array $activities): array {
        $notes = [];
        foreach ($activities as $activity) {
            $activitynotes = self::skipped_in((array) $activity);
            $notes = array_merge($notes, $activitynotes);
        }
        return $notes;
    }

    /**
     * The notes of one activity: one for each setting that counts parts left out.
     *
     * @param array $activity
     * @return string[]
     */
    private static function skipped_in(array $activity): array {
        $rawparameters = $activity['parameters'] ?? [];
        $parameters = (array) $rawparameters;
        $rawsettings = $parameters['mod_settings'] ?? [];
        $modsettings = (array) $rawsettings;
        $rawname = $parameters['name'] ?? '';
        $name = (string) $rawname;
        $notes = [];
        foreach ($modsettings as $key => $value) {
            $skipped = self::skipped_count((string) $key, $value);
            if ($skipped > 0) {
                $details = (object) ['name' => $name, 'count' => $skipped];
                $notes[] = get_string('templateskippedparts', 'local_coursegen', $details);
            }
        }
        return $notes;
    }

    /**
     * How many parts a setting says were left out; zero when it is not such a setting.
     *
     * @param string $key The name of the setting.
     * @param mixed $value Its value.
     * @return int
     */
    private static function skipped_count(string $key, $value): int {
        if (!str_starts_with($key, self::SKIPPED_PREFIX)) {
            return 0;
        }
        if (!is_numeric($value)) {
            return 0;
        }
        return max(0, (int) $value);
    }
}
