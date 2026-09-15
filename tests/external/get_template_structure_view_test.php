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

use local_coursegen\external\get_template_structure;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;

defined('MOODLE_INTERNAL') || die();

// The shared fixture trait lives one level up, outside this directory's
// autoload scope.
require_once(__DIR__ . '/../sections_config_fixture_trait.php');

/**
 * The professor-facing view of a saved template (get_template_structure)
 * must mirror the admin's configuration exactly:
 *
 * - action mapping: keep / unset / legacy "modify" are visible and locked;
 *   reference, template (mold) and exclude rows are not returned at all;
 * - virtual instances (local_coursegen_tpl_instance) are interleaved among
 *   the visible real activities at their saved anchor/sortorder positions,
 *   each returned as a locked, AI-generated row with a stable NEGATIVE id
 *   (-recordid, so it can never collide with a real cmid);
 * - an instance anchored to a row the professor cannot see (hidden action,
 *   or not uservisible) keeps its anchor's slot: it renders exactly where
 *   the hidden anchor would have been (immediately after the nearest
 *   preceding visible row, or at the section start when there is none);
 *   an anchor that matches no cmid in the section appends at the end;
 * - per-section behavior and per-activity action/isinstance are exposed as
 *   backward-compatible additions to execute_returns().
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_template_structure
 *
 * @runTestsInSeparateProcesses
 */
