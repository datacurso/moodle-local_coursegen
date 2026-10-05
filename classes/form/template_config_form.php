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

/**
 * Per-type default behavior + generated-course limits — a real
 * \core_form\dynamic_form, loaded inline via core_form/dynamicform whenever
 * the selected base course changes.
 *
 * This used to be a plain moodleform whose HTML a custom external function
 * (classes/external/get_course_preview.php) shuttled around as a raw string,
 * re-injected via innerHTML and manually re-bound in JS. dynamic_form is
 * Moodle's own generic answer to exactly this — "a form whose content
 * depends on runtime context, loaded/reloaded via AJAX without a page
 * reload" — via the core-provided core_form_dynamic_form web service, so
 * none of that custom plumbing is needed: see
 * amd/src/local/template/init.js for the client side.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

defined('MOODLE_INTERNAL') || die();

use context;
use context_system;
use core_form\dynamic_form;
use local_coursegen\local\models\template;
use moodle_url;

/**
 * Dynamic form for the limits part of the config screen.
 */
class template_config_form extends dynamic_form {
    use template_config_form_markers;

    /** Value of the naming select that means "use the pattern typed in the custom field". */
    const NAMING_CUSTOM = '__custom__';

    /** Token of a naming pattern that stands for the section's number. */
    const NAMING_TOKEN_NUMBER = '{N}';

    /** Token of a naming pattern that stands for the original section's name. */
    const NAMING_TOKEN_NAME = '{name}';

    /** The data-region hook of the naming pattern select, read by the template editor script. */
    const HOOK_NAMING_PATTERN = 'local_coursegen/template/naming-pattern';

    /** The data-region hook of the custom pattern field. */
    const HOOK_CUSTOM_PATTERN = 'local_coursegen/template/custom-pattern';

    /** The data-region hook of the first section number select. */
    const HOOK_NAMING_START = 'local_coursegen/template/naming-start';

    /** The data-region hook of the extra sections field. */
    const HOOK_MAX_SECTIONS = 'local_coursegen/template/max-sections';

    /** The data-region hook of the allow-extra-sections checkbox. */
    const HOOK_ALLOW_ADD_SECTIONS = 'local_coursegen/template/allow-add-sections';


    /**
     * Form definition.
     *
     * The selected course is passed as the "courseid" arg to
     * DynamicForm.load({courseid}) on the JS side, and read back here via
     * optional_param — the same pattern core's own
     * local_test\form\example_dynamic_form uses for its "coursemodule" arg.
     * No course selected yet (initial page load): render nothing.
     *
     * Edit mode passes "templateid" alongside "courseid" (both from
     * edit_template.php's initial render and from DynamicForm.load()): every
     * default below is then overridden with the template's saved values.
     */
    public function definition() {
        // Change tracking stays ENABLED (Moodle's default). This form is
        // reloaded wholesale via DynamicForm.load() whenever the selected
        // course changes, and is never itself submitted (its fields feed
        // the real Save action in init.js) — see the matching note on
        // course_picker_form::definition() for why the warning is still
        // wanted, and how it gets reset after a real save.

        $courseid = $this->optional_param('courseid', 0, PARAM_INT);
        if ($courseid <= 0) {
            return;
        }
        $template = $this->saved_template();
        $this->definition_limits($template);
        $this->definition_naming_pattern($template);
        $this->definition_markers();
    }

    /**
     * The template being edited, or null for a fresh one.
     *
     * The saved template's values override every fresh-course default below.
     * A stale/unknown id just falls back to the defaults.
     *
     * @return template|null
     */
    private function saved_template(): ?template {
        $templateid = $this->optional_param('templateid', 0, PARAM_INT);
        if ($templateid <= 0) {
            return null;
        }
        $template = template::get_record(['id' => $templateid]);
        if (!$template) {
            return null;
        }
        return $template;
    }

    /**
     * How many extra sections a template starts with: its saved allowance
     * when it has one, otherwise 1.
     *
     * @param template|null $template Saved template, null for a fresh one.
     * @return int
     */
    public static function default_extra_sections(?template $template): int {
        if (!$template) {
            return 1;
        }
        $saved = (int) $template->get('maxsections');
        if ($saved > 0) {
            return $saved;
        }
        return 1;
    }

