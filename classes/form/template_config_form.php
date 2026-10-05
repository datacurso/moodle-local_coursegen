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
 * Prompt marker guidance — a real
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
use moodle_url;

/**
 * Dynamic form for prompt marker guidance.
 */
class template_config_form extends dynamic_form {
    use template_config_form_markers;

    /**
     * Form definition.
     */
    public function definition() {
        // The UI has no manual section limits or naming controls.
        $this->definition_markers();
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
