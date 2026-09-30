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

/**
 * Tests for the local_coursegen data generator.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen_generator
 */
final class generator_test extends \advanced_testcase {
    /**
     * A seeded system instruction is stored as a visible guideline row.
     */
    public function test_create_system_instruction(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var \local_coursegen_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_coursegen');
        $instruction = $generator->create_system_instruction([
            'name' => 'Quality policy',
            'content' => 'All courses must include a welcome forum.',
        ]);

        $record = $DB->get_record('local_coursegen_system_instruction', ['id' => $instruction->id], '*', MUST_EXIST);
        $this->assertSame('Quality policy', $record->name);
        $this->assertSame('All courses must include a welcome forum.', $record->content);
        $this->assertEquals(0, $record->deleted);
        $this->assertEquals(get_admin()->id, $record->usermodified);
    }

    /**
     * A system instruction needs a name.
     */
    public function test_create_system_instruction_requires_name(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        $this->getDataGenerator()->get_plugin_generator('local_coursegen')->create_system_instruction([]);
    }
}