    /**
     * Section-limit fields: whether the teacher may add extra sections, and
     * how many.
     *
     * @param template|null $template Saved template to prefill from (edit
     *     mode), null for the fresh defaults.
     */
    private function definition_limits(?template $template): void {
        $mform = $this->_form;

        $limitstitle = get_string('template_limits_title', 'local_coursegen');
        $limitsdesc = get_string('template_limits_desc', 'local_coursegen');
        $mform->addElement('header', 'limitshdr', $limitstitle);
        $mform->setExpanded('limitshdr');
        $mform->addElement('static', 'limitsdesc', '', $limitsdesc);

        // Whether the teacher creating a course from this template may add
        // EXTRA sections on top of the template's own. Unchecked by default:
        // the template's own sections are always available regardless.
        $allowlabel = get_string('template_allow_add_sections', 'local_coursegen');
        $allowattributes = ['data-region' => self::HOOK_ALLOW_ADD_SECTIONS];
        $mform->addElement('advcheckbox', 'allowaddsections', '', $allowlabel, $allowattributes);
        $mform->setType('allowaddsections', PARAM_BOOL);
        if ($template) {
            // Checked iff the saved template allows extra sections — either a
            // positive maxsections or the legacy nolimit flag.
            $allowextrasections = $template->get('maxsections') > 0 || $template->get('nolimit');
            $mform->setDefault('allowaddsections', (int) $allowextrasections);
        }
        $mform->addHelpButton('allowaddsections', 'template_allow_add_sections', 'local_coursegen');

        // How many extra sections the teacher may add. Deliberately KEEPS the
        // "maxsections" element name even though the label changed: the save
        // payload, external function signature and stored column all stay
        // stable — only the meaning of the number changed (extra allowance,
        // no longer a total cap).
        $extralabel = get_string('template_extra_sections', 'local_coursegen');
        $maxattributes = ['size' => 5, 'data-region' => self::HOOK_MAX_SECTIONS];
        $mform->addElement('text', 'maxsections', $extralabel, $maxattributes);
        $mform->setType('maxsections', PARAM_INT);
        $maxsectionsdefault = self::default_extra_sections($template);
        $mform->setDefault('maxsections', $maxsectionsdefault);
        $mform->addHelpButton('maxsections', 'template_extra_sections', 'local_coursegen');
        // Only meaningful while the checkbox above is ticked — this is
        // exactly what hideIf is for, no hand-wired JS needed.
        $mform->hideIf('maxsections', 'allowaddsections', 'notchecked');
    }

    /**
     * The numbered section-naming presets, worded in the current language.
     *
     * The number and name tokens are the substitution contract shared with
     * the client-side preview, so they are identical in every language; only
     * the surrounding word comes from the language pack.
     * A preset's value is the pattern itself, so a pattern saved in another
     * language simply round-trips through the Custom option.
     *
     * @return string[] Pattern => label, both the same text.
     */
    public static function naming_presets(): array {
        $presets = [];
        foreach (['unit', 'module', 'topic', 'week'] as $kind) {
            $pattern = get_string('template_naming_preset_' . $kind, 'local_coursegen');
            $presets[$pattern] = $pattern;
        }
        return $presets;
    }

    /**
     * The pattern a fresh template starts with: the first preset.
     *
     * @return string
     */
    public static function default_naming_pattern(): string {
        $presets = self::naming_presets();
        $first = array_key_first($presets);
        return (string) $first;
    }

    /**
     * The pattern options the naming select offers: the language presets,
     * the name alone, and the custom entry.
     *
     * @return string[] Pattern => label.
     */
    public static function naming_options(): array {
        $nameonly = get_string('template_naming_name_only', 'local_coursegen');
        $custom = get_string('template_naming_custom', 'local_coursegen');
        $presets = self::naming_presets();
        return $presets + [self::NAMING_TOKEN_NAME => $nameonly, self::NAMING_CUSTOM => $custom];
    }

    /**
     * What the client-side preview needs to read the naming fields and to
     * apply a pattern, so the page script holds none of these literals.
     *
     * @return string[] {customvalue, numbertoken, nametoken}
     */
    public static function naming_contract(): array {
        return [
            'customvalue' => self::NAMING_CUSTOM,
            'numbertoken' => self::NAMING_TOKEN_NUMBER,
            'nametoken' => self::NAMING_TOKEN_NAME,
        ];
    }

    /**
     * The naming select's value and the custom field's text for a template.
     *
     * A saved pattern that is one of the options selects that option; any
     * other saved pattern round-trips through the Custom option with the
     * pattern itself restored into the text field.
     *
     * @param template|null $template Saved template, null for the defaults.
     * @param string[] $patterns The options of the naming select.
     * @return string[] {select value, custom text}
     */
    public static function naming_defaults(?template $template, array $patterns): array {
        $patterndefault = self::default_naming_pattern();
        $savedpattern = '';
        if ($template) {
            $savedpattern = (string) $template->get('namingpattern');
        }
        if ($savedpattern === '') {
            return [$patterndefault, ''];
        }
        if (array_key_exists($savedpattern, $patterns)) {
            return [$savedpattern, ''];
        }
        return [self::NAMING_CUSTOM, $savedpattern];
    }

