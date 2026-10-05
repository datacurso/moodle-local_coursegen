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

namespace local_coursegen\local\template;

use core\lock\lock;
use core\lock\lock_config;
use moodle_exception;
use stdClass;

/**
 * Saves, loads and deletes the templates.
 *
 * A save checks everything first and then replaces the activities of the template in one transaction, so a
 * template is never left half written. It does not check capabilities: the callers do.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_service {
    /** @var int Seconds a save waits for another save of the same template. */
    private const LOCK_TIMEOUT = 10;

    /** @var template_repository Where the rows live. */
    private template_repository $repository;

    /**
     * Constructor.
     *
     * @param template_repository|null $repository Repository to use, or null for the default one.
     */
    public function __construct(?template_repository $repository = null) {
        if ($repository === null) {
            $repository = new template_repository();
        }
        $this->repository = $repository;
    }

    /**
     * Create a template, or replace an existing one.
     *
     * @param int $templateid Id of the template to replace, or 0 to create one.
     * @param int $courseid Course whose structure is the template, for example 42.
     * @param string $name Name of the template, for example "Digital marketing".
     * @param string|null $description Optional description.
     * @param array[] $items One entry per activity with cmid, action and an optional instruction.
     * @param int $userid User who saves.
     * @return int Id of the template.
     * @throws moodle_exception When something is not valid, and nothing is saved.
     */
    public function save(
        int $templateid,
        int $courseid,
        string $name,
        ?string $description,
        array $items,
        int $userid
    ): int {
        $cleanname = template_input::clean_name($name);
        $cleandescription = plain_text::normalize($description);
        $this->assert_course_usable($courseid);
        $this->assert_template_exists($templateid);
        $rows = template_input::build_rows($courseid, $items);

        $lock = $this->acquire_lock($templateid);
        try {
            return $this->persist($templateid, $courseid, $cleanname, $cleandescription, $rows, $userid);
        } finally {
            $this->release_lock($lock);
        }
    }

    /**
     * Delete a template and what is saved for its activities.
     *
     * @param int $templateid Id of the template, for example 3.
     * @throws moodle_exception When the template does not exist.
     */
    public function delete(int $templateid): void {
        global $DB;

        $this->assert_template_exists($templateid);
        if ($templateid === 0) {
            throw new moodle_exception('error_template_not_found', 'local_coursegen');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $this->repository->delete($templateid);
            $transaction->allow_commit();
        } catch (\Throwable $error) {
            $transaction->rollback($error);
        }
    }

    /**
     * What the editor draws: the sections and activities of the course with what is saved for each activity.
     *
     * @param int $templateid Id of the template being edited, or 0 for a new one.
     * @param int $courseid Course chosen in the editor, or 0 to use the one of the template.
     * @return array With template, courseid, courseusable, sections and missing. The activities of a section carry
     *               action and instruction, and missing lists what is saved for activities that no longer exist.
     * @throws moodle_exception When the template does not exist.
     */
    public function load_for_edit(int $templateid, int $courseid): array {
        $this->assert_template_exists($templateid);
        $template = null;
        if ($templateid > 0) {
            $template = $this->repository->find($templateid);
        }

        $effectivecourseid = $courseid;
        if ($courseid === 0 && $template !== null) {
            $effectivecourseid = (int) $template->courseid;
        }

        $loaded = [
            'template' => $template,
            'courseid' => $effectivecourseid,
            'courseusable' => false,
            'sections' => [],
            'missing' => [],
        ];
        if (!course_structure::is_usable_course($effectivecourseid)) {
            return $loaded;
        }

        $sections = course_structure::for_course($effectivecourseid);
        $saved = $this->saved_items($template, $effectivecourseid);
        $loaded['courseusable'] = true;
        $loaded['sections'] = template_editor_data::apply_saved_items($sections, $saved);
        $loaded['missing'] = template_editor_data::missing_items($sections, $saved);

        return $loaded;
    }

    /**
     * Check that a course can be the base of a template.
     *
     * @param int $courseid Course id, for example 42.
     * @throws moodle_exception When it does not exist or is the front page.
     */
    private function assert_course_usable(int $courseid): void {
        if (!course_structure::is_usable_course($courseid)) {
            throw new moodle_exception('error_template_course_invalid', 'local_coursegen');
        }
    }

    /**
     * Check that the template to replace exists. A new template (0) always passes.
     *
     * @param int $templateid Template id, for example 3.
     * @throws moodle_exception When the template does not exist.
     */
    private function assert_template_exists(int $templateid): void {
        if ($templateid === 0) {
            return;
        }

        if ($templateid > 0 && $this->repository->find($templateid) !== null) {
            return;
        }

        throw new moodle_exception('error_template_not_found', 'local_coursegen');
    }

    /**
     * Take the lock that keeps two saves of the same template from running at once.
     *
     * @param int $templateid Template id, or 0 for a new template, which needs no lock.
     * @return lock|null
     * @throws moodle_exception When another save does not finish in time.
     */
    private function acquire_lock(int $templateid): ?lock {
        if ($templateid === 0) {
            return null;
        }

        $factory = lock_config::get_lock_factory('local_coursegen_template');
        $lock = $factory->get_lock('template_' . $templateid, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new moodle_exception('error_template_busy', 'local_coursegen');
        }

        return $lock;
    }

    /**
     * Release the lock of a save.
     *
     * @param lock|null $lock Lock returned by acquire_lock.
     */
    private function release_lock(?lock $lock): void {
        if ($lock === null) {
            return;
        }

        $lock->release();
    }

    /**
     * Write the template and its activities in one transaction.
     *
     * @param int $templateid Id of the template to replace, or 0 to create one.
     * @param int $courseid Course of the template.
     * @param string $name Clean name.
     * @param string|null $description Clean description.
     * @param stdClass[] $rows Rows of the activities, without template id.
     * @param int $userid User who saves.
     * @return int Id of the template.
     */
    private function persist(
        int $templateid,
        int $courseid,
        string $name,
        ?string $description,
        array $rows,
        int $userid
    ): int {
        global $DB;

        $savedid = 0;
        $transaction = $DB->start_delegated_transaction();
        try {
            $savedid = $this->store_template($templateid, $courseid, $name, $description, $userid);
            $itemrows = $this->with_template_id($rows, $savedid);
            $this->repository->replace_items($savedid, $itemrows);
            $transaction->allow_commit();
        } catch (\Throwable $error) {
            $transaction->rollback($error);
        }

        return $savedid;
    }

    /**
     * Insert or update the row of the template.
     *
     * @param int $templateid Id of the template to replace, or 0 to create one.
     * @param int $courseid Course of the template.
     * @param string $name Clean name.
     * @param string|null $description Clean description.
     * @param int $userid User who saves.
     * @return int Id of the template.
     */
    private function store_template(int $templateid, int $courseid, string $name, ?string $description, int $userid): int {
        $now = time();
        $record = new stdClass();
        $record->courseid = $courseid;
        $record->name = $name;
        $record->description = $description;
        $record->timemodified = $now;
        $record->usermodified = $userid;
        if ($templateid === 0) {
            $record->timecreated = $now;
            return $this->repository->insert_template($record);
        }

        $record->id = $templateid;
        $this->repository->update_template($record);

        return $templateid;
    }

    /**
     * Give every row the id of its template.
     *
     * @param stdClass[] $rows Rows without template id.
     * @param int $templateid Id of the template.
     * @return stdClass[]
     */
    private function with_template_id(array $rows, int $templateid): array {
        $completed = [];
        foreach ($rows as $row) {
            $row->templateid = $templateid;
            $completed[] = $row;
        }

        return $completed;
    }

    /**
     * What is saved for the activities of a template, when it was made from the same course.
     *
     * @param stdClass|null $template Row of the template, or null for a new one.
     * @param int $courseid Course shown in the editor.
     * @return stdClass[] Rows keyed by course module id.
     */
    private function saved_items(?stdClass $template, int $courseid): array {
        if ($template === null) {
            return [];
        }

        if ((int) $template->courseid !== $courseid) {
            return [];
        }

        return $this->repository->items_of((int) $template->id);
    }
}
