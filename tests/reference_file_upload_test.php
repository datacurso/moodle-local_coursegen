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

namespace local_coursegen;

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\reference\reference_file_storage;
use local_coursegen\local\reference\reference_file_upload;

/**
 * Taking the file a teacher uploads for a slot: what is kept and what is refused.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_file_upload
 */
final class reference_file_upload_test extends \advanced_testcase {
    /**
     * A template with a page that has an image place and a document place.
     *
     * @return array{0: int, 1: string, 2: string} The template, the document slot and the image slot.
     */
    private function template_with_two_places(): array {
        $course = $this->getDataGenerator()->create_course();
        $content = '[[coursegen:reference: Guide]]<iframe src="@@PLUGINFILE@@/guide.pdf"></iframe>'
            . '[[coursegen:reference: Cover]]<img src="@@PLUGINFILE@@/cover.png">';
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);
        $template = new template(0, (object) ['name' => 'Test template', 'courseid' => $course->id]);
        $template->create();
        $activity = new template_activity(0, (object) [
            'templateid' => $template->get('id'),
            'sectionid' => 0,
            'cmid' => $page->cmid,
            'action' => template_activity::ACTION_TEMPLATE,
        ]);
        $activity->create();
        return [(int) $template->get('id'), $page->cmid . '.1', $page->cmid . '.2'];
    }

    /**
     * An upload as PHP hands it over.
     *
     * @param string $name The name the browser sent.
     * @param string $content
     * @param int $error
     * @return array
     */
    private function upload(string $name, string $content, int $error = UPLOAD_ERR_OK): array {
        $directory = make_request_directory();
        $path = $directory . '/phpupload';
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($content), 'error' => $error];
    }

    /**
     * An acceptable file is kept in its slot under its own name.
     */
    public function test_an_acceptable_file_is_kept_in_its_slot(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();

        $upload = $this->upload('Teacher guide.pdf', 'PDFDATA');
        $name = reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);
        $file = reference_file_storage::staged_file((int) $user->id, $templateid, $documentslot);

        $this->assertSame('Teacher guide.pdf', $name);
        $this->assertSame('PDFDATA', $file->get_content());
    }

    /**
     * A second upload to the same slot replaces the first.
     */
    public function test_a_second_upload_replaces_the_first(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();
        $upload = $this->upload('one.pdf', 'ONE');
        reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);

        $upload = $this->upload('two.pdf', 'TWO');
        reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);
        $file = reference_file_storage::staged_file((int) $user->id, $templateid, $documentslot);

        $this->assertSame('two.pdf', $file->get_filename());
    }

    /**
     * A slot the template does not have is refused.
     */
    public function test_an_unknown_slot_is_refused(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid] = $this->template_with_two_places();

        $this->expectException(\moodle_exception::class);
        $message = get_string('referenceslotunknown', 'local_coursegen');
        $this->expectExceptionMessage($message);

        $upload = $this->upload('a.pdf', 'A');
        reference_file_upload::accept((int) $user->id, $templateid, '999999.1', $upload);
    }

    /**
     * A file the place does not accept is refused and nothing is kept.
     */
    public function test_a_file_of_another_kind_is_refused(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, , $imageslot] = $this->template_with_two_places();

        try {
            $upload = $this->upload('guide.pdf', 'PDFDATA');
            reference_file_upload::accept((int) $user->id, $templateid, $imageslot, $upload);
            $this->fail('A pdf was accepted by an image place.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('referencefiletype', $exception->errorcode);
        }
        $file = reference_file_storage::staged_file((int) $user->id, $templateid, $imageslot);
        $this->assertNull($file);
    }

    /**
     * A page the site would serve as HTML is refused even in a document place.
     */
    public function test_an_html_file_is_refused(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();

        $this->expectException(\moodle_exception::class);
        $message = get_string('referencefiletype', 'local_coursegen');
        $this->expectExceptionMessage($message);

        $upload = $this->upload('page.html', '<script>1</script>');
        reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);
    }

    /**
     * An empty file is refused.
     */
    public function test_an_empty_file_is_refused(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();

        $this->expectException(\moodle_exception::class);
        $message = get_string('referencefileempty', 'local_coursegen');
        $this->expectExceptionMessage($message);

        $upload = $this->upload('a.pdf', '');
        reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);
    }

    /**
     * A file larger than the site limit is refused, and the limit is named.
     */
    public function test_a_file_over_the_site_limit_is_refused(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('maxbytes', 100);
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();

        try {
            $upload = $this->upload('a.pdf', str_repeat('A', 500));
            reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);
            $this->fail('A file over the limit was accepted.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('referencefiletoolarge', $exception->errorcode);
        }
        $file = reference_file_storage::staged_file((int) $user->id, $templateid, $documentslot);
        $this->assertNull($file);
    }

    /**
     * An upload PHP cut short for its size is reported as too large.
     */
    public function test_an_upload_cut_by_php_is_reported_as_too_large(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();

        try {
            $upload = $this->upload('a.pdf', 'A', UPLOAD_ERR_INI_SIZE);
            reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);
            $this->fail('A cut upload was accepted.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('referencefiletoolarge', $exception->errorcode);
        }
    }

    /**
     * Any other upload failure is reported with its code.
     */
    public function test_any_other_upload_failure_is_reported(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();

        try {
            $upload = $this->upload('a.pdf', 'A', UPLOAD_ERR_PARTIAL);
            reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);
            $this->fail('A partial upload was accepted.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('referencefileupload', $exception->errorcode);
        }
    }

    /**
     * A name with a path in it is kept without the path.
     */
    public function test_a_name_with_a_path_is_kept_without_it(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();

        $upload = $this->upload('../../etc/guide.pdf', 'PDFDATA');
        $name = reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);

        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringEndsWith('guide.pdf', $name);
    }

    /**
     * A name that is nothing once made safe is refused.
     */
    public function test_a_name_with_nothing_left_is_refused(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();

        $this->expectException(\moodle_exception::class);

        $upload = $this->upload('', 'PDFDATA');
        reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);
    }

    /**
     * Discarding empties a slot that had a file.
     */
    public function test_discarding_empties_the_slot(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid, $documentslot] = $this->template_with_two_places();
        $upload = $this->upload('one.pdf', 'ONE');
        reference_file_upload::accept((int) $user->id, $templateid, $documentslot, $upload);

        reference_file_upload::discard((int) $user->id, $templateid, $documentslot);
        $file = reference_file_storage::staged_file((int) $user->id, $templateid, $documentslot);

        $this->assertNull($file);
    }

    /**
     * Discarding a slot the template does not have is refused.
     */
    public function test_discarding_an_unknown_slot_is_refused(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        [$templateid] = $this->template_with_two_places();

        $this->expectException(\moodle_exception::class);

        reference_file_upload::discard((int) $user->id, $templateid, '999999.1');
    }
}
