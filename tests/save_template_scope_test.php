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

use local_coursegen\external\save_template;
use local_coursegen\local\models\template_activity;
use local_coursegen\output\sections_config;

/**
 * save_template's persistence of the new templatescope field: a valid
 * explicit value round-trips and hydrates back into the review, an omitted
 * value defaults to "course", and an invalid one is normalised to "course"
 * rather than trusted as-is.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\save_template
 *
 * @runTestsInSeparateProcesses
 */
final class save_template_scope_test extends \advanced_testcase {
    use sections_config_fixture_trait;

    /**
     * Saving a "template" action with an explicit "section" scope round
     * trips: the saved row persists both values, and re-rendering the
     * review preselects "template" plus reveals the scope select with
     * "This section only" preselected (not hidden, since the row's own
     * action is now "template").
     */
    public function test_persists_and_hydrates_template_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);

        $saved = save_template::execute(0, 'Mold template', '', (int) $course->id, 0, false, '[]', '', 1, [
            [
                'sectionid' => (int) $section1->id,
                'sectionnum' => 1,
                'behavior' => 'custom',
                'activities' => [
                    [
                        'cmid' => (int) $page->cmid,
                        'action' => 'template',
                        'useasreference' => true,
                        'prompt' => '',
                        'templatescope' => 'section',
                    ],
                ],
            ],
        ]);
        $templateid = (int) $saved['id'];

        $record = template_activity::get_record(['templateid' => $templateid, 'cmid' => (int) $page->cmid]);
        $this->assertNotFalse($record);
        $this->assertSame('template', $record->get('action'));
        $this->assertSame('section', $record->get('templatescope'));

        $html = sections_config::render($modinfo, $templateid);

        $pageselect = $this->extract_action_select($html, (int) $page->cmid);
        $this->assertMatchesRegularExpression('/<option value="template"[^>]*\sselected/', $pageselect);

        // The badge is now visible (no d-none) since this row IS a template.
        $badgestart = strpos($html, 'data-region="template-badge" data-id="' . $page->cmid . '"');
        $badgetagstart = strrpos(substr($html, 0, $badgestart), '<span');
        $badgetag = substr($html, $badgetagstart, $badgestart - $badgetagstart);
        $this->assertStringNotContainsString('d-none', $badgetag);

        $scopeselect = $this->extract_scope_select($html, (int) $page->cmid);
        $selecttagend = strpos($scopeselect, '>');
        $this->assertStringNotContainsString('d-none', substr($scopeselect, 0, $selecttagend));
        $this->assertMatchesRegularExpression('/<option value="section"[^>]*\sselected/', $scopeselect);
    }

    /**
     * Omitting templatescope entirely (relying on the external function's
     * own VALUE_DEFAULT) persists "course", not an empty/null value.
     */
    public function test_defaults_scope_to_course_when_omitted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);

        $saved = save_template::execute(0, 'No scope given', '', (int) $course->id, 0, false, '[]', '', 1, [
            [
                'sectionid' => (int) $section1->id,
                'sectionnum' => 1,
                'behavior' => 'custom',
                'activities' => [
                    ['cmid' => (int) $page->cmid, 'action' => 'template', 'useasreference' => true, 'prompt' => ''],
                ],
            ],
        ]);

        $record = template_activity::get_record(['templateid' => (int) $saved['id'], 'cmid' => (int) $page->cmid]);
        $this->assertSame('course', $record->get('templatescope'));
    }

    /**
     * An explicit but unrecognised scope value (still alphabetic, so it
     * passes PARAM_ALPHA validation) is normalised to "course" rather than
     * persisted as-is — the same fallback template_row_options applies when
     * rendering, but enforced here at the write path too.
     */
    public function test_normalises_an_invalid_scope_value_to_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);

        $saved = save_template::execute(0, 'Bogus scope', '', (int) $course->id, 0, false, '[]', '', 1, [
            [
                'sectionid' => (int) $section1->id,
                'sectionnum' => 1,
                'behavior' => 'custom',
                'activities' => [
                    [
                        'cmid' => (int) $page->cmid,
                        'action' => 'template',
                        'useasreference' => true,
                        'prompt' => '',
                        'templatescope' => 'bogus',
                    ],
                ],
            ],
        ]);

        $record = template_activity::get_record(['templateid' => (int) $saved['id'], 'cmid' => (int) $page->cmid]);
        $this->assertSame('course', $record->get('templatescope'));
    }

    /**
     * Scope on a row NOT marked "template" still persists whatever was
     * sent (normalised the same way) — the field is simply ignored by the
     * generator for any other action, no special-casing needed at save time.
     */
    public function test_persists_scope_even_when_action_is_not_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $page] = $this->create_course_fixture();
        $modinfo = get_fast_modinfo($course);
        $section1 = $modinfo->get_section_info(1);

        $saved = save_template::execute(0, 'Keep with scope', '', (int) $course->id, 0, false, '[]', '', 1, [
            [
                'sectionid' => (int) $section1->id,
                'sectionnum' => 1,
                'behavior' => 'custom',
                'activities' => [
                    [
                        'cmid' => (int) $page->cmid,
                        'action' => 'keep',
                        'useasreference' => true,
                        'prompt' => '',
                        'templatescope' => 'section',
                    ],
                ],
            ],
        ]);

        $record = template_activity::get_record(['templateid' => (int) $saved['id'], 'cmid' => (int) $page->cmid]);
        $this->assertSame('keep', $record->get('action'));
        $this->assertSame('section', $record->get('templatescope'));
    }
}
