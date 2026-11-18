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
 * The files a template run sends to the AI service for its reference markers.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_reference_uploads
 */
final class template_reference_uploads_test extends \advanced_testcase {
    /** @var string A marker. */
    private const MARKER = '[[coursegen:reference: a new picture]]';

    /**
     * A forum whose intro file area holds one file, and its export entry.
     *
     * @param string $mimetype
     * @return array The file entry as the export lists it.
     */
    private function stored_entry(string $mimetype): array {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $context = \context_module::instance($forum->cmid);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_forum', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'Publicidad.jpg',
        ], 'JPGDATA');
        return [
            'filename' => 'Publicidad.jpg',
            'mimetype' => $mimetype,
            'isdir' => false,
            'contextid' => (int) $context->id,
            'component' => 'mod_forum',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'stored' => $file,
        ];
    }

    /**
     * A payload with one template source activity.
     *
     * @param array $entry
     * @param string $html
     * @return array
     */
    private function payload(array $entry, string $html): array {
        unset($entry['stored']);
        return [
            'activities' => [
                [
                    'uid' => 'mold-1',
                    'template_behavior' => ['action' => 'template'],
                    'parameters' => ['name' => 'Forum', 'structure' => ['intro' => $html], 'files' => [$entry]],
                ],
                ['uid' => 'inst-1', 'template_behavior' => ['action' => 'instance'], 'parameters' => ['name' => 'Instance']],
            ],
        ];
    }

    /**
     * An image below a marker is planned for upload under its template source's uid.
     */
    public function test_an_image_reference_is_planned_once(): void {
        $this->resetAfterTest();
        $entry = $this->stored_entry('image/jpeg');
        $html = self::MARKER . '<img src="@@PLUGINFILE@@/Publicidad.jpg">' . self::MARKER . '<img src="@@PLUGINFILE@@/Publicidad.jpg">';

        $plan = template_reference_uploads::plan($this->payload($entry, $html));

        $this->assertCount(1, $plan);
        $this->assertSame('mold-1', $plan[0]['uid']);
        $this->assertSame('Publicidad.jpg', $plan[0]['file']->get_filename());
    }

    /**
     * A file of a type the service makes nothing from is not sent: its element is removed.
     */
    public function test_a_type_without_a_handler_is_not_planned(): void {
        $this->resetAfterTest();
        $entry = $this->stored_entry('application/pdf');
        $html = self::MARKER . '<a href="@@PLUGINFILE@@/Publicidad.jpg">doc</a>';

        $this->assertSame([], template_reference_uploads::plan($this->payload($entry, $html)));
    }

    /**
     * A template without markers plans nothing.
     */
    public function test_no_marker_plans_nothing(): void {
        $this->resetAfterTest();
        $entry = $this->stored_entry('image/jpeg');

        $this->assertSame([], template_reference_uploads::plan($this->payload($entry, '<p>Hello</p>')));
    }

    /**
     * A marker with no target is refused while planning, before anything is sent.
     */
    public function test_a_marker_without_a_target_is_refused(): void {
        $this->resetAfterTest();
        $entry = $this->stored_entry('image/jpeg');

        $this->expectException(\moodle_exception::class);
        template_reference_uploads::plan($this->payload($entry, '<p>' . self::MARKER . '</p>'));
    }

    /**
     * The export lists a file that is not stored: it is an error naming the file.
     */
    public function test_a_file_that_is_not_stored_is_an_error(): void {
        $this->resetAfterTest();
        $entry = $this->stored_entry('image/jpeg');
        $entry['itemid'] = 999;
        $html = self::MARKER . '<img src="@@PLUGINFILE@@/Publicidad.jpg">';

        try {
            template_reference_uploads::plan($this->payload($entry, $html));
            $this->fail('A moodle_exception was expected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('template_reference_no_stored_file', $exception->errorcode);
        }
    }

    /**
     * Each planned file is uploaded to its session with its activity's uid, and a failure names the file.
     */
    public function test_the_files_are_sent_and_a_failure_names_the_file(): void {
        $this->resetAfterTest();
        $entry = $this->stored_entry('image/jpeg');
        $html = self::MARKER . '<img src="@@PLUGINFILE@@/Publicidad.jpg">';
        $plan = template_reference_uploads::plan($this->payload($entry, $html));

        $client = $this->createMock(\aiprovider_datacurso\httpclient\ai_course_api::class);
        $client->expects($this->once())
            ->method('upload_file')
            ->with('/course-template/reference-file/upload', $this->anything(), ['thread_id' => 't-1', 'uid' => 'mold-1'])
            ->willReturn(['filename' => 'Publicidad.jpg']);
        template_reference_uploads::send(new template_ai_api_service($client), 't-1', $plan);

        $failing = $this->createMock(\aiprovider_datacurso\httpclient\ai_course_api::class);
        $failing->method('upload_file')->willThrowException(new \Exception('413'));
        try {
            template_reference_uploads::send(new template_ai_api_service($failing), 't-1', $plan);
            $this->fail('A moodle_exception was expected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('template_reference_upload_failed', $exception->errorcode);
        }
    }
}
