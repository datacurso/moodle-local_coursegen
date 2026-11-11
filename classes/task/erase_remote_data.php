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

use core\task\adhoc_task;
use local_coursegen\local\service\remote_data_service;

/**
 * Deletes at the Datacurso course service the threads of a person or of a course that Moodle erased.
 *
 * Queued by the deletion (Privacy API, deleted user, deleted course) before the local rows that
 * hold the thread ids go, so a deletion never depends on the service being reachable at that
 * moment: a failed call throws and Moodle runs the task again later, up to its attempt limit. If
 * every attempt fails, the service deletes the threads anyway when their retention expires.
 *
 * Custom data: userid (int|null) and threadids (string[]). Mindfree, LCG-PRIV-001.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class erase_remote_data extends adhoc_task {
    /**
     * Queue the deletion of a person's threads, the listed threads, or both.
     *
     * The tenant is resolved when the task is queued, while the data still says whom it belongs
     * to: the task runs from the cron, outside any tenant.
     *
     * @param int|null $userid
     * @param string[] $threadids
     * @param int|null $tenantid Tenant whose course service keeps the threads, or null for the current user's tenant.
     * @return void
     */
    public static function queue(?int $userid, array $threadids, ?int $tenantid = null): void {
        if ($userid === null && $threadids === []) {
            return;
        }
        $tenantid = $tenantid ?? \local_coursegen\local\tenancy::get_tenant_id();
        $task = new self();
        $task->set_custom_data([
            'userid' => $userid,
            'threadids' => array_values($threadids),
            'tenantid' => $tenantid,
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_erase_remote_data', 'local_coursegen');
    }

    /**
     * Ask the service to delete the threads.
     *
     * @return void
     */
    public function execute(): void {
        $data = (array)$this->get_custom_data();
        $userid = isset($data['userid']) ? (int)$data['userid'] : null;
        $threadids = array_map('strval', (array)($data['threadids'] ?? []));
        $tenantid = isset($data['tenantid']) ? (int)$data['tenantid'] : null;
        $deleted = (new remote_data_service(null, $tenantid))->erase($userid, $threadids);
        mtrace("Threads deleted at the course service: {$deleted}.");
    }
}
