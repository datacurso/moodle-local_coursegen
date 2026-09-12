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

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_section;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\service\course_export_service;

/**
 * Data-shaping contract of course_export_service's exclusion parameters and
 * export_course_for_template(): the pure, no-live-AI-needed logic that merges
 * a template's own section/activity behavior (and general instruction/files)
 * into a plain course export.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\course_export_service
 */
final class course_export_service_test extends \advanced_testcase {
    /**
     * Create a course with numsections sections plus one mod_page in each
     * section from 1..numsections, and return course + section ids + cmids.
     *
     * @param int $numsections Number of content sections (section 0 excluded).
     * @return array{course:\stdClass,sectionids:array<int,int>,cmids:array<int,int>}
     */
    private function create_course_with_pages(int $numsections): array {
        $course = $this->getDataGenerator()->create_course(['numsections' => $numsections]);

        global $DB;
        $sectionids = [];
        $cmids = [];
        for ($sectionnum = 1; $sectionnum <= $numsections; $sectionnum++) {
            $sectionids[$sectionnum] = (int)$DB->get_field(
                'course_sections',
                'id',
                ['course' => $course->id, 'section' => $sectionnum],
                MUST_EXIST
            );
            $page = $this->getDataGenerator()->create_module('page', [
                'course' => $course->id,
                'section' => $sectionnum,
                'name' => 'Page ' . $sectionnum,
            ]);
            $cmids[$sectionnum] = (int)$page->cmid;
        }

        return ['course' => $course, 'sectionids' => $sectionids, 'cmids' => $cmids];
    }

    /**
     * Create a template persistent for the given base course.
     *
     * @param int $courseid Base course id.
     * @param string|null $generalinstruction Optional general_instruction value.
     * @return template
     */
    private function create_template(int $courseid, ?string $generalinstruction = null): template {
        $template = new template(0, (object)[
            'name' => 'Export test template',
            'courseid' => $courseid,
            'nolimit' => 1,
            'allowedtypes' => json_encode(['page']),
            'general_instruction' => $generalinstruction,
        ]);
        $template->create();
        return $template;
    }

    /**
     * A section marked behavior=exclude, and every activity it contains, are
     * absent from export_course_for_template()'s output.
     */
    public function test_excluded_section_and_its_activities_are_absent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_course_with_pages(2);
        $template = $this->create_template((int)$fixture['course']->id);

        $excludedsection = new template_section(0, (object)[
            'templateid' => (int)$template->get('id'),
            'sectionid' => $fixture['sectionids'][1],
            'sectionnum' => 1,
            'behavior' => 'exclude',
        ]);
        $excludedsection->create();

        $export = course_export_service::export_course_for_template($template);

        $sectionnums = array_column($export['sections_info'], 'section');
        $this->assertNotContains(1, $sectionnums);
        $this->assertContains(2, $sectionnums);

