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

use local_coursegen\local\models\module_job;

/**
 * Service class for handling module generation jobs using the persistent model.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_job_service {
    /**
     * Create and persist a module job record.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param string $jobid External job/thread identifier.
     * @param int $generateimages Whether images should be generated (0/1).
     * @param string|null $contexttype Context type used for the request.
     * @param string|null $systeminstructionname System instruction name, if any.
     * @param int|null $sectionnum Target section number.
     * @param int|null $beforemod Optional cm id to insert before.
     * @param string|null $status Optional initial status.
     * @return module_job
     */
    public static function create_job(
        int $courseid,
        int $userid,
        string $jobid,
        int $generateimages,
        ?string $contexttype,
        ?string $systeminstructionname,
        ?int $sectionnum,
        ?int $beforemod,
        ?string $status = null
    ): module_job {
        $now = time();

        $record = (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'job_id' => $jobid,
            'status' => $status,
            'generate_images' => $generateimages,
            'context_type' => $contexttype,
            'system_instruction_name' => $systeminstructionname,
            'sectionnum' => $sectionnum,
            'beforemod' => $beforemod,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $job = new module_job(0, $record);
        $job->create();

        return $job;
    }

    /**
     * Get a module job by external job id for a given user and course.
     *
     * @param string $jobid External job/thread identifier.
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return module_job
     */
    public static function get_user_job(string $jobid, int $courseid, int $userid): module_job {
        $job = module_job::get_record([
            'job_id' => $jobid,
            'courseid' => $courseid,
            'userid' => $userid,
        ]);

        if (!$job) {
            throw new \moodle_exception('error_no_module_job_found', 'local_coursegen');
        }

        return $job;
    }

    /**
     * Update job status.
     *
     * @param int $id Module job record id.
     * @param string|null $status Status string.
     * @return void
     */
    public static function update_status(int $id, ?string $status): void {
        $job = new module_job($id);
        $job->set('status', $status);
        $job->set('timemodified', time());
        $job->update();
    }
}
