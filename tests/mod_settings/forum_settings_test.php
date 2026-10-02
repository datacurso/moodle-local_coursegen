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

namespace local_coursegen\mod_settings;

use local_coursegen\local\reference\reference_file_storage;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/forum/lib.php');

/**
 * Unit tests for forum_settings - the files a discussion message references reach the post.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\forum_settings
 */
final class forum_settings_test extends \advanced_testcase {
    /**
     * Bring a file for place 3.1 of template 7 to session 55 of a user and give its address.
     *
     * @param \stdClass $user
     * @param string $name
     * @return string
     */
    private function brought_file_address(\stdClass $user, string $name): string {
        $path = make_request_directory() . '/upload.tmp';
        file_put_contents($path, 'BROUGHT');
        reference_file_storage::stage((int) $user->id, 7, '3.1', $name, $path);
        reference_file_storage::adopt((int) $user->id, 7, 55);
        $file = reference_file_storage::session_file((int) $user->id, 55, '3.1');
        return reference_file_storage::url_of($file)->out(false);
    }

    /**
     * The file a message references is stored with the post, and the message names it as the post's own file.
     */
    public function test_a_file_the_message_references_is_stored_with_the_post(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = (object) ['coursemodule' => $forum->cmid, 'instance' => $forum->id];
        $address = $this->brought_file_address(\core_user::get_user(get_admin()->id), 'Guide.pdf');
        $message = '<p>Read it</p><a href="' . $address . '">Guide</a>';

        $settings = new forum_settings($cm, ['discussions' => [['subject' => 'Debate', 'message' => $message]]], null);
        $settings->add_settings();

        $post = $DB->get_record('forum_posts', ['subject' => 'Debate'], '*', MUST_EXIST);
        $this->assertStringContainsString('@@PLUGINFILE@@/Guide.pdf', $post->message);
        $context = \context_module::instance($forum->cmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_forum', 'post', $post->id, 'id', false);
        $this->assertCount(1, $files);
        $this->assertSame('BROUGHT', reset($files)->get_content());
    }

    /**
     * A message without files is posted as it is.
     */
    public function test_a_message_without_files_is_posted_unchanged(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = (object) ['coursemodule' => $forum->cmid, 'instance' => $forum->id];

        $settings = new forum_settings($cm, ['discussions' => [['subject' => 'Plain', 'message' => '<p>Hello</p>']]], null);
        $settings->add_settings();

        $post = $DB->get_record('forum_posts', ['subject' => 'Plain'], '*', MUST_EXIST);
        $this->assertSame('<p>Hello</p>', $post->message);
    }
}
