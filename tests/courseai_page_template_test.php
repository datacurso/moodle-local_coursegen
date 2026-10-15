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

/**
 * Rendering tests for the course AI page template (import-into-existing-course panel).
 *
 * aicoursecreation.php renders local_coursegen/courseai_page with an
 * importexistingenabled flag taken from the enableimportexisting setting.
 * Rendering the template server-side with that flag checks the markup
 * contract the review-panel JavaScript and the Behat feature rely on
 * (ticket #9539).
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class courseai_page_template_test extends \advanced_testcase {
    /**
     * Render the page template with the context shape aicoursecreation.php builds.
     *
     * @param bool $importexistingenabled Value of the importexistingenabled flag.
     * @return string Rendered HTML.
     */
    private function render(bool $importexistingenabled): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_coursegen/courseai_page', [
            'guidelines' => '[]',
            'languages' => '[]',
            'defaultlang' => 'en',
            'logourl' => 'https://example.com/logo.png',
            'hassessions' => false,
            'sessions' => [],
            'allsessions' => [],
            'isresuming' => false,
            'subsectionsenabled' => false,
            'importexistingenabled' => $importexistingenabled,
            'guidelinelist' => [
                'listlabel' => 'Guidelines list',
                'emptytext' => 'No results',
                'hasitems' => false,
                'items' => [],
            ],
        ]);
    }

    /**
     * The review panel carries the target-mode radios and the import fields only when the setting is on.
     *
     * @return void
     */
    public function test_import_controls_render_only_when_enabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render(true);
        $this->assertStringContainsString('data-import-existing-enabled="1"', $html);
        $this->assertStringContainsString('name="coursegen-targetmode"', $html);
        $this->assertStringContainsString('id="importTargetFields"', $html);
        $this->assertStringContainsString('id="importCategory"', $html);
        $this->assertStringContainsString('id="importCourse"', $html);
        $this->assertStringContainsString('id="importProgressView"', $html);
        // The "new" mode is the default choice.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="coursegen-targetmode"[^>]*value="new"[^>]*checked/',
            $html
        );
        // The existing new-course fields stay in place.
        $this->assertStringContainsString('id="reviewCategory"', $html);
        $this->assertStringContainsString('id="reviewShortname"', $html);

        $html = $this->render(false);
        $this->assertStringContainsString('data-import-existing-enabled="0"', $html);
        $this->assertStringNotContainsString('coursegen-targetmode', $html);
        $this->assertStringNotContainsString('id="importTargetFields"', $html);
        $this->assertStringNotContainsString('id="importCourse"', $html);
        // The existing new-course fields are untouched by the flag.
        $this->assertStringContainsString('id="reviewCategory"', $html);
        $this->assertStringContainsString('id="reviewShortname"', $html);
    }

    /**
     * The import progress card is part of the page regardless of the flag: a session
     * started while the setting was on may be resumed after it was switched off.
     *
     * @return void
     */
    public function test_import_progress_view_always_renders(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render(false);
        $this->assertStringContainsString('id="importProgressView"', $html);
        $this->assertStringContainsString('id="btnOpenGeneratedCourse"', $html);
        // The done state reuses the completion card, whose title and button label are swapped by JS.
        $this->assertStringContainsString('id="completionTitle"', $html);
        $this->assertStringContainsString('id="btnOpenMoodleCourseLabel"', $html);
    }
}
