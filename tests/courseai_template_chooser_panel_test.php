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
 * Prompt-panel contract of the template-mode activity chooser modal.
 *
 * Picking an activity type in #tplActivityChooserModal must reveal a prompt
 * panel (selected-activity chip, generate-images radios, file upload chip,
 * prompt textarea and confirm button) instead of inserting immediately, so
 * the panel markup must be server-rendered inside the modal body — hidden by
 * default and wired up by local/courseai/template/chooser.js.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen
 */
final class courseai_template_chooser_panel_test extends \advanced_testcase {
    /**
     * Render the courseai_page template with a minimal context.
     *
     * @param bool $templatemode Whether template mode is active.
     * @return string Rendered HTML.
     */
    private function render_page(bool $templatemode): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_coursegen/courseai_page', [
            'guidelines' => '[]',
            'coursetemplates' => [],
            'templatepickerformhtml' => '',
            'templateemptystatehtml' => '',
            'hascoursetemplates' => false,
            'languages' => '[]',
            'defaultlang' => 'en',
            'logourl' => '',
            'hassessions' => false,
            'sessions' => [],
            'allsessions' => [],
            'isresuming' => false,
            'showsessionsview' => false,
            'templatemodeactive' => $templatemode,
            'subsectionsenabled' => false,
            'closeurl' => (new \moodle_url('/my/courses.php'))->out(false),
        ]);
    }

    /**
     * Template mode renders the chooser prompt panel, hidden by default.
     */
    public function test_template_mode_renders_chooser_prompt_panel(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $this->assertStringContainsString('id="tplChooserPromptPanel"', $html);
        $this->assertStringContainsString('data-region="local_coursegen/template/chooser-panel"', $html);
        $this->assertStringContainsString('data-region="local_coursegen/template/chooser-selected-name"', $html);
    }

    /**
     * The panel carries the generate-images radios reused from the activity AI chat footer.
     */
    public function test_chooser_prompt_panel_renders_generate_images_radios(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $this->assertStringContainsString('name="tpl_generate_images"', $html);
        $this->assertStringContainsString(get_string('noimages', 'local_coursegen'), $html);
        $this->assertStringContainsString(get_string('yesimages', 'local_coursegen'), $html);
    }

    /**
     * The panel carries the upload button, the selected-file chip and its remove control.
     */
    public function test_chooser_prompt_panel_renders_upload_regions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $this->assertStringContainsString('data-region="local_coursegen/template/chooser-upload"', $html);
        $this->assertStringContainsString('data-region="local_coursegen/template/chooser-selectedfile"', $html);
        $this->assertStringContainsString('data-region="local_coursegen/template/chooser-selectedfile_remove"', $html);
    }

    /**
     * The panel carries the prompt textarea and the confirm (add) button.
     */
    public function test_chooser_prompt_panel_renders_prompt_and_confirm(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_page(true);

        $this->assertStringContainsString('data-region="local_coursegen/template/chooser-prompt"', $html);
        $this->assertStringContainsString('data-region="local_coursegen/template/chooser-confirm"', $html);
        $this->assertStringContainsString(
            get_string('courseai_add_activity_placeholder', 'local_coursegen'),
            $html
        );
        $this->assertStringContainsString(get_string('courseai_template_add_activity', 'local_coursegen'), $html);
    }
}
