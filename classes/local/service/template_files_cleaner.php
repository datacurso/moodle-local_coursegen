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
 * Asks the service to delete the files of a run that has no more use for them.
 *
 * A failure is only logged: the service sweeps the files of every run after a few hours, so a course that was
 * built or a cancel the teacher made never fails because of the cleanup.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_files_cleaner {
    /**
     * Delete the files of a run.
     *
     * @param string $threadid Thread id of the run, for example "5c1e2a".
     * @param template_ai_api_service|null $api Optional pre-built service client; tests pass a mock.
     * @return bool True when the service deleted them.
     */
    public static function discard(string $threadid, ?template_ai_api_service $api = null): bool {
        if ($api === null) {
            $api = new template_ai_api_service();
        }
        try {
            $api->delete_files($threadid);
        } catch (\Throwable $exception) {
            debugging('The files of the template run could not be deleted: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
        return true;
    }
}
