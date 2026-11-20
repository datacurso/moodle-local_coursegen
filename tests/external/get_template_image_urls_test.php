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

namespace local_coursegen\external;

use core_external\external_api;
use local_coursegen\local\models\course_session;
use local_coursegen\local\models\template;

/**
 * Tests for get_template_image_urls.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_template_image_urls
 */
final class get_template_image_urls_test extends \advanced_testcase {
    /** @var string A one pixel PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * A template course with a cover, a template on it and a session of a user.
     *
     * @param int $userid The owner of the session.
     * @return int The session record id.
     */
    private function session(int $userid): int {
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
        $course = $this->getDataGenerator()->create_course(['summary' => '[[coursegen:reference: a cover]]']);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_course::instance($course->id)->id,
            'component' => 'course',
            'filearea' => 'overviewfiles',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'cover.png',
        ], base64_decode(self::PNG));
        $template = new template(0, (object) ['courseid' => $course->id, 'name' => 'T']);
        $template->create();
        $session = new course_session(0, (object) [
            'userid' => $userid,
            'session_id' => 'thread-1',
            'templateid' => $template->get('id'),
            'status' => 1,
        ]);
        $session->create();
        return (int) $session->get('id');
    }

    /**
     * The owner of a session gets the addresses of each picture it asks for; an unknown one gets empty addresses.
     */
    public function test_the_owner_gets_the_addresses_of_each_picture(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $recordid = $this->session((int) get_admin()->id);

        $result = get_template_image_urls::execute($recordid, [
            ['key' => 'course:cover', 'kind' => 'course_cover'],
            ['key' => 'inline:a:b', 'kind' => 'inline'],
        ]);
        $result = external_api::clean_returnvalue(get_template_image_urls::execute_returns(), $result);

        $this->assertCount(2, $result);
        $this->assertSame('course:cover', $result[0]['key']);
        $this->assertStringContainsString('/course/overviewfiles/cover.png', $result[0]['original']);
        $this->assertSame('', $result[0]['current']);
        $this->assertSame(['key' => 'inline:a:b', 'original' => '', 'current' => ''], $result[1]);
    }

    /**
     * A session of another user is refused.
     */
    public function test_a_session_of_another_user_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $other = $this->getDataGenerator()->create_user();
        $recordid = $this->session((int) $other->id);

        $this->expectException(\moodle_exception::class);
        get_template_image_urls::execute($recordid, [['key' => 'course:cover', 'kind' => 'course_cover']]);
    }

    /**
     * A user without the template capability is refused.
     */
    public function test_a_user_without_the_capability_is_refused(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $recordid = $this->session((int) $user->id);
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        get_template_image_urls::execute($recordid, [['key' => 'course:cover', 'kind' => 'course_cover']]);
    }
}
