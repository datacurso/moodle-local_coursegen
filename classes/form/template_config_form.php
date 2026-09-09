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
 * Dynamic form for the type-default / limits / allowed-types part of the config screen.
 */
class template_config_form extends dynamic_form {

    /**
     * Friendly label + sensible default action per recognised activity type.
     *
     * Mirrors amd/src/local/template/type_action_sync.js's TYPE_META — kept in
     * sync manually (JS cannot read a PHP class constant); this PHP copy is
     * the one that actually decides each field's default, the JS copy only
     * still matters for seeding individual per-activity actions after a
     * course is (re)selected (see type_action_sync.js::applyTypeDefaultsToState).
     *
     * @var array<string, array{label:string, default:string}>
     */
    private const TYPE_META = [
        'label' => ['label' => 'Banners', 'default' => 'modify'],
        'page' => ['label' => 'Informational pages', 'default' => 'modify'],
        'forum' => ['label' => 'Discussion forums', 'default' => 'keep'],
        'resource' => ['label' => 'File attachments', 'default' => 'keep'],
        'assign' => ['label' => 'Graded activities', 'default' => 'modify'],
        'feedback' => ['label' => 'Closing survey', 'default' => 'keep'],
        'lesson' => ['label' => 'Lesson content', 'default' => 'keep'],
    ];

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

        $actionlabels = [
            'modify' => get_string('template_activity_modify', 'local_coursegen'),
            'keep' => get_string('template_activity_keep', 'local_coursegen'),
            'reference' => get_string('template_activity_reference', 'local_coursegen'),
            'exclude' => get_string('template_activity_exclude', 'local_coursegen'),
        ];

        if (!empty($presentmodnames)) {
            $mform->addElement('header', 'typedefaultshdr', get_string('template_type_defaults_title', 'local_coursegen'));
            $mform->setExpanded('typedefaultshdr');
            $mform->addElement('static', 'typedefaultsdesc', '',
                get_string('template_type_defaults_desc', 'local_coursegen'));

            foreach ($presentmodnames as $modname) {
                $meta = self::TYPE_META[$modname] ?? ['label' => $modname, 'default' => 'keep'];
                // Never offer "Modify" for a type the AI generator cannot
                // produce today — the same constraint already enforced
                // server-side for the per-activity dropdown (see
                // classes/output/sections_config.php), applied here too so
                // an admin can never pick an option that will silently fail
                // later, at either level.
                $cansupportmodify = in_array($modname, mock_template_ai_service::SUPPORTED, true);
                $default = ($meta['default'] === 'modify' && !$cansupportmodify) ? 'keep' : $meta['default'];

                $options = [
                    'keep' => $actionlabels['keep'],
                    'reference' => $actionlabels['reference'],
                    'exclude' => $actionlabels['exclude'],
                ];
                if ($cansupportmodify) {
                    $options = ['modify' => $actionlabels['modify']] + $options;
                }

                $fieldname = "typedefault_{$modname}";
                $mform->addElement('select', $fieldname, $meta['label'], $options);
                $mform->setType($fieldname, PARAM_ALPHA);
                $mform->setDefault($fieldname, $default);
            }
        }

        $mform->addElement('header', 'limitshdr', get_string('template_limits_title', 'local_coursegen'));
        $mform->setExpanded('limitshdr');
        $mform->addElement('static', 'limitsdesc', '', get_string('template_limits_desc', 'local_coursegen'));

        $mform->addElement('text', 'maxsections', get_string('template_max_sections', 'local_coursegen'), ['size' => 5]);
        $mform->setType('maxsections', PARAM_INT);
        $mform->setDefault('maxsections', max(1, $numsections));

        $mform->addElement('advcheckbox', 'nolimit', '', get_string('template_no_limit', 'local_coursegen'));
        $mform->setType('nolimit', PARAM_BOOL);
        // Replaces the previous hand-wired "disable the number field in JS
        // when the checkbox is ticked" — this is exactly what disabledIf is for.
        $mform->disabledIf('maxsections', 'nolimit', 'checked');

        $modtypes = [];
        foreach (get_module_types_names() as $modname => $displayname) {
            $modtypes[$modname] = $displayname;
        }
        if (!empty($modtypes)) {
            $mform->addElement('header', 'allowedtypeshdr', get_string('template_allowed_types', 'local_coursegen'));
            $mform->setExpanded('allowedtypeshdr');
            $mform->addElement('static', 'allowedtypesdesc', '',
                get_string('template_allowed_types_desc', 'local_coursegen'));

            foreach ($modtypes as $modname => $displayname) {
                $fieldname = "allowedtype_{$modname}";
                $mform->addElement('advcheckbox', $fieldname, '', $displayname);
                $mform->setType($fieldname, PARAM_BOOL);
                $mform->setDefault($fieldname, in_array($modname, $presentmodnames, true) ? 1 : 0);
            }
        }
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
