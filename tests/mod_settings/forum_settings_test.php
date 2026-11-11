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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/forum/lib.php');

/**
 * Unit tests for forum_settings - the discussions are posted as written; their files are placed by activity_file_pass.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\forum_settings
 */
final class forum_settings_test extends \advanced_testcase {
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

        $settings = new forum_settings($cm, ['discussions' => [['subject' => 'Plain', 'message' => '<p>Hello</p>']]]);
        $settings->add_settings();

        $post = $DB->get_record('forum_posts', ['subject' => 'Plain'], '*', MUST_EXIST);
        $this->assertSame('<p>Hello</p>', $post->message);
    }
}
