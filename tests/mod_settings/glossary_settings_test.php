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

require_once(__DIR__ . '/../fixtures/generated_images_test_trait.php');

/**
 * Tests for glossary_settings: the generated images of the entries.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\glossary_settings
 */
final class glossary_settings_test extends \advanced_testcase {
    use \local_coursegen\generated_images_test_trait;

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
     * The image the cleaner left in the draft area of a definition is stored in the entry.
     */
    public function test_definition_image_is_stored_in_the_entry(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $cm = $this->make_glossary_cm();
        $itemid = $this->draft_with_image('img_entry.png');

        (new glossary_settings($cm, ['entries' => [[
            'concept' => 'Aerenchyma',
            'definition_editor' => [
                'text' => '<p>Air channels.</p><img src="@@PLUGINFILE@@/img_entry.png" alt="Cut">',
                'format' => FORMAT_HTML,
                'itemid' => $itemid,
            ],
        ]]]))->add_settings();

        $entry = $DB->get_record('glossary_entries', ['glossaryid' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('@@PLUGINFILE@@/img_entry.png', $entry->definition);

        $context = \context_module::instance($cm->coursemodule);
        $this->assertTrue(get_file_storage()->file_exists($context->id, 'mod_glossary', 'entry', $entry->id, '/', 'img_entry.png'));
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
