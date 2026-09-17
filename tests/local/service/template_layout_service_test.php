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
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;

/**
 * Generated + kept activities follow the template's order; base section summaries carry over.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_layout_service
 * @covers     \local_coursegen\local\service\template_keep_copier
 * @covers     \local_coursegen\local\service\template_export_service
 */
final class template_layout_service_test extends \advanced_testcase {
    /**
     * A template over a base course whose section 1 holds mold T1, kept label K, mold T2.
     *
     * @return array{template: template, base: \stdClass, t1: int, k: int, t2: int, sectionid: int}
     */
    private function template_fixture(): array {
        $generator = $this->getDataGenerator();
        $base = $generator->create_course(['numsections' => 1]);
        $t1 = $generator->create_module('page', ['course' => $base->id, 'section' => 1, 'name' => 'Mold 1']);
        $k = $generator->create_module('label', ['course' => $base->id, 'section' => 1, 'name' => 'Kept label']);
        $t2 = $generator->create_module('page', ['course' => $base->id, 'section' => 1, 'name' => 'Mold 2']);
        $sectionid = (int) get_fast_modinfo($base)->get_section_info(1)->id;

        $template = new template(0, (object) [
            'name' => 'Layout template', 'description' => '', 'courseid' => $base->id,
            'nolimit' => 1, 'allowedtypes' => '[]', 'namingpattern' => '', 'namingstart' => 1,
        ]);
        $template->create();
        $templateid = (int) $template->get('id');
        foreach ([[$t1->cmid, 'template'], [$k->cmid, 'keep'], [$t2->cmid, 'template']] as [$cmid, $action]) {
            (new template_activity(0, (object) [
                'templateid' => $templateid, 'sectionid' => $sectionid, 'cmid' => $cmid,
                'action' => $action, 'useasreference' => 1, 'prompt' => '',
            ]))->create();
        }

        return [
            'template' => $template, 'base' => $base,
            't1' => (int) $t1->cmid, 'k' => (int) $k->cmid, 't2' => (int) $t2->cmid, 'sectionid' => $sectionid,
        ];
    }

    /**
     * Save one virtual instance anchored after a real cmid.
     *
     * @param template $template
     * @param int $sectionid
     * @param int $sourcecmid
     * @param int $aftercmid
     * @param string $name
     * @return int The instance id.
     */
    private function add_instance(template $template, int $sectionid, int $sourcecmid, int $aftercmid, string $name): int {
        $instance = new template_instance(0, (object) [
            'templateid' => $template->get('id'), 'sectionid' => $sectionid, 'sourcecmid' => $sourcecmid,
            'sourcename' => 'Mold', 'name' => $name, 'typelabel' => 'Page', 'modname' => 'page',
            'aftercmid' => $aftercmid, 'sortorder' => 0,
        ]);
        $instance->create();
        return (int) $instance->get('id');
    }

    /**
     * Section 1's sequence of a course.
     *
     * @param \stdClass $course
     * @return int[]
     */
    private function sequence(\stdClass $course): array {
        $section = get_fast_modinfo($course->id)->get_section_info(1);
        return array_map('intval', array_filter(explode(',', (string) $section->sequence)));
    }

    /**
     * A kept activity sitting between two molds ends up between their generated instances.
     */
    public function test_kept_activity_between_two_molds_is_placed_between_their_instances(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fixture = $this->template_fixture();
        $i1 = $this->add_instance($fixture['template'], $fixture['sectionid'], $fixture['t1'], $fixture['t1'], 'Gen 1');
        $i2 = $this->add_instance($fixture['template'], $fixture['sectionid'], $fixture['t2'], $fixture['t2'], 'Gen 2');

        // Generation appends the generated activities first, then the kept copy.
        $generator = $this->getDataGenerator();
        $target = $generator->create_course(['numsections' => 1]);
        $g1 = $generator->create_module('page', ['course' => $target->id, 'section' => 1, 'name' => 'Gen 1']);
        $g2 = $generator->create_module('page', ['course' => $target->id, 'section' => 1, 'name' => 'Gen 2']);
        $kept = template_keep_copier::copy_into((int) $fixture['template']->get('id'), (int) $target->id);
        $this->assertSame([$fixture['k']], array_keys($kept));
        $this->assertSame([(int) $g1->cmid, (int) $g2->cmid, $kept[$fixture['k']]], $this->sequence($target));

        template_layout_service::apply(
            (int) $fixture['template']->get('id'),
            (int) $target->id,
            [$i1 => (int) $g1->cmid, $i2 => (int) $g2->cmid],
            $kept
        );

        $this->assertSame([(int) $g1->cmid, $kept[$fixture['k']], (int) $g2->cmid], $this->sequence($target));
    }

