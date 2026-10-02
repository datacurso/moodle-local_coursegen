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

use local_coursegen\local\reference\reference_file_server;
use local_coursegen\local\reference\reference_file_storage;

/**
 * Who may read the files a teacher brought for a generation.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\reference\reference_file_server
 */
final class reference_file_server_test extends \advanced_testcase {
    /**
     * A session file of a user, and the arguments of its address.
     *
     * @param \stdClass $user
     * @return array{0: \context_user, 1: array}
     */
    private function session_file_of(\stdClass $user): array {
        $directory = make_request_directory();
        $path = $directory . '/upload.tmp';
        file_put_contents($path, 'PDFDATA');
        $userid = (int) $user->id;
        reference_file_storage::stage($userid, 7, '12.1', 'guide.pdf', $path);
        reference_file_storage::adopt($userid, 7, 55);
        $context = \context_user::instance($userid);
        return [$context, ['55', '12.1', 'guide.pdf']];
    }

    /**
     * The owner reads the file of their session.
     */
    public function test_the_owner_reads_the_file(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        [$context, $args] = $this->session_file_of($user);

        $file = reference_file_server::file_for($context, 'referencefile', $args, (int) $user->id);

        $this->assertNotNull($file);
        $this->assertSame('PDFDATA', $file->get_content());
    }

    /**
     * Another user does not read it.
     */
    public function test_another_user_does_not_read_the_file(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        [$context, $args] = $this->session_file_of($user);

        $file = reference_file_server::file_for($context, 'referencefile', $args, (int) $other->id);

        $this->assertNull($file);
    }

    /**
     * The files staged before a generation are never addressed.
     */
    public function test_the_staged_area_is_not_served(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        [$context, $args] = $this->session_file_of($user);

        $file = reference_file_server::file_for($context, 'referencestaged', $args, (int) $user->id);

        $this->assertNull($file);
    }

    /**
     * Only a user context is served.
     */
    public function test_a_context_that_is_not_a_user_context_is_not_served(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        [, $args] = $this->session_file_of($user);
        $context = \context_system::instance();

        $file = reference_file_server::file_for($context, 'referencefile', $args, (int) $user->id);

        $this->assertNull($file);
    }

    /**
     * An address with too few parts, or for a file that is not there, serves nothing.
     */
    public function test_a_short_or_unknown_address_serves_nothing(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        [$context] = $this->session_file_of($user);
        $userid = (int) $user->id;

        $short = reference_file_server::file_for($context, 'referencefile', ['55', 'guide.pdf'], $userid);
        $unknown = reference_file_server::file_for($context, 'referencefile', ['55', '12.1', 'other.pdf'], $userid);

        $this->assertNull($short);
        $this->assertNull($unknown);
    }
}
