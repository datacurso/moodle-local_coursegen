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

namespace local_coursegen\local\space;

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;

/**
 * Tests for space_files_request.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\space\space_files_request
 */
final class space_files_request_test extends \advanced_testcase {
    /**
     * A template over a course with one space resource.
     *
     * @param bool $required
     * @return array{0: int, 1: int} The template id and the cmid of the space.
     */
    private function template_with_space(bool $required): array {
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id, 'name' => 'Guide']);
        $template = new template(0, (object) ['name' => 'T', 'courseid' => $course->id]);
        $template->create();
        (new template_activity(0, (object) [
            'templateid' => $template->get('id'), 'sectionid' => 0, 'cmid' => $resource->cmid,
            'action' => 'space', 'spacerequired' => (int) $required,
        ]))->create();
        return [(int) $template->get('id'), (int) $resource->cmid];
    }

    /**
     * A draft area holding one file.
     *
     * @param int $userid
     * @return int
     */
    private function draft(int $userid): int {
        $draftid = file_get_unused_draft_itemid();
        $context = \context_user::instance($userid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid,
            'filepath' => '/', 'filename' => 'mine.pdf',
        ], 'MINE');
        return $draftid;
    }

    /**
     * A file for a space of the template is accepted and kept for the session.
     */
    public function test_a_file_for_a_space_is_accepted_and_stored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $userid = (int) get_admin()->id;
        [$templateid, $cmid] = $this->template_with_space(true);
        $draftid = $this->draft($userid);

        $drafts = space_files_request::validated($templateid, $userid, [['cmid' => $cmid, 'draftitemid' => $draftid]]);
        space_files_request::store($drafts, $userid, 31);

        $this->assertSame([$cmid => $draftid], $drafts);
        $this->assertSame([$cmid], array_keys(space_file_storage::files_of_session($userid, 31)));
    }

    /**
     * An optional space may be left empty.
     */
    public function test_an_optional_space_may_have_no_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$templateid] = $this->template_with_space(false);

        $drafts = space_files_request::validated($templateid, (int) get_admin()->id, []);

        $this->assertSame([], $drafts);
    }

    /**
     * A required space with no file stops the generation and names the file.
     */
    public function test_a_required_space_without_a_file_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$templateid] = $this->template_with_space(true);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Guide');
        space_files_request::validated($templateid, (int) get_admin()->id, []);
    }

    /**
     * A file for a cmid that is not a space of the template is refused.
     */
    public function test_a_file_for_something_that_is_not_a_space_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $userid = (int) get_admin()->id;
        [$templateid] = $this->template_with_space(false);

        $this->expectException(\invalid_parameter_exception::class);
        space_files_request::validated($templateid, $userid, [['cmid' => 99999, 'draftitemid' => $this->draft($userid)]]);
    }

    /**
     * A draft area without a file is refused.
     */
    public function test_an_empty_draft_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$templateid, $cmid] = $this->template_with_space(false);

        $this->expectException(\moodle_exception::class);
        space_files_request::validated($templateid, (int) get_admin()->id, [['cmid' => $cmid, 'draftitemid' => 987654]]);
    }
}
