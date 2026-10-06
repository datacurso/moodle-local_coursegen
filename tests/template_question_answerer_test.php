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
 * The answer of a teacher to the question a template run is paused on.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_question_answerer
 */
final class template_question_answerer_test extends \advanced_testcase {
    /**
     * A draft area of the current user holding one file.
     *
     * @param string $content Bytes of the file.
     * @param string $filename Name of the file.
     * @return int Draft item id.
     */
    private function draft_with(string $content, string $filename = 'guide.pdf'): int {
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
        $files = get_file_storage()->get_area_files(\context_user::instance($USER->id)->id, 'user', 'draft', $draftid, 'id', false);
        return count($files);
    }

    /**
     * An answerer whose service records what it receives.
     *
     * @param array $uploadresult What the file upload answers.
     * @return array The answerer and the mock of the service.
     */
    private function answerer(array $uploadresult = ['file_id' => 'f1']): array {
        $api = $this->createMock(template_ai_api_service::class);
        $api->method('upload_answer_file')->willReturn($uploadresult);
        $api->method('answer')->willReturn(['status' => 'stored']);
        return [new template_question_answerer($api), $api];
    }

    /**
     * A file answer uploads the file, answers with its id and empties the draft area.
     */
    public function test_a_file_is_uploaded_answered_and_removed_from_the_draft(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with('PDFDATA');
        [$answerer, $api] = $this->answerer();

        $api->expects($this->once())->method('upload_answer_file')->with('t-1', $this->isInstanceOf(\stored_file::class));
        $api->expects($this->once())->method('answer')->with('t-1', 'c4', ['file_id' => 'f1']);

        $answerer->answer('t-1', 'c4', 'file', $draftid, '', '');

        $this->assertSame(0, $this->draft_count($draftid));
    }

    /**
     * The draft is emptied even when the service refuses the upload.
     */
    public function test_the_draft_is_emptied_when_the_upload_fails(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with('PDFDATA');
        $api = $this->createMock(template_ai_api_service::class);
        $api->method('upload_answer_file')->willThrowException(new \moodle_exception('invalidlicensekey', 'aiprovider_datacurso'));
        $answerer = new template_question_answerer($api);

        try {
            $answerer->answer('t-1', 'c4', 'file', $draftid, '', '');
            $this->fail('The failure of the upload must reach the caller.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('invalidlicensekey', $exception->errorcode);
        }

        $this->assertSame(0, $this->draft_count($draftid));
    }

    /**
     * A file answer without a file is refused and nothing is sent.
     */
    public function test_a_file_answer_without_a_file_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$answerer, $api] = $this->answerer();
        $api->expects($this->never())->method('upload_answer_file');
        $api->expects($this->never())->method('answer');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('templateanswerfilemissing', 'local_coursegen'));
        $answerer->answer('t-1', 'c4', 'file', file_get_unused_draft_itemid(), '', '');
    }

    /**
     * An empty file is refused and its draft is emptied.
     */
    public function test_an_empty_file_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with('');
        [$answerer, $api] = $this->answerer();
        $api->expects($this->never())->method('upload_answer_file');

        try {
            $answerer->answer('t-1', 'c4', 'file', $draftid, '', '');
            $this->fail('An empty file must be refused.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('templateanswerfileempty', $exception->errorcode);
        }

        $this->assertSame(0, $this->draft_count($draftid));
    }

    /**
     * A file over the structural limit is refused and its draft is emptied.
     */
    public function test_a_file_over_the_limit_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with(str_repeat('x', template_question_answerer::MAX_FILE_BYTES + 1));
        [$answerer, $api] = $this->answerer();
        $api->expects($this->never())->method('upload_answer_file');

        try {
            $answerer->answer('t-1', 'c4', 'file', $draftid, '', '');
            $this->fail('A file over the limit must be refused.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('templateanswerfiletoolarge', $exception->errorcode);
        }

        $this->assertSame(0, $this->draft_count($draftid));
    }

    /**
     * A text answer is trimmed and sent as text.
     */
    public function test_a_text_answer_is_trimmed_and_sent(): void {
        $this->resetAfterTest();
        [$answerer, $api] = $this->answerer();
        $api->expects($this->once())->method('answer')->with('t-1', 'c5', ['text' => 'Sí, con acentos y emoji 🙂']);

        $answerer->answer('t-1', 'c5', 'text', 0, "  Sí, con acentos y emoji 🙂 \n", '');
    }

    /**
     * A blank text answer is refused.
     */
    public function test_a_blank_text_answer_is_refused(): void {
        $this->resetAfterTest();
        [$answerer, $api] = $this->answerer();
        $api->expects($this->never())->method('answer');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('templateanswertextmissing', 'local_coursegen'));
        $answerer->answer('t-1', 'c5', 'text', 0, " \t\n", '');
    }

    /**
     * A choice answer is sent as the choice.
     */
    public function test_a_choice_answer_is_sent(): void {
        $this->resetAfterTest();
        [$answerer, $api] = $this->answerer();
        $api->expects($this->once())->method('answer')->with('t-1', 'c6', ['choice' => 'Unit 2']);

        $answerer->answer('t-1', 'c6', 'choice', 0, '', 'Unit 2');
    }

    /**
     * A blank choice is refused.
     */
    public function test_a_blank_choice_is_refused(): void {
        $this->resetAfterTest();
        [$answerer, $api] = $this->answerer();
        $api->expects($this->never())->method('answer');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('templateanswerchoicemissing', 'local_coursegen'));
        $answerer->answer('t-1', 'c6', 'choice', 0, '', '');
    }

    /**
     * An unknown kind of answer is refused.
     */
    public function test_an_unknown_kind_is_refused(): void {
        $this->resetAfterTest();
        [$answerer, $api] = $this->answerer();
        $api->expects($this->never())->method('answer');

        $this->expectException(\coding_exception::class);
        $answerer->answer('t-1', 'c6', 'video', 0, '', '');
    }

    /**
     * Only the draft of the current user is read: the file of another user's draft is not found.
     */
    public function test_the_draft_of_another_user_is_not_read(): void {
        $this->resetAfterTest();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);
        $draftid = $this->draft_with('SECRET');
        $this->setAdminUser();
        [$answerer, $api] = $this->answerer();
        $api->expects($this->never())->method('upload_answer_file');

        $this->expectException(\moodle_exception::class);
        $answerer->answer('t-1', 'c4', 'file', $draftid, '', '');
    }
}
