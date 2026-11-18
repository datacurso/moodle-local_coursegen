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

use local_coursegen\local\backup\activity_reader;
use local_coursegen\local\backup\backup_vars_processor;

/**
 * Any processor can walk the backup structure a module declares for an activity.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\backup\activity_reader
 * @covers     \local_coursegen\local\backup\reader_task
 * @covers     \local_coursegen\local\backup\backup_vars_processor
 */
final class activity_reader_walk_test extends \advanced_testcase {
    /**
     * The names of the elements a walk visits.
     *
     * @param \stdClass $cm The activity: id, modname and course.
     * @param bool $withuserdata
     * @return string[]|null Null when the module declares no structure.
     */
    private function walked_names(\stdClass $cm, bool $withuserdata): ?array {
        activity_reader::require_backup_api();
        $processor = new class extends backup_vars_processor {
            /** @var string[] The elements visited, in the order they opened. */
            public array $names = [];

            #[\Override]
            public function pre_process_nested_element(\base_nested_element $nested) {
                $this->names[] = $nested->get_name();
            }

            #[\Override]
            public function process_nested_element(\base_nested_element $nested) {
                return;
            }

            #[\Override]
            public function post_process_nested_element(\base_nested_element $nested) {
                return;
            }

            #[\Override]
            public function process_final_element(\base_final_element $final) {
                return;
            }

            #[\Override]
            public function process_attribute(\base_attribute $attribute) {
                return;
            }
        };
        if (!activity_reader::walk($cm, $processor, $withuserdata)) {
            return null;
        }
        return $processor->names;
    }

    /**
     * The activity of a course module, as the walk takes it.
     *
     * @param \stdClass $module What a module generator returned.
     * @param string $modname
     * @return \stdClass
     */
    private function activity_of(\stdClass $module, string $modname): \stdClass {
        return (object) ['id' => (int) $module->cmid, 'modname' => $modname, 'course' => (int) $module->course];
    }

    /**
     * What people file in a module is read only when asked for.
     */
    public function test_the_posts_of_a_forum_are_read_only_with_user_data(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => get_admin()->id,
        ]);
        $activity = $this->activity_of($forum, 'forum');

        $without = $this->walked_names($activity, false);
        $with = $this->walked_names($activity, true);

        $this->assertContains('forum', $without);
        $this->assertNotContains('post', $without);
        $this->assertContains('post', $with);
    }

    /**
     * The pages of a wiki are the wiki, so they are read without asking.
     */
    public function test_the_pages_of_a_wiki_are_always_read(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $wiki = $this->getDataGenerator()->create_module('wiki', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_wiki')->create_first_page($wiki);

        $names = $this->walked_names($this->activity_of($wiki, 'wiki'), false);

        $this->assertContains('page', $names);
    }

    /**
     * A module that declares no structure cannot be walked, and says so.
     */
    public function test_a_module_without_a_structure_is_not_walked(): void {
        $this->resetAfterTest();
        $cm = (object) ['id' => 1, 'modname' => 'nosuchmodule', 'course' => 1];

        $this->assertNull($this->walked_names($cm, true));
    }
}
