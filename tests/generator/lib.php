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

use core\context\system;
use core\exception\coding_exception;
use core\persistent;
use local_coursegen\local\models\course_context;
use local_coursegen\local\models\course_session;
use local_coursegen\local\models\module_job;

/**
 * Data generator for local_coursegen.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_coursegen_generator extends component_generator_base {
    /**
     * Create an institutional guideline (system instruction) row.
     *
     * The course AI creation page lists every non-deleted row of
     * local_coursegen_system_instruction as a guideline, so seeding this table
     * is enough to exercise the guideline UI without the external AI service.
     *
     * @param array $record Column overrides: name (required), content (defaults to ''), deleted (defaults to 0),
     *     usermodified (defaults to the current user, or the site admin when nobody is logged in),
     *     timecreated and timemodified (default to now).
     * @return stdClass The inserted record.
     */
    public function create_system_instruction(array $record): stdClass {
        global $DB, $USER;

        if (empty($record['name'])) {
            throw new coding_exception('A system instruction requires a name.');
        }

        $now = time();
        $instruction = (object) [
            'name' => $record['name'],
            'content' => $record['content'] ?? '',
            'deleted' => (int) ($record['deleted'] ?? 0),
            'timecreated' => (int) ($record['timecreated'] ?? $now),
            'timemodified' => (int) ($record['timemodified'] ?? $now),
            'usermodified' => (int) ($record['usermodified'] ?? ($USER->id ?: get_admin()->id)),
        ];
        $instruction->id = $DB->insert_record('local_coursegen_system_instruction', $instruction);

        return $instruction;
    }

    /**
     * Create a course planning session row, as course_planning_service::start_course_planning() does.
     *
     * @param array $record Column overrides: userid (defaults to the current user), courseid (defaults to null),
     *     session_id (defaults to a random 'sess_' identifier), status (course_session::STATUS_*, defaults to
     *     pending), coursedata (JSON string or array; defaults to the planning form data of a custom prompt in
     *     English without images, subsections or system instruction), timecreated and timemodified (default
     *     to now).
     * @return course_session The stored session.
     */
    public function create_course_session(array $record = []): course_session {
        global $USER;

        $userid = (int) ($record['userid'] ?? $USER->id);
        if ($userid <= 0) {
            throw new coding_exception('A course session requires a userid or a logged-in user.');
        }

        $coursedata = $record['coursedata'] ?? [
            'local_coursegen_lang' => 'en',
            'local_coursegen_generate_images' => 0,
            'local_coursegen_generate_subsections' => 0,
            'local_coursegen_context_type' => 'customprompt',
            'local_coursegen_custom_prompt' => 'Create a course about testing',
            'local_coursegen_use_system_instruction' => 0,
            'local_coursegen_select_system_instruction' => 0,
        ];
        if (is_array($coursedata)) {
            $coursedata = json_encode($coursedata);
        }

        $session = new course_session(0, (object) [
            'courseid' => isset($record['courseid']) ? (int) $record['courseid'] : null,
            'userid' => $userid,
            'session_id' => (string) ($record['session_id'] ?? 'sess_' . bin2hex(random_bytes(8))),
            'status' => (int) ($record['status'] ?? course_session::STATUS_PENDING),
            'coursedata' => $coursedata,
        ]);
        $session->create();

        return $this->apply_timestamps(course_session::class, $session, $record);
    }

    /**
     * Create a module generation job row, as module_job_service::create_job() does.
     *
     * @param array $record Column overrides: courseid (required), userid (defaults to the current user), job_id
     *     (defaults to a random 'job_' identifier), status (defaults to 'execution_started', the first status the
     *     AI service reports; pass null for a job without status), generate_images (defaults to 0), context_type,
     *     system_instruction_name, sectionnum and beforemod (default to null), timecreated and timemodified
     *     (default to now).
     * @return module_job The stored job.
     */
    public function create_module_job(array $record = []): module_job {
        global $USER;

        if (empty($record['courseid'])) {
            throw new coding_exception('A module job requires a courseid.');
        }

        $userid = (int) ($record['userid'] ?? $USER->id);
        if ($userid <= 0) {
            throw new coding_exception('A module job requires a userid or a logged-in user.');
        }

        $job = new module_job(0, (object) [
            'courseid' => (int) $record['courseid'],
            'userid' => $userid,
            'job_id' => (string) ($record['job_id'] ?? 'job_' . bin2hex(random_bytes(8))),
            'status' => array_key_exists('status', $record) ? $record['status'] : 'execution_started',
            'generate_images' => (int) ($record['generate_images'] ?? 0),
            'context_type' => $record['context_type'] ?? null,
            'system_instruction_name' => $record['system_instruction_name'] ?? null,
            'sectionnum' => isset($record['sectionnum']) ? (int) $record['sectionnum'] : null,
            'beforemod' => isset($record['beforemod']) ? (int) $record['beforemod'] : null,
        ]);
        $job->create();

        return $this->apply_timestamps(module_job::class, $job, $record);
    }

    /**
     * Create the AI context row of a course.
     *
     * @param array $record Column overrides: courseid (required), context_type (syllabus|prompt, defaults to
     *     syllabus), system_instruction_id, lang and prompt_text (default to null), usermodified (defaults to
     *     the current user), timecreated and timemodified (default to now).
     * @return course_context The stored context row.
     */
    public function create_course_context(array $record = []): course_context {
        global $DB, $USER;

        if (empty($record['courseid'])) {
            throw new coding_exception('A course context requires a courseid.');
        }

        $contexttype = $record['context_type'] ?? course_context::CONTEXT_TYPE_SYLLABUS;
        $allowed = [course_context::CONTEXT_TYPE_SYLLABUS, course_context::CONTEXT_TYPE_CUSTOM_PROMPT];
        if (!in_array($contexttype, $allowed, true)) {
            throw new coding_exception('A course context type must be one of: ' . implode(', ', $allowed) . '.');
        }

        $context = new course_context(0, (object) [
            'courseid' => (int) $record['courseid'],
            'context_type' => $contexttype,
            'system_instruction_id' => isset($record['system_instruction_id']) ? (int) $record['system_instruction_id'] : null,
            'lang' => $record['lang'] ?? null,
            'prompt_text' => $record['prompt_text'] ?? null,
        ]);
        $context->create();

        // The persistent stamps usermodified with the current user; honour an explicit owner.
        if (isset($record['usermodified']) && (int) $record['usermodified'] !== (int) $USER->id) {
            $DB->set_field(course_context::TABLE, 'usermodified', (int) $record['usermodified'], ['id' => $context->get('id')]);
            $context = new course_context($context->get('id'));
        }

        return $this->apply_timestamps(course_context::class, $context, $record);
    }

    /**
     * Store a syllabus file for a planning session, where courseai_syllabus_upload saves it.
     *
     * Syllabus files live in the system context, in the local_coursegen/syllabus
     * file area, with the planning session id as item id.
     *
     * @param course_session $session Planning session the file belongs to.
     * @param string $filename File name.
     * @param string $content File content.
     * @return stored_file The stored file.
     */
    public function create_syllabus_file(
        course_session $session,
        string $filename = 'syllabus.pdf',
        string $content = '%PDF-1.4 test'
    ): stored_file {
        $sessionid = (int) $session->get('id');
        if ($sessionid <= 0) {
            throw new coding_exception('A syllabus file requires a stored course session.');
        }

        return get_file_storage()->create_file_from_string((object) [
            'contextid' => system::instance()->id,
            'component' => 'local_coursegen',
            'filearea' => course_context::CONTEXT_TYPE_SYLLABUS,
            'itemid' => $sessionid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Apply explicit timecreated/timemodified overrides, which persistent::create() always stamps with now.
     *
     * @param string $class Persistent class name.
     * @param persistent $persistent Freshly created persistent.
     * @param array $record Generator record with optional timecreated/timemodified keys.
     * @return persistent The persistent re-read from the database when a timestamp was overridden.
     */
    private function apply_timestamps(string $class, persistent $persistent, array $record): persistent {
        global $DB;

        $overrides = [];
        foreach (['timecreated', 'timemodified'] as $field) {
            if (isset($record[$field])) {
                $overrides[$field] = (int) $record[$field];
            }
        }
        if (empty($overrides)) {
            return $persistent;
        }

        $overrides['id'] = (int) $persistent->get('id');
        $DB->update_record($class::TABLE, (object) $overrides);

        return new $class($overrides['id']);
    }
}
