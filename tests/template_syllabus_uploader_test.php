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
 * The syllabus a teacher attaches to a template generation.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_syllabus_uploader
 */
final class template_syllabus_uploader_test extends \advanced_testcase {
    /**
     * A draft area of the current user holding one file.
     *
     * @param string $content Bytes of the file.
     * @param string $filename Name of the file.
     * @return int Draft item id.
     */
    private function draft_with(string $content, string $filename = 'syllabus.pdf'): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return $draftid;
    }

    /**
     * How many files a draft area still holds.
     *
     * @param int $draftid Draft item id.
     * @return int Count of files.
     */
    private function draft_count(int $draftid): int {
        global $USER;
        $context = \context_user::instance($USER->id);
        $files = get_file_storage()->get_area_files($context->id, 'user', 'draft', $draftid, 'id', false);
        return count($files);
    }

    /**
     * An uploader whose service answers with a result or fails with an error.
     *
     * @param array|\Throwable $outcome What the upload of the syllabus answers or throws.
     * @return array The uploader and the mock of the service.
     */
    private function uploader($outcome = ['status' => 'stored']): array {
        $api = $this->createMock(template_ai_api_service::class);
        if ($outcome instanceof \Throwable) {
            $api->method('upload_syllabus')->willThrowException($outcome);
        } else {
            $api->method('upload_syllabus')->willReturn($outcome);
        }
        return [new template_syllabus_uploader($api), $api];
    }

    /**
     * The error code of what the uploader throws.
     *
     * @param template_syllabus_uploader $uploader The uploader.
     * @param int $draftid Draft item id.
     * @return string Error code, empty when nothing was thrown.
     */
    private function error_code(template_syllabus_uploader $uploader, int $draftid): string {
        try {
            $uploader->send('t-1', $draftid);
        } catch (\moodle_exception $exception) {
            return $exception->errorcode;
        }
        return '';
    }

    /**
     * The syllabus is uploaded to the thread, its name and size come back and the draft area is emptied.
     */
    public function test_a_syllabus_is_uploaded_and_removed_from_the_draft(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with('PDFDATA');
        [$uploader, $api] = $this->uploader();
        $once = $this->once();
        $file = $this->isInstanceOf(\stored_file::class);
        $api->expects($once)->method('upload_syllabus')->with('t-77', $file);

        $sent = $uploader->send('t-77', $draftid);

        $this->assertSame(['filename' => 'syllabus.pdf', 'filesize' => 7], $sent);
        $remaining = $this->draft_count($draftid);
        $this->assertSame(0, $remaining);
    }

    /**
     * The syllabus never goes through the endpoint of the file a question asks for.
     */
    public function test_the_file_of_a_question_is_not_used_for_the_syllabus(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with('PDFDATA');
        [$uploader, $api] = $this->uploader();
        $never = $this->never();
        $api->expects($never)->method('upload_answer_file');
        $never = $this->never();
        $api->expects($never)->method('answer');

        $uploader->send('t-1', $draftid);
    }

    /**
     * A draft area without a file is refused and nothing is sent.
     */
    public function test_a_missing_file_is_refused_and_nothing_is_sent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$uploader, $api] = $this->uploader();
        $never = $this->never();
        $api->expects($never)->method('upload_syllabus');

        $draftid = file_get_unused_draft_itemid();
        $code = $this->error_code($uploader, $draftid);
        $this->assertSame('templatesyllabusmissing', $code);
    }

    /**
     * An empty file is refused before it is sent and its draft area is emptied.
     */
    public function test_an_empty_file_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with('');
        [$uploader, $api] = $this->uploader();
        $never = $this->never();
        $api->expects($never)->method('upload_syllabus');

        $code = $this->error_code($uploader, $draftid);

        $this->assertSame('templatesyllabusempty', $code);
        $remaining = $this->draft_count($draftid);
        $this->assertSame(0, $remaining);
    }

    /**
     * A file over the structural limit is refused before it is sent and its draft area is emptied.
     */
    public function test_a_file_over_the_limit_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $content = str_repeat('a', template_syllabus_uploader::MAX_FILE_BYTES + 1);
        $draftid = $this->draft_with($content);
        [$uploader, $api] = $this->uploader();
        $never = $this->never();
        $api->expects($never)->method('upload_syllabus');

        $code = $this->error_code($uploader, $draftid);

        $this->assertSame('templatesyllabustoolarge', $code);
        $remaining = $this->draft_count($draftid);
        $this->assertSame(0, $remaining);
    }

    /**
     * Each HTTP status the service refuses the syllabus with becomes the message that explains it.
     *
     * @dataProvider status_provider
     * @param int $status HTTP status of the refusal.
     * @param string $expected Error code of the plugin.
     */
    public function test_an_http_refusal_becomes_a_message_of_the_plugin(int $status, string $expected): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with('PDFDATA');
        $error = new \moodle_exception('httperror', 'aiprovider_datacurso', '', $status);
        [$uploader] = $this->uploader($error);

        $code = $this->error_code($uploader, $draftid);

        $this->assertSame($expected, $code);
        $remaining = $this->draft_count($draftid);
        $this->assertSame(0, $remaining);
    }

    /**
     * Statuses and the message each one gets, and the ones that keep the error of the provider.
     *
     * @return array The cases.
     */
    public static function status_provider(): array {
        return [
            'bad request' => [400, 'templatesyllabusrejected'],
            'unknown run' => [404, 'templatesyllabusrun'],
            'request timeout' => [408, 'templatesyllabustimeout'],
            'run already started' => [409, 'templatesyllabusrun'],
            'too large' => [413, 'templatesyllabustoolarge'],
            'unsupported type' => [415, 'templatesyllabusrejected'],
            'cannot be used' => [422, 'templatesyllabusrejected'],
            'render timeout' => [504, 'templatesyllabustimeout'],
            'server error keeps the error' => [500, 'httperror'],
            'bad gateway keeps the error' => [502, 'httperror'],
        ];
    }

    /**
     * An error that is not an HTTP status, such as a blank license key, reaches the caller untouched.
     */
    public function test_a_license_error_reaches_the_caller(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with('PDFDATA');
        $error = new \moodle_exception('invalidlicensekey', 'aiprovider_datacurso');
        [$uploader] = $this->uploader($error);

        $code = $this->error_code($uploader, $draftid);

        $this->assertSame('invalidlicensekey', $code);
        $remaining = $this->draft_count($draftid);
        $this->assertSame(0, $remaining);
    }

    /**
     * Whatever a successful answer of the service holds, the syllabus counts as stored.
     *
     * @dataProvider accepted_answer_provider
     * @param array $answer Decoded answer of the service.
     */
    public function test_any_successful_answer_is_accepted(array $answer): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with('PDFDATA');
        [$uploader] = $this->uploader($answer);

        $code = $this->error_code($uploader, $draftid);

        $this->assertSame('', $code);
        $remaining = $this->draft_count($draftid);
        $this->assertSame(0, $remaining);
    }

    /**
     * Answers the plugin accepts.
     *
     * @return array The cases.
     */
    public static function accepted_answer_provider(): array {
        return [
            'empty answer' => [[]],
            'contract answer' => [['thread_id' => 't-1', 'pages' => 7]],
            'extra keys' => [['thread_id' => 't-1', 'pages' => 7, 'note' => 'x']],
        ];
    }

    /**
     * A name with accents and spaces is returned as it is.
     */
    public function test_a_name_with_accents_is_returned_as_it_is(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $name = 'Sílabo ñandú 2026.pdf';
        $draftid = $this->draft_with('PDFDATA', $name);
        [$uploader] = $this->uploader();

        $sent = $uploader->send('t-1', $draftid);

        $this->assertSame($name, $sent['filename']);
    }
}
