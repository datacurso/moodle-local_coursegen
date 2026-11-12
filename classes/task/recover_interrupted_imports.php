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

namespace local_coursegen\task;

use core\task\scheduled_task;
use local_coursegen\local\service\course_import_service;

/**
 * Scheduled task that rolls back the imports into an existing course whose process died.
 *
 * An import whose process died (time or memory limit, a restart of the service) leaves the
 * original course renamed and hidden and a partial copy. This task undoes it from the
 * bookkeeping of its session; an import still at work holds the lock of its target and is
 * left alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recover_interrupted_imports extends scheduled_task {
    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_recover_interrupted_imports', 'local_coursegen');
    }

    /**
     * Roll back the interrupted imports.
     *
     * @return void
     */
    public function execute(): void {
        $recovered = course_import_service::recover_interrupted_imports();
        mtrace("Interrupted imports rolled back: {$recovered}.");
    }
}