    /**
     * Section-naming-pattern fields: how each generated section's name is
     * derived from its position, independent of any one course's structure
     * (unlike the type-defaults/limits above, these options don't depend on
     * $courseid at all) — kept in this same dynamic_form rather than a
     * separate one so the whole config screen stays one coherent unit, and
     * because its live preview (rendered client-side, see
     * amd/src/local/template/step_limits.js::updatePreview) needs the same
     * already-loaded course structure the type-defaults section does.
     *
     * @param template|null $template Saved template to prefill from (edit
     *     mode), null for the fresh defaults.
     */
    private function definition_naming_pattern(?template $template): void {
        global $OUTPUT;

        $mform = $this->_form;

        $headertitle = get_string('template_naming_pattern', 'local_coursegen');
        $mform->addElement('header', 'namingpatternhdr', $headertitle);
        $mform->setExpanded('namingpatternhdr');

        $patterns = self::naming_options();
        [$patterndefault, $customdefault] = self::naming_defaults($template, $patterns);

        $patternattributes = ['data-region' => self::HOOK_NAMING_PATTERN];
        $mform->addElement('select', 'namingpattern', $headertitle, $patterns, $patternattributes);
        $mform->setType('namingpattern', PARAM_RAW);
        $mform->setDefault('namingpattern', $patterndefault);
        $mform->addHelpButton('namingpattern', 'template_naming_pattern', 'local_coursegen');

        $customlabel = get_string('template_naming_custom', 'local_coursegen');
        $placeholder = get_string('template_naming_custom_placeholder', 'local_coursegen');
        $customattributes = ['placeholder' => $placeholder, 'data-region' => self::HOOK_CUSTOM_PATTERN];
        $mform->addElement('text', 'custompattern', $customlabel, $customattributes);
        $mform->setType('custompattern', PARAM_TEXT);
        $mform->setDefault('custompattern', $customdefault);
        $mform->hideIf('custompattern', 'namingpattern', 'neq', self::NAMING_CUSTOM);
        $mform->addHelpButton('custompattern', 'template_naming_custom', 'local_coursegen');

        $startlabel = get_string('template_naming_start', 'local_coursegen');
        $startattributes = ['data-region' => self::HOOK_NAMING_START];
        $mform->addElement('select', 'namingstart', $startlabel, [1 => '1', 0 => '0'], $startattributes);
        $mform->setType('namingstart', PARAM_INT);
        $startdefault = 1;
        if ($template) {
            $startdefault = (int) $template->get('namingstart');
        }
        $mform->setDefault('namingstart', $startdefault);
        $mform->addHelpButton('namingstart', 'template_naming_start', 'local_coursegen');

        $previewbox = $OUTPUT->render_from_template('local_coursegen/template_naming_preview_box', []);
        $mform->addElement('static', 'namingpreviewwrap', '', $previewbox);
    }

    /**
     * Returns context where this form is used.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Checks if current user has access to this form, otherwise throws exception.
     */
    protected function check_access_for_dynamic_submission(): void {
        $context = $this->get_context_for_dynamic_submission();
        $templateid = $this->optional_param('templateid', 0, PARAM_INT);
        $capability = 'local/coursegen:createtemplates';
        if ($templateid > 0) {
            $capability = 'local/coursegen:edittemplates';
        }
        require_capability($capability, $context);
    }

    /**
     * Process the form submission, used if form was submitted via AJAX.
     *
     * Never actually reached in normal use: this form renders no submit
     * button (its fields are read directly by JS and folded into the
     * template-wide "Save" action — see amd/src/local/template/init.js),
     * so the client never calls DynamicForm's submitFormAjax() on it. Still
     * required by the abstract base class.
     *
     * @return array
     */
    public function process_dynamic_submission() {
        return ['ok' => true];
    }

    /**
     * Load in existing data as form defaults.
     *
     * No-op: every default is already set directly in definition() above,
     * the same way local_test\form\example_dynamic_form (the core reference
     * example this was modelled on) leaves this empty too.
     */
    public function set_data_for_dynamic_submission(): void {
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/coursegen/edit_template.php');
    }
}
