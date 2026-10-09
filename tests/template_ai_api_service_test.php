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

use aiprovider_datacurso\httpclient\ai_course_api;

/**
 * The calls to the template agent endpoints of the service.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_ai_api_service
 */
final class template_ai_api_service_test extends \advanced_testcase {
    /**
     * A service over a mocked client.
     *
     * @param ai_course_api $client The mock.
     * @return template_ai_api_service The service.
     */
    private function service(ai_course_api $client): template_ai_api_service {
        return new template_ai_api_service($client);
    }

    /**
     * The run is created at the init endpoint of the template agent and its thread id is returned.
     */
    public function test_init_posts_the_payload_and_returns_the_thread_id(): void {
        $client = $this->createMock(ai_course_api::class);
        $client->expects($this->once())->method('request')
            ->with('POST', '/template-agent/init', ['contract_version' => 2])
            ->willReturn(['thread_id' => 't-9']);

        $this->assertSame('t-9', $this->service($client)->init(['contract_version' => 2]));
    }

    /**
     * An init that answers no thread id fails.
     */
    public function test_init_without_a_thread_id_fails(): void {
        $client = $this->createMock(ai_course_api::class);
        $client->method('request')->willReturn(['thread_id' => '']);

        $this->expectException(\moodle_exception::class);
        $this->service($client)->init([]);
    }

    /**
     * An answer carries the thread, the call and exactly the answer given.
     */
    public function test_answer_posts_the_thread_the_call_and_the_answer(): void {
        $client = $this->createMock(ai_course_api::class);
        $client->expects($this->once())->method('request')
            ->with('POST', '/template-agent/feedback', ['thread_id' => 't-9', 'call_id' => 'c4', 'answer' => ['file_id' => 'f1']])
            ->willReturn(['status' => 'stored']);

        $this->assertSame(['status' => 'stored'], $this->service($client)->answer('t-9', 'c4', ['file_id' => 'f1']));
    }

    /**
     * A file is uploaded to the file endpoint with the thread id.
     */
    public function test_the_answer_file_is_uploaded_with_the_thread_id(): void {
        $this->resetAfterTest();
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_coursegen', 'filearea' => 'x', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'guide.pdf',
        ], 'PDF');
        $client = $this->createMock(ai_course_api::class);
        $client->expects($this->once())->method('upload_file')
            ->with('/template-agent/file/upload', $file, ['thread_id' => 't-9'])
            ->willReturn(['file_id' => 'f1']);

        $this->assertSame(['file_id' => 'f1'], $this->service($client)->upload_answer_file('t-9', $file));
    }

    /**
     * A stored file of the site that stands for the syllabus of a teacher.
     *
     * @return \stored_file The file.
     */
    private function syllabus_file(): \stored_file {
        $context = \context_system::instance();
        $record = [
            'contextid' => $context->id, 'component' => 'local_coursegen', 'filearea' => 'x', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'syllabus.pdf',
        ];
        $storage = get_file_storage();
        return $storage->create_file_from_string($record, 'PDF');
    }

    /**
     * The syllabus is uploaded to its own endpoint with the thread id, apart from the file of a question.
     */
    public function test_the_syllabus_is_uploaded_to_its_own_endpoint_with_the_thread_id(): void {
        $this->resetAfterTest();
        $file = $this->syllabus_file();
        $client = $this->createMock(ai_course_api::class);
        $once = $this->once();
        $client->expects($once)->method('upload_file')
            ->with('/template-agent/syllabus/upload', $file, ['thread_id' => 't-9'])
            ->willReturn(['status' => 'stored']);
        $service = $this->service($client);

        $answer = $service->upload_syllabus('t-9', $file);

        $this->assertSame(['status' => 'stored'], $answer);
    }

    /**
     * A null answer of the syllabus upload becomes an empty array.
     */
    public function test_a_null_answer_of_the_syllabus_upload_becomes_an_empty_array(): void {
        $this->resetAfterTest();
        $file = $this->syllabus_file();
        $client = $this->createMock(ai_course_api::class);
        $client->method('upload_file')->willReturn(null);
        $service = $this->service($client);

        $answer = $service->upload_syllabus('t-9', $file);

        $this->assertSame([], $answer);
    }

    /**
     * A generated file is downloaded from the file endpoint, with the ids encoded.
     */
    public function test_a_generated_file_is_downloaded_from_the_file_endpoint(): void {
        $client = $this->createMock(ai_course_api::class);
        $client->expects($this->once())->method('download_file')
            ->with('/template-agent/file/t%2F9/f%201', 'guide.pdf', ['itemid' => 5])
            ->willReturn(null);

        $this->assertNull($this->service($client)->download_generated_file('t/9', 'f 1', 'guide.pdf', ['itemid' => 5]));
    }

    /**
     * The state and the result are read with a get, the id encoded.
     */
    public function test_state_and_result_are_read_with_a_get(): void {
        $client = $this->createMock(ai_course_api::class);
        $client->expects($this->exactly(2))->method('request')->willReturnMap([
            ['GET', '/template-agent/state/t-9', [], ['status' => 'RUNNING']],
            ['GET', '/template-agent/result/t-9', [], ['generated_activities' => []]],
        ]);
        $service = $this->service($client);

        $this->assertSame(['status' => 'RUNNING'], $service->get_state('t-9'));
        $this->assertSame(['generated_activities' => []], $service->get_result('t-9'));
    }

    /**
     * A null answer of the client becomes an empty array.
     */
    public function test_a_null_answer_becomes_an_empty_array(): void {
        $client = $this->createMock(ai_course_api::class);
        $client->method('request')->willReturn(null);

        $this->assertSame([], $this->service($client)->get_state('t-9'));
    }

    /**
     * The browser reads the stream through the relay, never from the service.
     */
    public function test_the_stream_url_is_the_relay_of_the_plugin(): void {
        global $CFG;
        $client = $this->createMock(ai_course_api::class);

        $url = $this->service($client)->stream_url('t-9');

        $this->assertSame($CFG->wwwroot . '/local/coursegen/stream.php?streamtype=template&threadid=t-9', $url);
    }
}
