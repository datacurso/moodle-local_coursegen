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

namespace local_coursegen\local\service;

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_section;

/**
 * The instruction of a template section is kept with its files when the template is saved.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_persistence_service
 * @covers     \local_coursegen\local\models\template_section
 */
final class template_section_instruction_test extends \advanced_testcase {
    /**
     * Save one section of a new template.
     *
     * @param array $extra What the section brings besides its behavior.
     * @param string $behavior
     * @return array [template id, section id]
     */
    private function save(array $extra, string $behavior = 'template'): array {
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $sectionid = (int) get_fast_modinfo($course)->get_section_info(1)->id;
        $template = new template(0);
        $template->set('name', 'Units');
        $template->set('courseid', (int) $course->id);
        $template->create();
        template_persistence_service::save_sections((int) $template->get('id'), [[
            'sectionid' => $sectionid,
            'sectionnum' => 1,
            'behavior' => $behavior,
            'activities' => [],
            'instances' => [],
            'spaces' => [],
        ] + $extra]);
        return [(int) $template->get('id'), $sectionid];
    }

    /**
     * A draft area with one picture, as the editor leaves it.
     *
     * @return int The draft item id.
     */
    private function draft_with_picture(): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'unit.png',
        ], 'png');
        return $draftid;
    }

    /**
     * The instruction and its picture stay with the template section.
     */
    public function test_the_instruction_and_its_files_are_kept(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with_picture();
        $html = '<p>Una lección por tema.</p><img src="' . \moodle_url::make_draftfile_url($draftid, '/', 'unit.png') . '">';

        [$templateid, $sectionid] = $this->save([
            'instruction' => $html,
            'instructionformat' => FORMAT_HTML,
            'instructiondraftid' => $draftid,
        ]);

        $record = template_section::get_record(['templateid' => $templateid, 'sectionid' => $sectionid]);
        $this->assertStringContainsString('Una lección por tema.', $record->get('instruction'));
        $this->assertStringContainsString('@@PLUGINFILE@@/unit.png', $record->get('instruction'));
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_coursegen',
            template_section::INSTRUCTION_FILEAREA,
            (int) $record->get('id'),
            'id',
            false
        );
        $this->assertSame(['unit.png'], array_values(array_map(fn($file) => $file->get_filename(), $files)));
    }

    /**
     * A section that is not a template section keeps no instruction.
     */
    public function test_another_section_keeps_no_instruction(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$templateid, $sectionid] = $this->save(['instruction' => '<p>x</p>'], 'aimodify');

        $record = template_section::get_record(['templateid' => $templateid, 'sectionid' => $sectionid]);
        $this->assertNull($record->get('instruction'));
    }

    /**
     * Deleting the section takes the files of its instruction along.
     */
    public function test_deleting_the_section_deletes_its_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with_picture();
        [$templateid, $sectionid] = $this->save(['instruction' => '', 'instructiondraftid' => $draftid]);
        $record = template_section::get_record(['templateid' => $templateid, 'sectionid' => $sectionid]);
        $id = (int) $record->get('id');

        $record->delete();

        $this->assertTrue(get_file_storage()->is_area_empty(
            \context_system::instance()->id,
            'local_coursegen',
            template_section::INSTRUCTION_FILEAREA,
            $id
        ));
    }
}