final class get_template_structure_view_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * Create a bare template record for a course, bypassing save_template —
     * these tests exercise the read side only, so the fixture writes the
     * exact rows under test straight through the models.
     *
     * @param int $courseid
     * @return int The new template id.
     */
    private function make_template(int $courseid): int {
        $template = new template(0, (object) ['name' => 'Professor view fixture', 'courseid' => $courseid]);
        $template->create();
        return (int) $template->get('id');
    }

    /**
     * Save one per-activity action row.
     *
     * @param int $templateid
     * @param int $sectionid
     * @param int $cmid
     * @param string $action
     */
    private function add_activity_action(int $templateid, int $sectionid, int $cmid, string $action): void {
        (new template_activity(0, (object) [
            'templateid' => $templateid,
            'sectionid' => $sectionid,
            'cmid' => $cmid,
            'action' => $action,
        ]))->create();
    }

    /**
     * Save one per-section behavior row.
     *
     * @param int $templateid
     * @param int $sectionid
     * @param int $sectionnum
     * @param string $behavior
     */
    private function add_section_behavior(int $templateid, int $sectionid, int $sectionnum, string $behavior): void {
        (new template_section(0, (object) [
            'templateid' => $templateid,
            'sectionid' => $sectionid,
            'sectionnum' => $sectionnum,
            'behavior' => $behavior,
        ]))->create();
    }

    /**
     * Save one virtual instance row.
     *
     * @param int $templateid
     * @param int $sectionid
     * @param array $overrides Field overrides.
     * @return template_instance
     */
    private function add_instance(int $templateid, int $sectionid, array $overrides = []): template_instance {
        $data = array_merge([
            'templateid' => $templateid,
            'sectionid' => $sectionid,
            'sourcecmid' => 1,
            'sourcename' => 'Source template',
            'name' => 'Generated activity',
            'typelabel' => 'Forum',
            'modname' => 'forum',
            'prompt' => '',
            'anchorcmid' => 0,
            'sortorder' => 0,
        ], $overrides);
        $instance = new template_instance(0, (object) $data);
        $instance->create();
        return $instance;
    }

    /**
     * Find one returned section by its base-course section id.
     *
     * @param array $result get_template_structure::execute() return value.
     * @param int $sectionid
     * @return array
     */
    private function section_by_id(array $result, int $sectionid): array {
        foreach ($result['sections'] as $section) {
            if ((int) $section['id'] === $sectionid) {
                return $section;
            }
        }
        $this->fail('Section not found in response: ' . $sectionid);
    }

    /**
     * The returned activity names of one section, in render order.
     *
     * @param array $section One entry of the response's sections array.
     * @return string[]
     */
    private function activity_names(array $section): array {
        return array_map(static fn($activity) => $activity['name'], $section['activities']);
    }

    /**
     * Rows saved as reference, template (mold) or exclude are not returned
     * at all — the professor only sees what the generated course will
     * actually keep.
     */
    public function test_reference_template_and_exclude_rows_are_hidden(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, $forum, $lti, $label] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);
        $section2 = $modinfo->get_section_info(2);

        $templateid = $this->make_template((int) $course->id);
        $this->add_activity_action($templateid, (int) $section1->id, (int) $page->cmid, 'reference');
        $this->add_activity_action($templateid, (int) $section2->id, (int) $forum->cmid, 'template');
        $this->add_activity_action($templateid, (int) $section2->id, (int) $lti->cmid, 'exclude');

        $result = get_template_structure::execute($templateid);

        $this->assertSame([], $this->section_by_id($result, (int) $section1->id)['activities']);
        // Only the label (no saved action at all) survives in section 2.
        $this->assertSame(
            [format_string($label->name)],
            $this->activity_names($this->section_by_id($result, (int) $section2->id))
        );
    }

    /**
     * A legacy saved "modify" and a row with no saved action both resolve
     * to keep: visible, locked, and reported as action "keep".
     */
    public function test_legacy_modify_and_unset_actions_are_visible_as_keep(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page, , , $label] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);
        $section2 = $modinfo->get_section_info(2);

        $templateid = $this->make_template((int) $course->id);
        $this->add_activity_action($templateid, (int) $section1->id, (int) $page->cmid, 'modify');

        $result = get_template_structure::execute($templateid);

        $pagerow = $this->section_by_id($result, (int) $section1->id)['activities'][0] ?? null;
        $this->assertNotNull($pagerow, 'Legacy modify row must stay visible');
        $this->assertSame(format_string($page->name), $pagerow['name']);
        $this->assertSame('keep', $pagerow['action']);
        $this->assertTrue($pagerow['locked']);

        $labelrow = null;
        foreach ($this->section_by_id($result, (int) $section2->id)['activities'] as $activity) {
            if ($activity['name'] === format_string($label->name)) {
                $labelrow = $activity;
            }
        }
        $this->assertNotNull($labelrow, 'Unset-action row must stay visible');
        $this->assertSame('keep', $labelrow['action']);
        $this->assertTrue($labelrow['locked']);
    }

    /**
     * Instances interleave among the visible real rows at their saved
     * positions: anchor 0 renders at the section start, anchored instances
     * render right after their anchor, and sortorder (not creation order)
     * breaks ties on a shared anchor. The activity list length — what the
     * client's "N activities" count reads — includes them.
     */
    public function test_instances_interleave_with_visible_activities(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, , $forum, $lti, $label] = $this->create_course_fixture();
        $section2 = get_fast_modinfo($course)->get_section_info(2);

        $templateid = $this->make_template((int) $course->id);
        $this->add_instance($templateid, (int) $section2->id, ['name' => 'At start', 'anchorcmid' => 0]);
        // Created second-after-forum FIRST to prove sortorder wins over ids.
        $this->add_instance($templateid, (int) $section2->id, [
            'name' => 'Second after forum', 'anchorcmid' => (int) $forum->cmid, 'sortorder' => 2,
        ]);
        $this->add_instance($templateid, (int) $section2->id, [
            'name' => 'First after forum', 'anchorcmid' => (int) $forum->cmid, 'sortorder' => 1,
        ]);

        $result = get_template_structure::execute($templateid);
        $section = $this->section_by_id($result, (int) $section2->id);

        $this->assertSame([
            'At start',
            format_string($forum->name),
            'First after forum',
            'Second after forum',
            format_string($lti->name),
            format_string($label->name),
        ], $this->activity_names($section));
        $this->assertCount(6, $section['activities']);
    }

    /**
     * An instance anchored to a row the professor cannot see (here: its
     * anchor is the mold activity itself, action=template) keeps its
     * anchor's slot — it renders exactly where the hidden anchor would
     * have been, not at the section end.
     */
    public function test_instance_anchored_to_hidden_row_takes_anchor_slot(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, , $forum, $lti, $label] = $this->create_course_fixture();
        $section2 = get_fast_modinfo($course)->get_section_info(2);

        $templateid = $this->make_template((int) $course->id);
        $this->add_activity_action($templateid, (int) $section2->id, (int) $forum->cmid, 'template');
        $this->add_instance($templateid, (int) $section2->id, [
            'name' => 'Molded on forum', 'anchorcmid' => (int) $forum->cmid,
        ]);

        $result = get_template_structure::execute($templateid);

        // Forum is first in the section, so its instance takes the section
        // start once the mold row itself disappears.
        $this->assertSame([
            'Molded on forum',
            format_string($lti->name),
            format_string($label->name),
        ], $this->activity_names($this->section_by_id($result, (int) $section2->id)));
    }

    /**
     * An instance whose anchorcmid matches nothing in the section (the base
     * activity was deleted) appends at the section end instead of being
     * dropped — same rule the admin review follows.
     */
    public function test_instance_with_orphan_anchor_appends_at_section_end(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, , $forum, $lti, $label] = $this->create_course_fixture();
        $section2 = get_fast_modinfo($course)->get_section_info(2);

        $templateid = $this->make_template((int) $course->id);
        $this->add_instance($templateid, (int) $section2->id, [
            'name' => 'Orphaned instance', 'anchorcmid' => 999999,
        ]);

        $result = get_template_structure::execute($templateid);

        $this->assertSame([
            format_string($forum->name),
            format_string($lti->name),
            format_string($label->name),
            'Orphaned instance',
        ], $this->activity_names($this->section_by_id($result, (int) $section2->id)));
    }

    /**
     * Instance rows carry every field the client renders from — a stable
     * negative id (-recordid, so it can never collide with a cmid), the
     * isinstance/aigenerated flags, the snapshot name/typelabel/modname,
     * locked, a monologo icon resolved from the snapshot modname (empty
     * when the snapshot has none) — and the whole payload survives
     * external_api::clean_returnvalue, proving execute_returns() declares
     * the new fields.
     */
    public function test_instance_row_fields_and_id_scheme(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $templateid = $this->make_template((int) $course->id);
        $instance = $this->add_instance($templateid, (int) $section1->id, [
            'name' => 'Discussion 1', 'typelabel' => 'Forum', 'modname' => 'forum', 'anchorcmid' => 0,
        ]);
        $this->add_instance($templateid, (int) $section1->id, [
            'name' => 'No icon', 'typelabel' => 'Page', 'modname' => null, 'anchorcmid' => (int) $page->cmid,
        ]);

        $result = get_template_structure::execute($templateid);
        $result = \external_api::clean_returnvalue(get_template_structure::execute_returns(), $result);

        $activities = $this->section_by_id($result, (int) $section1->id)['activities'];
        $this->assertSame(['Discussion 1', format_string($page->name), 'No icon'], array_column($activities, 'name'));

        $row = $activities[0];
        $this->assertSame(-((int) $instance->get('id')), $row['id']);
        $this->assertTrue($row['isinstance']);
        $this->assertTrue($row['aigenerated']);
        $this->assertTrue($row['locked']);
        $this->assertSame('Forum', $row['typelabel']);
        $this->assertSame('forum', $row['modname']);
        $this->assertSame('collaboration', $row['purpose']);
        $this->assertStringContainsString('forum', $row['iconhtml']);

        $pagerow = $activities[1];
        $this->assertFalse($pagerow['isinstance']);
        $this->assertFalse($pagerow['aigenerated']);
        $this->assertSame('keep', $pagerow['action']);
        $this->assertSame('', $pagerow['typelabel']);

        $noiconrow = $activities[2];
        $this->assertSame('', $noiconrow['modname']);
        $this->assertSame('', $noiconrow['iconhtml'], 'A missing modname snapshot must yield no icon, not a broken one');
        $this->assertSame('other', $noiconrow['purpose']);
    }

    /**
     * Per-section behavior is exposed; a kept section is locked and its
     * rows — instances included — all come back locked, so the admin's
     * configuration mirrors faithfully into the professor view.
     */
    public function test_section_behavior_is_exposed_and_kept_sections_lock_everything(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);
        $section2 = $modinfo->get_section_info(2);

        $templateid = $this->make_template((int) $course->id);
        $this->add_section_behavior($templateid, (int) $section1->id, 1, 'keep');
        $this->add_instance($templateid, (int) $section1->id, [
            'name' => 'Kept-section instance', 'anchorcmid' => (int) $page->cmid,
        ]);

        $result = get_template_structure::execute($templateid);

        $kept = $this->section_by_id($result, (int) $section1->id);
        $this->assertSame('keep', $kept['behavior']);
        $this->assertTrue($kept['locked']);
        $this->assertSame(
            [format_string($page->name), 'Kept-section instance'],
            $this->activity_names($kept)
        );
        foreach ($kept['activities'] as $activity) {
            $this->assertTrue($activity['locked']);
        }

        $custom = $this->section_by_id($result, (int) $section2->id);
        $this->assertSame('custom', $custom['behavior']);
        $this->assertFalse($custom['locked']);
    }
}
