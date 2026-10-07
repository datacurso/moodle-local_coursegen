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
 * Decides whether a course made from a template is complete.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_apply_guard {
    /**
     * Fails when an activity or a file of the template could not be put in the course.
     *
     * @param array $created The result of the course creation.
     * @param string[] $unappliedfiles Names of the files that could not be put in their resource.
     * @return void
     * @throws \moodle_exception When something is missing from the course.
     */
    public static function ensure_complete(array $created, array $unappliedfiles): void {
        $errors = $created['activityerrors'] ?? [];
        $partial = !empty($created['partial']);
        if ($partial || !empty($errors)) {
            throw new \moodle_exception('error_template_creation_incomplete', 'local_coursegen', '', self::describe($errors));
        }

        if (!empty($unappliedfiles)) {
            throw new \moodle_exception('error_template_creation_files', 'local_coursegen', '', implode(', ', $unappliedfiles));
        }
    }

    /**
     * The failed activities as a short text.
     *
     * @param array $errors The activity errors of the creation.
     * @return string The names of the activities, or their types when they have no name.
     */
    private static function describe(array $errors): string {
        $names = [];
        foreach ($errors as $error) {
            $title = trim((string) ($error['title'] ?? ''));
            $names[] = $title !== '' ? $title : (string) ($error['resource_type'] ?? '');
        }
        return implode(', ', array_filter($names));
    }
}
