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
        $sectionid = $this->first_section_id($course);
        $activities = [['cmid' => (int) $page->cmid, 'action' => 'keep', 'useasreference' => true, 'prompt' => '']];
        $space = $this->space_payload('resource', true, 'Upload the weekly guide as a PDF.', (int) $page->cmid, 0);

        $saved = $this->save_with_instances((int) $course->id, $sectionid, 1, $activities, [], 0, [$space]);

        $templateid = (int) $saved['id'];
        $record = template_space::get_record(['templateid' => $templateid]);
        $this->assertNotFalse($record);
        $modname = $record->get('modname');
        $required = (int) $record->get('required');
        $instruction = $record->get('instruction');
        $aftercmid = (int) $record->get('aftercmid');
        $uid = $record->get('uid');
        $this->assertSame('resource', $modname);
        $this->assertSame(1, $required);
        $this->assertSame('Upload the weekly guide as a PDF.', $instruction);
        $this->assertSame((int) $page->cmid, $aftercmid);
        $this->assertNotSame('', $uid);

        $html = $this->render_review($course, $templateid);
        $spaceid = (int) $record->get('id');
        $area = $this->extract_space_area($html, $spaceid);

        $badge = $this->badge_text('template_space_required');
        $typename = get_string('modulename', 'mod_resource');
        $this->assertStringContainsString($badge, $area);
        $this->assertStringContainsString('data-modname="resource"', $area);
        $this->assertStringContainsString('data-required="1"', $area);
        $this->assertStringContainsString('Upload the weekly guide as a PDF.', $area);
        $this->assertStringContainsString($typename, $area);
    }

    /**
     * An optional space with no instruction stores no instruction at all and
     * renders its subtitle hidden.
     */
    public function test_optional_space_without_instruction_stores_null_and_hides_the_subtitle(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $sectionid = $this->first_section_id($course);
        $space = $this->space_payload('forum', false, '   ', 0, 0);

        $saved = $this->save_with_instances((int) $course->id, $sectionid, 1, [], [], 0, [$space]);

        $templateid = (int) $saved['id'];
        $record = template_space::get_record(['templateid' => $templateid]);
        $required = (int) $record->get('required');
        $instruction = $record->get('instruction');
        $this->assertSame(0, $required);
        $this->assertNull($instruction);

        $html = $this->render_review($course, $templateid);
        $spaceid = (int) $record->get('id');
        $area = $this->extract_space_area($html, $spaceid);
        $badge = $this->badge_text('template_space_optional');
        $this->assertStringContainsString($badge, $area);
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
        $sectionid = $this->first_section_id($course);
        $cmid = (int) $page->cmid;
        $activities = [['cmid' => $cmid, 'action' => 'template', 'useasreference' => true, 'prompt' => '']];
        $instance = $this->instance_payload($cmid, 'Page template', 'Second', $cmid, 1);
        $space = $this->space_payload('resource', true, 'First', $cmid, 0);

        $saved = $this->save_with_instances((int) $course->id, $sectionid, 1, $activities, [$instance], 0, [$space]);

        $html = $this->render_review($course, (int) $saved['id']);
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
        $sectionid = $this->first_section_id($course);
        $cmid = (int) $page->cmid;
        $activities = [[
            'cmid' => $cmid,
            'action' => 'space',
            'useasreference' => true,
            'prompt' => '',
            'spacerequired' => false,
            'spaceinstruction' => 'Replace this page with your own welcome.',
        ]];

        $saved = $this->save_with_instances((int) $course->id, $sectionid, 1, $activities, []);

        $templateid = (int) $saved['id'];
        $record = template_activity::get_record(['templateid' => $templateid, 'cmid' => $cmid]);
        $action = $record->get('action');
        $required = (int) $record->get('spacerequired');
        $instruction = $record->get('spaceinstruction');
        $this->assertSame('space', $action);
        $this->assertSame(0, $required);
        $this->assertSame('Replace this page with your own welcome.', $instruction);

        $html = $this->render_review($course, $templateid);
        $select = $this->extract_action_select($html, $cmid);
        $this->assertMatchesRegularExpression('/<option value="space"[^>]*\sselected/', $select);

        $tag = $this->extract_space_tag($html, $cmid);
        $badge = $this->badge_text('template_space_optional');
        $opening = $this->opening_tag($tag);
        $this->assertStringContainsString($badge, $tag);
        $this->assertStringNotContainsString('d-none', $opening);
        $this->assertStringContainsString('Replace this page with your own welcome.', $html);
    }

    /**
     * An activity that is not marked as a space keeps its badge hidden.
     */
    public function test_activity_not_marked_as_space_keeps_its_badge_hidden(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();

        $html = $this->render_review($course);

        $tag = $this->extract_space_tag($html, (int) $page->cmid);
        $opening = $this->opening_tag($tag);
        $this->assertStringContainsString('d-none', $opening);
    }

    /**
     * Saving again replaces the template's spaces instead of adding to them.
     */
    public function test_resaving_replaces_the_spaces(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $sectionid = $this->first_section_id($course);
        $courseid = (int) $course->id;
        $resourcespace = $this->space_payload('resource', true, 'One', 0, 0);
        $forumspace = $this->space_payload('forum', true, 'Two', 0, 1);
        $twospaces = [$resourcespace, $forumspace];

        $first = $this->save_with_instances($courseid, $sectionid, 1, [], [], 0, $twospaces);
        $firstid = (int) $first['id'];
        $countbefore = template_space::count_records(['templateid' => $firstid]);
        $this->assertSame(2, $countbefore);

        $pagespace = $this->space_payload('page', false, 'Only', 0, 0);
        $this->save_with_instances($courseid, $sectionid, 1, [], [], $firstid, [$pagespace]);

        $spaces = template_space::get_records(['templateid' => $firstid]);
        $this->assertCount(1, $spaces);
        $remaining = reset($spaces);
        $modname = $remaining->get('modname');
        $this->assertSame('page', $modname);
    }

    /**
     * A space for something that is not an installed activity type is rejected.
     */
    public function test_space_for_an_unknown_activity_type_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $sectionid = $this->first_section_id($course);
        $space = $this->space_payload('notamodule', true, '', 0, 0);

        $this->expectException(\invalid_parameter_exception::class);
        $this->save_with_instances((int) $course->id, $sectionid, 1, [], [], 0, [$space]);
    }

    /**
     * Deleting a template deletes its spaces too.
     */
    public function test_deleting_a_template_deletes_its_spaces(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course] = $this->create_course_fixture();
        $sectionid = $this->first_section_id($course);
        $space = $this->space_payload('resource', true, 'Gone soon', 0, 0);

        $saved = $this->save_with_instances((int) $course->id, $sectionid, 1, [], [], 0, [$space]);
        $templateid = (int) $saved['id'];
        $countbefore = template_space::count_records(['templateid' => $templateid]);
        $this->assertSame(1, $countbefore);

        \local_coursegen\external\delete_template::execute($templateid);

        $countafter = template_space::count_records(['templateid' => $templateid]);
        $this->assertSame(0, $countafter);
    }

    /**
     * The id of the first section after the general one.
     *
     * @param \stdClass $course
     * @return int
     */
    private function first_section_id(\stdClass $course): int {
        $modinfo = get_fast_modinfo($course);
        $section = $modinfo->get_section_info(1);
        return (int) $section->id;
    }

    /**
     * Render the sections review, optionally preselecting a saved template.
     *
     * @param \stdClass $course
     * @param int $templateid Saved template id, 0 for a fresh template.
     * @return string
     */
    private function render_review(\stdClass $course, int $templateid = 0): string {
        $modinfo = get_fast_modinfo($course);
        return sections_config::render($modinfo, $templateid);
    }

    /**
     * The text of a space badge for a requirement label.
     *
     * @param string $requirementkey 'template_space_required' or 'template_space_optional'.
     * @return string
     */
    private function badge_text(string $requirementkey): string {
        $requirement = get_string($requirementkey, 'local_coursegen');
        return get_string('template_space_badge', 'local_coursegen', $requirement);
    }

    /**
     * The opening tag of an element's markup, up to its first closing bracket.
     *
     * @param string $markup
     * @return string
     */
    private function opening_tag(string $markup): string {
        $end = strpos($markup, '>');
        return substr($markup, 0, $end);
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
        $before = substr($html, 0, $markerpos);
        $tagstart = strrpos($before, '<button');
        $tagend = strpos($html, '</button>', $tagstart);
        $length = $tagend + strlen('</button>') - $tagstart;
        return substr($html, $tagstart, $length);
    }
}
