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
use local_coursegen\local\service\mock_template_ai_service;
use moodle_url;

/**
 * Dynamic form for the limits / allowed-types part of the config screen.
 */
class template_config_form extends dynamic_form {

    /**
     * Form definition.
     *
     * The selected course is passed as the "courseid" arg to
     * DynamicForm.load({courseid}) on the JS side, and read back here via
     * optional_param — the same pattern core's own
     * local_test\form\example_dynamic_form uses for its "coursemodule" arg.
     * No course selected yet (initial page load): render nothing.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->disable_form_change_checker();

        $courseid = $this->optional_param('courseid', 0, PARAM_INT);
        if ($courseid <= 0) {
            return;
        }
        $modinfo = get_fast_modinfo($courseid);

        $presentmodnames = [];
        foreach ($modinfo->get_cms() as $cm) {
            $presentmodnames[$cm->modname] = true;
        }
        $presentmodnames = array_keys($presentmodnames);
        sort($presentmodnames);

        $numsections = count($modinfo->get_section_info_all()) - 1;

        $mform->addElement('header', 'limitshdr', get_string('template_limits_title', 'local_coursegen'));
        $mform->setExpanded('limitshdr');
        $mform->addElement('static', 'limitsdesc', '', get_string('template_limits_desc', 'local_coursegen'));

        $mform->addElement('text', 'maxsections', get_string('template_max_sections', 'local_coursegen'), ['size' => 5]);
        $mform->setType('maxsections', PARAM_INT);
        $mform->setDefault('maxsections', max(1, $numsections));
        $mform->addHelpButton('maxsections', 'template_max_sections', 'local_coursegen', '', false, max(1, $numsections));

        $mform->addElement('advcheckbox', 'nolimit', '', get_string('template_no_limit', 'local_coursegen'));
        $mform->setType('nolimit', PARAM_BOOL);
        $mform->addHelpButton('nolimit', 'template_no_limit', 'local_coursegen');
        // Replaces the previous hand-wired "disable the number field in JS
        // when the checkbox is ticked" — this is exactly what disabledIf is for.
        $mform->disabledIf('maxsections', 'nolimit', 'checked');

        // Only ever offer types the AI service actually has a content
        // contract for (see mock_template_ai_service::AI_SUPPORTED_TYPES'
        // own docblock) — never everything installed on the site. On a real
        // site that can mean dozens of installed module types; restricting
        // to the AI-supported set both keeps this list scannable and
        // guarantees an admin can never allow a type here that would just
        // silently fail (or need manual authoring) when a course is
        // generated.
        $modtypes = [];
        foreach (get_module_types_names() as $modname => $displayname) {
            if (in_array($modname, mock_template_ai_service::AI_SUPPORTED_TYPES, true)) {
                $modtypes[$modname] = $displayname;
            }
        }
        if (!empty($modtypes)) {
            $mform->addElement('header', 'allowedtypeshdr', get_string('template_allowed_types', 'local_coursegen'));
            $mform->setExpanded('allowedtypeshdr');
            $mform->addElement('static', 'allowedtypesdesc', '',
                get_string('template_allowed_types_desc', 'local_coursegen'));

            // A single searchable multi-select (Moodle's own standard
            // building block for "pick several from a moderate list", the
            // same autocomplete element already used for the base-course
            // picker in course_picker_form.php) replaces what used to be one
            // checkbox row per installed type — with dozens of installed
            // types that list became a long wall to scan; typing to filter
            // and seeing selections as chips is far more scannable, and no
            // AJAX transport is needed since the AI-supported list is short
            // enough to send whole, exactly like the category field.
            $mform->addElement('autocomplete', 'allowedtypes', '', $modtypes, [
                'multiple' => true,
                'noselectionstring' => get_string('template_allowed_types_none', 'local_coursegen'),
            ]);
            $mform->setType('allowedtypes', PARAM_ALPHANUMEXT);
            $preselected = array_values(array_intersect($presentmodnames, array_keys($modtypes)));
            $mform->setDefault('allowedtypes', $preselected);
            $mform->addHelpButton('allowedtypes', 'template_allowed_types', 'local_coursegen');
        }

        $this->definition_naming_pattern();
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
     */
    private function definition_naming_pattern(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'namingpatternhdr', get_string('template_naming_pattern', 'local_coursegen'));
        $mform->setExpanded('namingpatternhdr');

        $patterns = [
            'Unidad {N} — {nombre}' => 'Unidad {N} — {nombre}',
            'Módulo {N}: {nombre}' => 'Módulo {N}: {nombre}',
            'Tema {N}: {nombre}' => 'Tema {N}: {nombre}',
            'Semana {N}: {nombre}' => 'Semana {N}: {nombre}',
            '{nombre}' => get_string('template_naming_name_only', 'local_coursegen'),
            '__custom__' => get_string('template_naming_custom', 'local_coursegen'),
        ];
        $mform->addElement('select', 'namingpattern', get_string('template_naming_pattern', 'local_coursegen'), $patterns);
        $mform->setType('namingpattern', PARAM_RAW);
        $mform->setDefault('namingpattern', 'Unidad {N} — {nombre}');
        $mform->addHelpButton('namingpattern', 'template_naming_pattern', 'local_coursegen');

        $mform->addElement('text', 'custompattern', get_string('template_naming_custom', 'local_coursegen'),
            ['placeholder' => 'E.g.: Chapter {N} - {nombre}']);
        $mform->setType('custompattern', PARAM_TEXT);
        $mform->hideIf('custompattern', 'namingpattern', 'neq', '__custom__');
        $mform->addHelpButton('custompattern', 'template_naming_custom', 'local_coursegen');

        $mform->addElement('select', 'namingstart', get_string('template_naming_start', 'local_coursegen'), [
            1 => '1',
            0 => '0',
        ]);
        $mform->setType('namingstart', PARAM_INT);
        $mform->setDefault('namingstart', 1);
        $mform->addHelpButton('namingstart', 'template_naming_start', 'local_coursegen');

        $mform->addElement('static', 'namingpreviewwrap', '',
            \html_writer::div('', 'bg-light rounded p-2', ['data-region' => 'naming-preview']));
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
        require_capability('local/coursegen:managetemplates', $this->get_context_for_dynamic_submission());
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
