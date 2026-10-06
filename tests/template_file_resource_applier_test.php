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

use local_coursegen\tests\fixtures\downloading_template_api;

/**
 * Giving the copies of the template resources the files the run attached.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_file_resource_applier
 */
final class template_file_resource_applier_test extends \advanced_testcase {
    /**
     * Load the client double the tests use.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/fixtures/downloading_template_api.php');
    }

    /**
     * The files of a draft area of the current user.
     *
     * @param int $draftid Draft item id.
     * @return int Count of files.
     */
    private function draft_count(int $draftid): int {
        global $USER;
        $context = \context_user::instance($USER->id);
        return count(get_file_storage()->get_area_files($context->id, 'user', 'draft', $draftid, 'id', false));
    }

    /**
     * The file lands in the copy of the resource and the draft area is emptied.
     */
    public function test_the_file_lands_in_the_copy_and_the_draft_is_emptied(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $copy = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $api = new downloading_template_api();
        $applier = new template_file_resource_applier($api);
        $selected = [['uid' => '11340', 'cmid' => 11340, 'file' => ['file_id' => 'f1', 'filename' => 'guide.pdf']]];

        $failures = $applier->apply('t-9', $selected, [11340 => (int) $copy->cmid]);

        $this->assertSame([], $failures);
        $context = \context_module::instance($copy->cmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_resource', 'content', 0, 'id', false);
        $this->assertCount(1, $files);
        $this->assertSame('CONTENT OF f1', reset($files)->get_content());
        $this->assertSame(0, $this->draft_count($api->drafts[0]));
    }

    /**
     * A resource that was not copied is reported by the name of its file, and nothing is downloaded.
     */
    public function test_a_resource_without_a_copy_is_reported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $api = $this->createMock(template_ai_api_service::class);
        $api->expects($this->never())->method('download_generated_file');
        $applier = new template_file_resource_applier($api);
        $selected = [['uid' => '11340', 'cmid' => 11340, 'file' => ['file_id' => 'f1', 'filename' => 'guide.pdf']]];

        $this->assertSame(['guide.pdf'], $applier->apply('t-9', $selected, []));
    }

    /**
     * A download that fails is reported, the draft is emptied and the next file is still applied.
     */
    public function test_a_failed_download_is_reported_and_the_next_file_is_applied(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $second = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $applier = new template_file_resource_applier(new downloading_template_api('bad'));
        $selected = [
            ['uid' => '1', 'cmid' => 1, 'file' => ['file_id' => 'bad', 'filename' => 'bad.pdf']],
            ['uid' => '2', 'cmid' => 2, 'file' => ['file_id' => 'ok', 'filename' => 'ok.pdf']],
        ];

        $failures = $applier->apply('t-9', $selected, [1 => (int) $first->cmid, 2 => (int) $second->cmid]);

        $this->assertSame(['bad.pdf'], $failures);
        $this->assertDebuggingCalled(null, DEBUG_DEVELOPER);
        $context = \context_module::instance($second->cmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_resource', 'content', 0, 'id', false);
        $this->assertSame('ok.pdf', reset($files)->get_filename());
    }

    /**
     * A download that returns no file is reported.
     */
    public function test_a_download_that_returns_no_file_is_reported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $copy = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $applier = new template_file_resource_applier(new downloading_template_api(null, true));
        $selected = [['uid' => '1', 'cmid' => 1, 'file' => ['file_id' => 'f', 'filename' => 'x.pdf']]];

        $this->assertSame(['x.pdf'], $applier->apply('t-9', $selected, [1 => (int) $copy->cmid]));
    }

    /**
     * Nothing selected does nothing.
     */
    public function test_nothing_selected_does_nothing(): void {
        $this->resetAfterTest();
        $api = $this->createMock(template_ai_api_service::class);
        $api->expects($this->never())->method('download_generated_file');

        $this->assertSame([], (new template_file_resource_applier($api))->apply('t-9', [], [1 => 2]));
    }
}
