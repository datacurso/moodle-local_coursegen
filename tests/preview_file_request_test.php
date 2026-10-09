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

use local_coursegen\local\preview\preview_file_request;

/**
 * The address a file of the preview is asked for by.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\preview_file_request
 */
final class preview_file_request_test extends \basic_testcase {
    /** @var string The opaque uid of an activity. */
    private const UID = '7f1c2a9e-5b0d-4c1e-9a77-3e2d8b6a4f10';

    /**
     * The session, the uid and the file name come out of the path.
     */
    public function test_a_valid_path_is_read(): void {
        $request = preview_file_request::from_path('/221/' . self::UID . '/guide.pdf');

        $this->assertSame(221, $request->sessionid());
        $this->assertSame(self::UID, $request->uid());
        $this->assertSame('guide.pdf', $request->filename());
    }

    /**
     * The leading slash is optional, and a name with spaces and accents is kept.
     */
    public function test_the_leading_slash_is_optional_and_the_name_keeps_its_characters(): void {
        $request = preview_file_request::from_path('5/' . self::UID . '/Guía semanal 2.pdf');

        $this->assertSame(5, $request->sessionid());
        $this->assertSame('Guía semanal 2.pdf', $request->filename());
    }

    /**
     * Everything that is not exactly session, uid and name is refused.
     *
     * @return array
     */
    public static function refused_paths(): array {
        return [
            'empty' => [''],
            'only a slash' => ['/'],
            'one part' => ['/221'],
            'two parts' => ['/221/' . self::UID],
            'no file name' => ['/221/' . self::UID . '/'],
            'session is not a number' => ['/abc/' . self::UID . '/guide.pdf'],
            'session is zero' => ['/0/' . self::UID . '/guide.pdf'],
            'session is negative' => ['/-4/' . self::UID . '/guide.pdf'],
            'uid has a dot' => ['/221/..' . '/guide.pdf'],
            'uid has a space' => ['/221/a b/guide.pdf'],
            'uid is too long' => ['/221/' . str_repeat('a', 65) . '/guide.pdf'],
            'name goes up a folder' => ['/221/' . self::UID . '/../guide.pdf'],
            'name has a folder' => ['/221/' . self::UID . '/folder/guide.pdf'],
            'name is a dot' => ['/221/' . self::UID . '/.'],
            'name is two dots' => ['/221/' . self::UID . '/..'],
            'name has a null byte' => ['/221/' . self::UID . "/guide\0.pdf"],
        ];
    }

    /**
     * A path that is not a session, a uid and a file name is refused.
     *
     * @dataProvider refused_paths
     * @param string $path
     */
    public function test_a_path_that_is_not_session_uid_and_name_is_refused(string $path): void {
        $this->expectException(\moodle_exception::class);
        preview_file_request::from_path($path);
    }
}