        $activitynames = array_column(array_column($export['activities'], 'parameters'), 'name');
        $this->assertNotContains('Page 1', $activitynames);
        $this->assertContains('Page 2', $activitynames);
    }

    /**
     * An activity marked action=exclude inside an otherwise-included
     * (behavior=custom) section is absent, but its section still appears.
     */
    public function test_excluded_activity_inside_included_section_is_absent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_course_with_pages(1);
        $template = $this->create_template((int)$fixture['course']->id);

        $section = new template_section(0, (object)[
            'templateid' => (int)$template->get('id'),
            'sectionid' => $fixture['sectionids'][1],
            'sectionnum' => 1,
            'behavior' => 'custom',
        ]);
        $section->create();

        $activity = new template_activity(0, (object)[
            'templateid' => (int)$template->get('id'),
            'sectionid' => $fixture['sectionids'][1],
            'cmid' => $fixture['cmids'][1],
            'action' => 'exclude',
            'useasreference' => 0,
        ]);
        $activity->create();

        $export = course_export_service::export_course_for_template($template);

        $sectionnums = array_column($export['sections_info'], 'section');
        $this->assertContains(1, $sectionnums);
        $this->assertSame([], $export['activities']);
    }

    /**
     * Included sections/activities carry their configured template_behavior.
     */
    public function test_included_entries_carry_configured_template_behavior(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_course_with_pages(1);
        $template = $this->create_template((int)$fixture['course']->id);

        $section = new template_section(0, (object)[
            'templateid' => (int)$template->get('id'),
            'sectionid' => $fixture['sectionids'][1],
            'sectionnum' => 1,
            'behavior' => 'keep',
        ]);
        $section->create();

        $activity = new template_activity(0, (object)[
            'templateid' => (int)$template->get('id'),
            'sectionid' => $fixture['sectionids'][1],
            'cmid' => $fixture['cmids'][1],
            'action' => 'reference',
            'useasreference' => 1,
            'prompt' => 'Use as tone reference',
        ]);
        $activity->create();

        $export = course_export_service::export_course_for_template($template);

        $section1 = $this->find_section($export, 1);
        $this->assertSame('keep', $section1['template_behavior']['behavior']);

        $this->assertCount(1, $export['activities']);
        $behavior = $export['activities'][0]['template_behavior'];
        $this->assertSame('reference', $behavior['action']);
        $this->assertTrue($behavior['useasreference']);
        $this->assertSame('Use as tone reference', $behavior['prompt']);
    }

    /**
     * Find one sections_info entry by its section number.
     *
     * @param array $export export_course()/export_course_for_template() result.
     * @param int $sectionnum Section number to find.
     * @return array
     */
    private function find_section(array $export, int $sectionnum): array {
        foreach ($export['sections_info'] as $entry) {
            if ((int)$entry['section'] === $sectionnum) {
                return $entry;
            }
        }
        $this->fail('Section ' . $sectionnum . ' not found in export.');
    }

    /**
     * A section/activity with no template_section/template_activity row at
     * all falls back to that persistent's own defaults (custom / modify),
     * never to exclude.
     */
    public function test_missing_rows_default_to_custom_and_modify_never_exclude(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_course_with_pages(1);
        $template = $this->create_template((int)$fixture['course']->id);

        // Deliberately no template_section/template_activity rows created.
        $export = course_export_service::export_course_for_template($template);

        $section1 = $this->find_section($export, 1);
        $this->assertSame('custom', $section1['template_behavior']['behavior']);

        $this->assertCount(1, $export['activities']);
        $behavior = $export['activities'][0]['template_behavior'];
        $this->assertSame('modify', $behavior['action']);
        $this->assertTrue($behavior['useasreference']);
        $this->assertNull($behavior['prompt']);
    }

    /**
     * general_instruction is exposed verbatim when set on the template, and
     * null when it was never set.
     */
    public function test_general_instruction_present_when_set_and_null_otherwise(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_course_with_pages(1);

        $withinstruction = $this->create_template((int)$fixture['course']->id, 'Keep a friendly tone throughout.');
        $export = course_export_service::export_course_for_template($withinstruction);
        $this->assertSame('Keep a friendly tone throughout.', $export['general_instruction']);

        $withoutinstruction = $this->create_template((int)$fixture['course']->id);
        $export = course_export_service::export_course_for_template($withoutinstruction);
        $this->assertNull($export['general_instruction']);
    }

    /**
     * general_reference_files reflects files actually uploaded to the
     * template_general_files filearea for that template's own itemid, and is
     * empty when none exist.
     */
    public function test_general_reference_files_reflects_uploaded_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_course_with_pages(1);
        $template = $this->create_template((int)$fixture['course']->id);
        $templateid = (int)$template->get('id');

        $export = course_export_service::export_course_for_template($template);
        $this->assertSame([], $export['general_reference_files']);
        $this->assertSame([], course_export_service::get_general_reference_files());

        $fs = get_file_storage();
        $file = $fs->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_coursegen',
            'filearea' => 'template_general_files',
            'itemid' => $templateid,
            'filepath' => '/',
            'filename' => 'reference.txt',
            'mimetype' => 'text/plain',
        ], 'Reference content');

        $export = course_export_service::export_course_for_template($template);

        $this->assertCount(1, $export['general_reference_files']);
        $this->assertSame('reference.txt', $export['general_reference_files'][0]['filename']);
        $this->assertSame($file->get_contenthash(), $export['general_reference_files'][0]['contenthash']);

        $collected = course_export_service::get_general_reference_files();
        $this->assertCount(1, $collected);
        $this->assertSame('reference.txt', $collected[0]->get_filename());
    }

    /**
     * The extended export_course() exclusion parameters, exercised directly
     * (no template involved): an excluded section number and an excluded
     * cmid are both left out, everything else is unaffected.
     */
    public function test_export_course_exclusion_parameters_in_isolation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_course_with_pages(2);

        $export = course_export_service::export_course(
            (int)$fixture['course']->id,
            [1],
            [$fixture['cmids'][2]]
        );

        $sectionnums = array_column($export['sections_info'], 'section');
        $this->assertNotContains(1, $sectionnums);
        $this->assertContains(2, $sectionnums);
        // Section 2 is included, but its own activity was excluded by cmid.
        $this->assertSame([], $export['activities']);
    }

    /**
     * Without exclusions, export_course() behaves exactly as before
     * (existing callers/behavior unaffected by the new optional parameters).
     */
    public function test_export_course_without_exclusions_is_unaffected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_course_with_pages(2);

        $export = course_export_service::export_course((int)$fixture['course']->id);

        $sectionnums = array_column($export['sections_info'], 'section');
        $this->assertContains(1, $sectionnums);
        $this->assertContains(2, $sectionnums);
        $this->assertCount(2, $export['activities']);
    }
}
