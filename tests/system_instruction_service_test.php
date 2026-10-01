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

use core\exception\moodle_exception;
use local_coursegen\local\models\system_instruction;
use local_coursegen\local\service\system_instruction_service;

/**
 * Tests for the institutional guideline (system instruction) service.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\system_instruction_service
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\service\system_instruction_service::class)]
final class system_instruction_service_test extends \advanced_testcase {
    /**
     * A created instruction stores the trimmed name, the content and the current user as modifier.
     */
    public function test_create_stores_instruction_for_current_user(): void {
        global $DB;

        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $this->setUser($author);

        $instruction = system_instruction_service::create('  Quality policy  ', 'Include a welcome forum.');

        $record = $DB->get_record(system_instruction::TABLE, ['id' => $instruction->get('id')], '*', MUST_EXIST);
        $this->assertSame('Quality policy', $record->name);
        $this->assertSame('Include a welcome forum.', $record->content);
        $this->assertEquals(0, $record->deleted);
        $this->assertEquals($author->id, $record->usermodified);
        $this->assertGreaterThan(0, $record->timecreated);
        $this->assertEquals($record->timecreated, $record->timemodified);
    }

    /**
     * Names must be unique among active instructions and may not be blank.
     */
    public function test_create_rejects_duplicate_or_blank_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        system_instruction_service::create('Quality policy', 'A');

        try {
            system_instruction_service::create('Quality policy', 'B');
            $this->fail('A duplicate name must be rejected.');
        } catch (moodle_exception $e) {
            $this->assertSame('systeminstructionnameexists', $e->errorcode);
        }

        try {
            system_instruction_service::create('   ', 'B');
            $this->fail('A blank name must be rejected.');
        } catch (moodle_exception $e) {
            $this->assertSame('systeminstructionnameexists', $e->errorcode);
        }

        $this->assertCount(1, system_instruction_service::get_all());
    }

    /**
     * A name freed by a soft delete can be used again.
     */
    public function test_create_allows_name_of_deleted_instruction(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $old = system_instruction_service::create('Quality policy', 'Old');
        system_instruction_service::delete((int)$old->get('id'));

        $new = system_instruction_service::create('Quality policy', 'New');

        $this->assertNotEquals($old->get('id'), $new->get('id'));
        $this->assertSame('New', system_instruction_service::get_instruction_content((int)$new->get('id')));
    }

    /**
     * get_all() lists only active instructions, newest first.
     */
    public function test_get_all_lists_active_instructions_newest_first(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_coursegen_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursegen');
        $generator->create_system_instruction(['name' => 'Old', 'timecreated' => 100]);
        $generator->create_system_instruction(['name' => 'Deleted', 'deleted' => 1, 'timecreated' => 200]);
        $generator->create_system_instruction(['name' => 'New', 'timecreated' => 300]);

        $names = array_map(
            static fn(system_instruction $instruction): string => $instruction->get('name'),
            array_values(system_instruction_service::get_all())
        );

        $this->assertSame(['New', 'Old'], $names);
    }

    /**
     * get_by_id() returns active instructions only.
     */
    public function test_get_by_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $active = system_instruction_service::create('Active', 'A');
        $deleted = system_instruction_service::create('Deleted', 'D');
        system_instruction_service::delete((int)$deleted->get('id'));

        $found = system_instruction_service::get_by_id((int)$active->get('id'));
        $this->assertInstanceOf(system_instruction::class, $found);
        $this->assertSame('Active', $found->get('name'));

        $this->assertNull(system_instruction_service::get_by_id((int)$deleted->get('id')));
        $this->assertNull(system_instruction_service::get_by_id(999999));
    }

    /**
     * update() changes name and content and records the user who modified it.
     */
    public function test_update_records_modifier(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $editor = $generator->create_user();

        $this->setUser($author);
        $instruction = system_instruction_service::create('Quality policy', 'Old content');
        $DB->set_field(system_instruction::TABLE, 'timemodified', 1000, ['id' => $instruction->get('id')]);

        $this->setUser($editor);
        $updated = system_instruction_service::update((int)$instruction->get('id'), '  Renamed policy ', 'New content');

        $this->assertSame('Renamed policy', $updated->get('name'));
        $record = $DB->get_record(system_instruction::TABLE, ['id' => $instruction->get('id')], '*', MUST_EXIST);
        $this->assertSame('Renamed policy', $record->name);
        $this->assertSame('New content', $record->content);
        $this->assertEquals($editor->id, $record->usermodified);
        $this->assertGreaterThan(1000, $record->timemodified);
    }

    /**
     * An instruction may keep its own name but not take the name of another active one.
     */
    public function test_update_enforces_unique_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $first = system_instruction_service::create('First', 'A');
        system_instruction_service::create('Second', 'B');

        $same = system_instruction_service::update((int)$first->get('id'), 'First', 'Changed');
        $this->assertSame('Changed', $same->get('content'));

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('systeminstructionnameexists', 'local_coursegen'));
        system_instruction_service::update((int)$first->get('id'), 'Second', 'A');
    }

    /**
     * Deleted or missing instructions cannot be updated.
     */
    public function test_update_rejects_deleted_or_missing_instruction(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $deleted = system_instruction_service::create('Deleted', 'D');
        system_instruction_service::delete((int)$deleted->get('id'));

        foreach ([(int)$deleted->get('id'), 999999] as $id) {
            try {
                system_instruction_service::update($id, 'Any', 'Any');
                $this->fail('Updating instruction ' . $id . ' must be rejected.');
            } catch (moodle_exception $e) {
                $this->assertSame('invalidrecord', $e->errorcode);
            }
        }
    }

    /**
     * delete() soft deletes: the row stays, flagged, and disappears from the service reads.
     */
    public function test_delete_is_soft(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $instruction = system_instruction_service::create('Quality policy', 'Content');
        $id = (int)$instruction->get('id');

        $this->assertTrue(system_instruction_service::delete($id));

        $record = $DB->get_record(system_instruction::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(1, $record->deleted);
        $this->assertSame('Quality policy', $record->name);
        $this->assertNull(system_instruction_service::get_by_id($id));
        $this->assertSame([], system_instruction_service::get_all());
        $this->assertSame('', system_instruction_service::get_instruction_content($id));

        // Deleting again, or a missing id, reports nothing to delete.
        $this->assertFalse(system_instruction_service::delete($id));
        $this->assertFalse(system_instruction_service::delete(999999));
    }

    /**
     * get_instruction_content() returns the content, or an empty string when there is none.
     */
    public function test_get_instruction_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $withcontent = system_instruction_service::create('With content', 'Follow the style guide.');
        $empty = system_instruction_service::create('Empty', '');

        $this->assertSame(
            'Follow the style guide.',
            system_instruction_service::get_instruction_content((int)$withcontent->get('id'))
        );
        $this->assertSame('', system_instruction_service::get_instruction_content((int)$empty->get('id')));
        $this->assertSame('', system_instruction_service::get_instruction_content(999999));
    }
}
