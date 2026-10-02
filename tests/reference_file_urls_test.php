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

use local_coursegen\local\models\course_session;
use local_coursegen\local\reference\reference_file_storage;
use local_coursegen\local\reference\reference_file_urls;

/**
 * The addresses of the files a generation uses, by the name the payload gives their place.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_file_urls
 */
final class reference_file_urls_test extends \advanced_testcase {
    /**
     * A payload with one real activity that lists one place with a file.
     *
     * @return array
     */
    private function payload(): array {
        return [
            'activities' => [['uid' => 'uid-aaa', 'cmid' => 12]],
            'reference_files' => ['uid-aaa.1'],
        ];
    }

    /**
     * Stage and hand over a file for place 12.1 of template 7 to session 55.
     *
     * @param int $userid
     */
    private function bring_file(int $userid): void {
        $directory = make_request_directory();
        $path = $directory . '/upload.tmp';
        file_put_contents($path, 'PDFDATA');
        reference_file_storage::stage($userid, 7, '12.1', 'guide.pdf', $path);
        reference_file_storage::adopt($userid, 7, 55);
    }

    /**
     * Each listed place has the address of the file of the session.
     */
    public function test_each_listed_place_has_the_address_of_its_file(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $this->bring_file((int) $user->id);

        $urls = reference_file_urls::for_session($this->payload(), (int) $user->id, 55);

        $this->assertSame(['uid-aaa.1'], array_keys($urls));
        $this->assertStringContainsString('/local_coursegen/referencefile/55/12.1/guide.pdf', $urls['uid-aaa.1']);
    }

    /**
     * A payload that lists nothing has no addresses.
     */
    public function test_a_payload_listing_nothing_has_no_addresses(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $this->bring_file((int) $user->id);

        $urls = reference_file_urls::for_session(['activities' => []], (int) $user->id, 55);

        $this->assertSame([], $urls);
    }

    /**
     * A listed place whose file is gone stops the run and names the place.
     */
    public function test_a_listed_place_that_lost_its_file_is_reported(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();

        try {
            reference_file_urls::for_session($this->payload(), (int) $user->id, 55);
            $this->fail('A place without its file was read as having one.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('referencefilemissing', $exception->errorcode);
            $this->assertStringContainsString('uid-aaa.1', $exception->getMessage());
        }
    }

    /**
     * The files of another teacher's session are never read.
     */
    public function test_the_files_of_another_teacher_are_not_read(): void {
        $this->resetAfterTest(true);
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->bring_file((int) $owner->id);

        $this->expectException(\moodle_exception::class);

        reference_file_urls::for_session($this->payload(), (int) $other->id, 55);
    }

    /**
     * A session reads its payload and its owner from the record it was stored with.
     */
    public function test_a_session_reads_its_own_payload_and_owner(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $session = new course_session(0, (object) [
            'userid' => (int) $user->id,
            'session_id' => 'thread-1',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['templateid' => 7, 'payload' => $this->payload()]),
        ]);
        $session->create();
        $directory = make_request_directory();
        $path = $directory . '/upload.tmp';
        file_put_contents($path, 'PDFDATA');
        reference_file_storage::stage((int) $user->id, 7, '12.1', 'guide.pdf', $path);
        reference_file_storage::adopt((int) $user->id, 7, (int) $session->get('id'));

        $urls = reference_file_urls::for_course_session($session);

        $this->assertSame(['uid-aaa.1'], array_keys($urls));
    }

    /**
     * A session stored with no payload has no addresses.
     */
    public function test_a_session_without_payload_has_no_addresses(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $session = new course_session(0, (object) [
            'userid' => (int) $user->id,
            'session_id' => 'thread-2',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => '{}',
        ]);
        $session->create();

        $urls = reference_file_urls::for_course_session($session);

        $this->assertSame([], $urls);
    }
}
