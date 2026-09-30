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

defined('MOODLE_INTERNAL') || die();

// The shared fixture trait sits in tests/ root, outside the tests/classes
// autoload scope, so it must be required explicitly.
require_once(__DIR__ . '/sections_config_fixture_trait.php');

use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_space;
use local_coursegen\output\sections_config;

/**
 * save_template's persistence of spaces the professor fills with an activity
 * of their own - virtual ones, and existing activities marked "space" - and
 * sections_config's hydration of them back into the review.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_persistence_service
 * @covers     \local_coursegen\output\sections_config
 *
 * @runTestsInSeparateProcesses
 */
final class save_template_spaces_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * A virtual space persists every field, and renders back into the review
     * with its type, its requirement badge and its instruction.
     */
    public function test_virtual_space_round_trips_into_the_review(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [['cmid' => (int) $page->cmid, 'action' => 'keep', 'useasreference' => true, 'prompt' => '']],
            [],
            0,
            [$this->space_payload('resource', true, 'Upload the weekly guide as a PDF.', (int) $page->cmid, 0)]
        );

        $record = template_space::get_record(['templateid' => (int) $saved['id']]);
        $this->assertNotFalse($record);
        $this->assertSame('resource', $record->get('modname'));
        $this->assertSame(1, (int) $record->get('required'));
        $this->assertSame('Upload the weekly guide as a PDF.', $record->get('instruction'));
        $this->assertSame((int) $page->cmid, (int) $record->get('aftercmid'));
        $this->assertNotSame('', $record->get('uid'));

        $html = sections_config::render(get_fast_modinfo($course), (int) $saved['id']);
        $area = $this->extract_space_area($html, (int) $record->get('id'));

        $requiredlabel = get_string('template_space_required', 'local_coursegen');
        $this->assertStringContainsString(get_string('template_space_badge', 'local_coursegen', $requiredlabel), $area);
        $this->assertStringContainsString('data-modname="resource"', $area);
        $this->assertStringContainsString('data-required="1"', $area);
        $this->assertStringContainsString('Upload the weekly guide as a PDF.', $area);
        $this->assertStringContainsString(get_string('modulename', 'mod_resource'), $area);
    }

    /**
     * An optional space with no instruction stores no instruction at all and
     * renders its subtitle hidden.
     */
    public function test_optional_space_without_instruction_stores_null_and_hides_the_subtitle(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [],
            [],
            0,
            [$this->space_payload('forum', false, '   ', 0, 0)]
        );

        $record = template_space::get_record(['templateid' => (int) $saved['id']]);
        $this->assertSame(0, (int) $record->get('required'));
        $this->assertNull($record->get('instruction'));

        $html = sections_config::render(get_fast_modinfo($course), (int) $saved['id']);
        $area = $this->extract_space_area($html, (int) $record->get('id'));
        $optionallabel = get_string('template_space_optional', 'local_coursegen');
        $this->assertStringContainsString(get_string('template_space_badge', 'local_coursegen', $optionallabel), $area);
        $this->assertStringContainsString('data-required="0"', $area);
        $this->assertMatchesRegularExpression('/class="tpl-space-instruction[^"]*d-none"/', $area);
    }

    /**
     * A space and an instance that share an anchor keep the order they were
     * saved in, because they share one sortorder sequence.
     */
    public function test_space_and_instance_keep_their_saved_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [['cmid' => (int) $page->cmid, 'action' => 'template', 'useasreference' => true, 'prompt' => '']],
            [$this->instance_payload((int) $page->cmid, 'Page template', 'Second', (int) $page->cmid, 1)],
            0,
            [$this->space_payload('resource', true, 'First', (int) $page->cmid, 0)]
        );

        $html = sections_config::render(get_fast_modinfo($course), (int) $saved['id']);
        $spaceposition = strpos($html, 'data-for="spacerow"');
        $instanceposition = strpos($html, 'data-for="instancerow"');
        $this->assertNotFalse($spaceposition);
        $this->assertNotFalse($instanceposition);
        $this->assertLessThan($instanceposition, $spaceposition);
    }

    /**
     * An existing activity marked "space" keeps its requirement and
     * instruction, preselects the action and shows its badge.
     */
    public function test_activity_marked_as_space_round_trips_into_the_review(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [[
                'cmid' => (int) $page->cmid,
                'action' => 'space',
                'useasreference' => true,
                'prompt' => '',
                'spacerequired' => false,
                'spaceinstruction' => 'Replace this page with your own welcome.',
            ]]
        );

        $record = template_activity::get_record(['templateid' => (int) $saved['id'], 'cmid' => (int) $page->cmid]);
        $this->assertSame('space', $record->get('action'));
        $this->assertSame(0, (int) $record->get('spacerequired'));
        $this->assertSame('Replace this page with your own welcome.', $record->get('spaceinstruction'));

        $html = sections_config::render(get_fast_modinfo($course), (int) $saved['id']);
        $select = $this->extract_action_select($html, (int) $page->cmid);
        $this->assertMatchesRegularExpression('/<option value="space"[^>]*\sselected/', $select);

        $tag = $this->extract_space_tag($html, (int) $page->cmid);
        $optionallabel = get_string('template_space_optional', 'local_coursegen');
        $this->assertStringContainsString(get_string('template_space_badge', 'local_coursegen', $optionallabel), $tag);
        $this->assertStringNotContainsString('d-none', substr($tag, 0, strpos($tag, '>')));
        $this->assertStringContainsString('Replace this page with your own welcome.', $html);
    }

    /**
     * An activity that is not marked as a space keeps its badge hidden.
     */
    public function test_activity_not_marked_as_space_keeps_its_badge_hidden(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();

        $html = sections_config::render(get_fast_modinfo($course));

        $tag = $this->extract_space_tag($html, (int) $page->cmid);
        $this->assertStringContainsString('d-none', substr($tag, 0, strpos($tag, '>')));
    }

    /**
     * Saving again replaces the template's spaces instead of adding to them.
     */
    public function test_resaving_replaces_the_spaces(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $first = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [],
            [],
            0,
            [$this->space_payload('resource', true, 'One', 0, 0), $this->space_payload('forum', true, 'Two', 0, 1)]
        );
        $this->assertSame(2, template_space::count_records(['templateid' => (int) $first['id']]));

        $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [],
            [],
            (int) $first['id'],
            [$this->space_payload('page', false, 'Only', 0, 0)]
        );

        $spaces = template_space::get_records(['templateid' => (int) $first['id']]);
        $this->assertCount(1, $spaces);
        $this->assertSame('page', reset($spaces)->get('modname'));
    }

    /**
     * A space for something that is not an installed activity type is rejected.
     */
    public function test_space_for_an_unknown_activity_type_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $this->expectException(\invalid_parameter_exception::class);
        $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [],
            [],
            0,
            [$this->space_payload('notamodule', true, '', 0, 0)]
        );
    }

    /**
     * Deleting a template deletes its spaces too.
     */
    public function test_deleting_a_template_deletes_its_spaces(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $saved = $this->save_with_instances(
            (int) $course->id,
            (int) $section1->id,
            1,
            [],
            [],
            0,
            [$this->space_payload('resource', true, 'Gone soon', 0, 0)]
        );
        $this->assertSame(1, template_space::count_records(['templateid' => (int) $saved['id']]));

        \local_coursegen\external\delete_template::execute((int) $saved['id']);

        $this->assertSame(0, template_space::count_records(['templateid' => (int) $saved['id']]));
    }

    /**
     * Build a minimal space save payload entry (see
     * classes/external/save_template.php's "spaces" structure).
     *
     * @param string $modname
     * @param bool $required
     * @param string $instruction
     * @param int $aftercmid
     * @param int $sortorder
     * @return array
     */
    private function space_payload(
        string $modname,
        bool $required,
        string $instruction,
        int $aftercmid,
        int $sortorder
    ): array {
        return [
            'modname' => $modname,
            'required' => $required,
            'instruction' => $instruction,
            'aftercmid' => $aftercmid,
            'sortorder' => $sortorder,
        ];
    }

    /**
     * Extract the markup of one virtual space row, from its opening tag to
     * its closing one, so assertions cannot match a different row.
     *
     * @param string $html Full rendered review.
     * @param int $spaceid
     * @return string
     */
    private function extract_space_area(string $html, int $spaceid): string {
        $start = strpos($html, 'data-space-id="' . $spaceid . '"');
        $this->assertNotFalse($start, 'Space row not found');
        $end = strpos($html, '</tr>', $start);
        $this->assertNotFalse($end, 'Unterminated space row');
        return substr($html, $start, $end - $start);
    }

    /**
     * Extract one real activity row's clickable space badge markup (opening
     * tag through its closing tag).
     *
     * @param string $html Full rendered review.
     * @param int $cmid
     * @return string
     */
    private function extract_space_tag(string $html, int $cmid): string {
        $marker = 'data-region="space-tag" data-id="' . $cmid . '"';
        $markerpos = strpos($html, $marker);
        $this->assertNotFalse($markerpos, 'No space tag rendered matching: ' . $marker);
        $tagstart = strrpos(substr($html, 0, $markerpos), '<button');
        $tagend = strpos($html, '</button>', $tagstart);
        return substr($html, $tagstart, $tagend + strlen('</button>') - $tagstart);
    }
}
