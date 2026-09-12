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

use local_coursegen\form\template_config_form;
use local_coursegen\local\models\template;

/**
 * Limits section contract of the template config form.
 *
 * The "Generated course limits" section offers an "Allow the teacher to add
 * sections" checkbox (unchecked by default) plus a number-of-extra-sections
 * field that KEEPS the maxsections name (so the save payload stays stable)
 * and defaults to 1 — no longer the base course's own section count — with
 * the old "No limit" checkbox gone.
 *
 * When a "templateid" arg accompanies "courseid" (edit mode), every field
 * prefills from the saved template instead of the fresh-course defaults.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\form\template_config_form
 */
final class template_config_form_defaults_test extends \advanced_testcase {
    /**
     * Render the form for a course with the given number of sections.
     *
     * @param int $numsections Base course section count.
     * @return string Rendered form HTML.
     */
    private function render_form(int $numsections): string {
        $course = $this->getDataGenerator()->create_course(['numsections' => $numsections]);
        $form = new template_config_form(null, null, 'post', '', [], true, [
            'courseid' => (int) $course->id,
        ]);
        return $form->render();
    }

    /**
     * The limits section offers allowaddsections + maxsections and no nolimit checkbox.
     */
    public function test_limits_section_offers_allow_add_sections_toggle(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_form(3);

        $this->assertStringContainsString('name="allowaddsections"', $html);
        $this->assertStringContainsString(
            get_string('template_allow_add_sections', 'local_coursegen'),
            $html
        );
        $this->assertStringNotContainsString('name="nolimit"', $html);
    }

    /**
     * maxsections defaults to 1 extra section, not the base course's section count.
     */
    public function test_extra_sections_field_defaults_to_one(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_form(3);

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="maxsections"[^>]*value="1"/',
            $html
        );
        $this->assertStringContainsString(
            get_string('template_extra_sections', 'local_coursegen'),
            $html
        );
    }


    /**
     * Create a saved template row for the given course.
     *
     * @param int $courseid Base course id.
     * @param array $overrides Field values overriding the fixture defaults.
     * @return template The created template.
     */
    private function create_template(int $courseid, array $overrides = []): template {
        $tpl = new template(0);
        $tpl->set('name', 'Saved template');
        $tpl->set('courseid', $courseid);
        foreach ($overrides as $field => $value) {
            $tpl->set($field, $value);
        }
        $tpl->create();
        return $tpl;
    }

    /**
     * Render the form in edit mode (courseid + templateid args).
     *
     * @param int $courseid Base course id.
     * @param int $templateid Saved template id.
     * @return string Rendered form HTML.
     */
    private function render_form_for_template(int $courseid, int $templateid): string {
        $form = new template_config_form(null, null, 'post', '', [], true, [
            'courseid' => $courseid,
            'templateid' => $templateid,
        ]);
        return $form->render();
    }

    /**
     * Extract one named select's markup so option assertions cannot match a
     * different select's options.
     *
     * @param string $html Rendered form.
     * @param string $name The select's name attribute.
     * @return string The select markup, without the closing tag.
     */
    private function extract_select(string $html, string $name): string {
        $marker = 'name="' . $name . '"';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, 'No select rendered with name ' . $name);
        $end = strpos($html, '</select>', $start);
        return substr($html, $start, $end - $start);
    }

    /**
     * Edit mode prefills every field from the saved template: extra sections
     * enabled with the saved count, the saved allowed types selected, and a
     * custom naming pattern round-tripping back into the Custom option plus
     * its text field.
     */
    public function test_form_prefills_from_saved_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $tpl = $this->create_template((int) $course->id, [
            'maxsections' => 4,
            'allowedtypes' => json_encode(['page', 'forum']),
            'namingpattern' => 'Chapter {N} - {nombre}',
            'namingstart' => 0,
        ]);

        $html = $this->render_form_for_template((int) $course->id, (int) $tpl->get('id'));

        // Extra sections were allowed: checkbox ticked, saved count restored.
        $this->assertMatchesRegularExpression('/<input[^>]*name="allowaddsections"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="maxsections"[^>]*value="4"/', $html);

        // The SAVED allowed types are selected — not the course-present preset.
        $allowedselect = $this->extract_select($html, 'allowedtypes[]');
        $this->assertMatchesRegularExpression('/<option[^>]*value="page"[^>]*selected/', $allowedselect);
        $this->assertMatchesRegularExpression('/<option[^>]*value="forum"[^>]*selected/', $allowedselect);
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*value="quiz"[^>]*selected/', $allowedselect);

        // A custom naming pattern round-trips: the select shows Custom and
        // the text field holds the saved pattern.
        $patternselect = $this->extract_select($html, 'namingpattern');
        $this->assertMatchesRegularExpression('/<option[^>]*value="__custom__"[^>]*selected/', $patternselect);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="custompattern"[^>]*value="Chapter \{N\} - \{nombre\}"/',
            $html
        );

        // Saved naming start restored.
        $startselect = $this->extract_select($html, 'namingstart');
        $this->assertMatchesRegularExpression('/<option[^>]*value="0"[^>]*selected/', $startselect);
    }

    /**
     * A template saved without extra sections keeps the checkbox unticked,
     * and a preset naming pattern selects its own option (no Custom).
     */
    public function test_form_prefills_preset_pattern_without_extra_sections(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $tpl = $this->create_template((int) $course->id, [
            'namingpattern' => 'Tema {N}: {nombre}',
        ]);

        $html = $this->render_form_for_template((int) $course->id, (int) $tpl->get('id'));

        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="allowaddsections"[^>]*checked/', $html);

        $patternselect = $this->extract_select($html, 'namingpattern');
        $this->assertMatchesRegularExpression('/<option[^>]*value="Tema \{N\}: \{nombre\}"[^>]*selected/', $patternselect);
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*value="__custom__"[^>]*selected/', $patternselect);
    }
}
