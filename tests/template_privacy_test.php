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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use local_coursegen\local\template\template_service;
use local_coursegen\privacy\provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/template_test_helper.php');

/**
 * Tests for what the privacy provider says and does about the templates.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\privacy\provider
 */
final class template_privacy_test extends \advanced_testcase {
    use template_test_helper;

    /**
     * Start with a clean site.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The names of the items of a metadata collection.
     *
     * @param array $items Items of the collection.
     * @return string[]
     */
    private function names_of(array $items): array {
        $names = [];
        foreach ($items as $item) {
            $names[] = $item->get_name();
        }

        return $names;
    }

    /**
     * The metadata describes both tables.
     */
    public function test_metadata_describes_the_template_tables(): void {
        $collection = provider::get_metadata(new collection('local_coursegen'));

        $items = $collection->get_collection();
        $names = $this->names_of($items);

        $this->assertContains('local_coursegen_template', $names);
        $this->assertContains('local_coursegen_tpl_item', $names);
    }

    /**
     * A user who saved a template has data in their user context.
     */
    public function test_user_who_saved_a_template_has_a_context(): void {
        [$course] = $this->make_course();
        $user = $this->getDataGenerator()->create_user();
        $service = new template_service();
        $service->save(0, (int) $course->id, 'Mine', null, [], (int) $user->id);

        $contextlist = provider::get_contexts_for_userid((int) $user->id);

        $usercontext = \context_user::instance($user->id);
        $value = $contextlist->get_contextids();
        $this->assertContains($usercontext->id, $value);
    }

    /**
     * Deleting the data of a user keeps the template and removes who modified it.
     */
    public function test_deleting_a_user_keeps_the_template_and_anonymizes_it(): void {
        global $DB;
        [$course, $page] = $this->make_course();
        $user = $this->getDataGenerator()->create_user();
        $service = new template_service();
        $items = [['cmid' => $page, 'action' => 'ai', 'instruction' => 'Keep me']];
        $templateid = $service->save(0, (int) $course->id, 'Shared', null, $items, (int) $user->id);
        $usercontext = \context_user::instance($user->id);
        $approved = new approved_contextlist($user, 'local_coursegen', [$usercontext->id]);

        provider::delete_data_for_user($approved);

        $template = $DB->get_record('local_coursegen_template', ['id' => $templateid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $template->usermodified);
        $this->assertSame('Shared', $template->name);
        $count = $DB->count_records('local_coursegen_tpl_item', ['templateid' => $templateid]);
        $this->assertSame(1, $count);
    }
}
