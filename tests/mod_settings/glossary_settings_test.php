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

/**
 * Tests for glossary_settings: the entries of a glossary.
 *
 * The generated images of an entry are given to it by activity_file_pass once the activity exists, see
 * tests/files/activity_file_pass_test.php.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\glossary_settings
 */
final class glossary_settings_test extends \advanced_testcase {
    /**
     * Create a glossary and return a cm-like object shaped as create_mod_service passes it.
     *
     * @return object Object with ->coursemodule (cmid) and ->instance (glossary id).
     */
    private function make_glossary_cm(): object {
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        return (object) ['coursemodule' => $glossary->cmid, 'instance' => $glossary->id];
    }

    /**
     * An entry without a draft area is still created.
     */
    public function test_entry_without_draft_area_is_created(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $cm = $this->make_glossary_cm();

        (new glossary_settings($cm, ['entries' => [[
            'concept' => 'Tiller',
            'definition_editor' => ['text' => '<p>A shoot.</p>', 'format' => FORMAT_HTML],
        ]]]))->add_settings();

        $this->assertSame(1, $DB->count_records('glossary_entries', ['glossaryid' => $cm->instance]));
    }
}
