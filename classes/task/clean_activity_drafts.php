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
use local_coursegen\local\draft_files;
use local_coursegen\local\models\activity_draft;

/**
 * Deletes the drafts of the AI activity editor that nobody has changed for too long.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class clean_activity_drafts extends scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_clean_activity_drafts', 'local_coursegen');
    }

    /**
     * Delete the old drafts with their files, and the files left without a draft. The drafts are found by their indexed time of
     * change.
     */
    public function execute(): void {
        global $DB;

        $old = $DB->get_recordset_select('local_coursegen_activity_draft', 'timemodified < :limit', [
            'limit' => time() - activity_draft::LIFETIME,
        ], '', 'id, userid, courseid, kind');
        foreach ($old as $draft) {
            draft_files::delete_all($draft->userid, $draft->courseid, $draft->kind);
            $DB->delete_records('local_coursegen_activity_draft', ['id' => $draft->id]);
        }
        $old->close();

        draft_files::delete_orphans();
    }
}