    /**
     * The synthetic cmid of an instance in the init payload maps back to that instance.
     */
    public function test_export_gives_each_instance_a_cmid_that_maps_back_to_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fixture = $this->template_fixture();
        $i1 = $this->add_instance($fixture['template'], $fixture['sectionid'], $fixture['t1'], $fixture['t1'], 'Gen 1');

        $payload = template_export_service::build_init_payload((int) $fixture['template']->get('id'));

        $instances = array_values(array_filter($payload['activities'], static function (array $activity): bool {
            return ($activity['template_behavior']['template_source_cmid'] ?? 0) > 0;
        }));
        $this->assertCount(1, $instances);
        $this->assertSame($i1, template_export_service::instance_id_of((int) $instances[0]['cmid']));
        $this->assertNull(template_export_service::instance_id_of($fixture['t1']));
    }

    /**
     * A payload cmid maps to a saved instance only when it names an instance of this template.
     */
    public function test_payload_cmids_map_to_instances_of_this_template_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fixture = $this->template_fixture();
        $templateid = (int) $fixture['template']->get('id');
        $i1 = $this->add_instance($fixture['template'], $fixture['sectionid'], $fixture['t1'], $fixture['t1'], 'Gen 1');
        $other = $this->template_fixture();
        $foreign = $this->add_instance($other['template'], $other['sectionid'], $other['t1'], $other['t1'], 'Foreign');

        $base = template_export_service::INSTANCE_CMID_BASE;
        // A kept real cmid echoed back is ignored; so is an instance of another template or an unknown one.
        $this->assertNull(template_layout_service::instance_id_for($fixture['k'], $templateid));
        $this->assertNull(template_layout_service::instance_id_for($base + $foreign, $templateid));
        $this->assertNull(template_layout_service::instance_id_for($base + 123456, $templateid));
        $this->assertNull(template_layout_service::instance_id_for($base + $i1, 0));
        $this->assertSame($i1, template_layout_service::instance_id_for($base + $i1, $templateid));

        $this->assertSame(
            [$i1 => 501],
            template_layout_service::generated_by_instance([
                $fixture['k'] => 500,
                $base + $i1 => 501,
                $base + $foreign => 502,
            ], $templateid)
        );
    }

    /**
     * A REAL course_modules id that numerically lands at or above
     * INSTANCE_CMID_BASE (a mature site can reach that id range) must never
     * be treated as a synthetic instance id, even when its offset happens to
     * collide with a real template_instance id of the very template being
     * generated - the mapping must be rejected outright, not merely by the
     * template_instance lookup.
     */
    public function test_a_real_course_module_id_past_the_instance_base_is_never_treated_as_an_instance(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $fixture = $this->template_fixture();
        $templateid = (int) $fixture['template']->get('id');
        $i1 = $this->add_instance($fixture['template'], $fixture['sectionid'], $fixture['t1'], $fixture['t1'], 'Gen 1');

        $base = template_export_service::INSTANCE_CMID_BASE;
        $collidingcmid = $base + $i1;

        // Force a real course_modules row to exist at exactly that id, as a
        // long-lived site's auto-increment eventually would - inserting one
        // row directly with that id is the only practical way to reach it in
        // a test (creating that many real modules is not).
        $label = $DB->get_record('modules', ['name' => 'label'], '*', MUST_EXIST);
        $labelinstance = $DB->insert_record('label', (object) [
            'course' => $fixture['base']->id, 'name' => 'Real module at the collision id',
            'intro' => '', 'introformat' => 1, 'timemodified' => time(),
        ]);
        $DB->import_record('course_modules', (object) [
            'id' => $collidingcmid, 'course' => $fixture['base']->id, 'module' => $label->id,
            'instance' => $labelinstance, 'section' => 0, 'added' => time(), 'visible' => 1,
        ]);
        // Move the id sequence past our manually-inserted row so later
        // inserts in the same test run never collide with it.
        $DB->get_manager()->reset_sequence('course_modules');

        $this->assertNull(
            template_layout_service::instance_id_for($collidingcmid, $templateid),
            'a real course-module id must never resolve to an instance, even if its offset collides'
        );
        $this->assertSame(
            [],
            template_layout_service::generated_by_instance([$collidingcmid => 999], $templateid)
        );
    }

    /**
     * A section saved with behavior "exclude" never receives the base summary.
     */
    public function test_excluded_section_summary_is_not_copied(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $fixture = $this->template_fixture();
        $base = $fixture['base'];
        $basesection = get_fast_modinfo($base)->get_section_info(1);
        $DB->set_field('course_sections', 'summary', '<p>Base summary</p>', ['id' => $basesection->id]);
        (new template_section(0, (object) [
            'templateid' => $fixture['template']->get('id'), 'sectionid' => $basesection->id,
            'sectionnum' => 1, 'behavior' => 'exclude',
        ]))->create();
        rebuild_course_cache($base->id, true);

        $target = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $targetsection = get_fast_modinfo($target)->get_section_info(1);
        $this->assertSame('', (string) $targetsection->summary);

        template_layout_service::apply((int) $fixture['template']->get('id'), (int) $target->id, [], []);

        $this->assertSame('', (string) $DB->get_field('course_sections', 'summary', ['id' => $targetsection->id]));
    }

    /**
     * An aimodify section without a summary receives the base section's summary and its files.
     */
    public function test_copies_base_section_summary_and_files_when_target_has_none(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $fixture = $this->template_fixture();
        $base = $fixture['base'];
        $basesection = get_fast_modinfo($base)->get_section_info(1);
        $DB->update_record('course_sections', (object) [
            'id' => $basesection->id,
            'summary' => '<p><img src="@@PLUGINFILE@@/banner.png"></p>',
            'summaryformat' => FORMAT_HTML,
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_course::instance($base->id)->id, 'component' => 'course', 'filearea' => 'section',
            'itemid' => $basesection->id, 'filepath' => '/', 'filename' => 'banner.png',
        ], 'BANNER');
        (new template_section(0, (object) [
            'templateid' => $fixture['template']->get('id'), 'sectionid' => $basesection->id,
            'sectionnum' => 1, 'behavior' => 'aimodify',
        ]))->create();
        rebuild_course_cache($base->id, true);

        $target = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $targetsection = get_fast_modinfo($target)->get_section_info(1);
        $DB->set_field('course_sections', 'summary', '<p>Kept by the AI</p>', ['id' => $targetsection->id]);
        rebuild_course_cache($target->id, true);

        template_layout_service::apply((int) $fixture['template']->get('id'), (int) $target->id, [], []);

        // Section 1 already had a summary: left alone.
        $this->assertSame(
            '<p>Kept by the AI</p>',
            $DB->get_field('course_sections', 'summary', ['id' => $targetsection->id])
        );

        // Now without one: the base summary and its file are copied over.
        $DB->set_field('course_sections', 'summary', '', ['id' => $targetsection->id]);
        rebuild_course_cache($target->id, true);
        template_layout_service::apply((int) $fixture['template']->get('id'), (int) $target->id, [], []);

        $copied = $DB->get_record('course_sections', ['id' => $targetsection->id], '*', MUST_EXIST);
        $this->assertSame('<p><img src="@@PLUGINFILE@@/banner.png"></p>', $copied->summary);
        $this->assertSame((int) FORMAT_HTML, (int) $copied->summaryformat);
        $file = get_file_storage()->get_file(
            \context_course::instance($target->id)->id, 'course', 'section', $targetsection->id, '/', 'banner.png'
        );
        $this->assertNotFalse($file);
        $this->assertSame('BANNER', $file->get_content());
    }
}
