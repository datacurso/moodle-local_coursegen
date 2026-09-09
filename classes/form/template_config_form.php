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
 * Per-kind default behavior + generated-course limits — real mform elements
 * instead of hand-built markup (the "Default behavior per kind of
 * component" selects and the "Generated course limits" fields used to be
 * raw HTML strung together in amd/src/local/template/kind_defaults.js and
 * template_wizard.mustache, with JS binding events on top of them).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_coursegen\local\service\mock_template_ai_service;

/**
 * Form for the kind-default / limits / allowed-types part of the config screen.
 */
class template_config_form extends \moodleform {

    /**
     * Friendly label + sensible default action per recognised component kind.
     *
     * Mirrors amd/src/local/template/kind_defaults.js's KIND_META — kept in
     * sync manually (JS cannot read a PHP class constant); this PHP copy is
     * now the one that actually decides each field's default, the JS copy
     * only still matters for seeding individual per-activity actions after
     * a course is (re)selected (see kind_defaults.js::applyKindDefaultsToState).
     *
     * @var array<string, array{label:string, default:string}>
     */
    private const KIND_META = [
        'label' => ['label' => 'Banners', 'default' => 'modify'],
        'page' => ['label' => 'Informational pages', 'default' => 'modify'],
        'forum' => ['label' => 'Discussion forums', 'default' => 'keep'],
        'resource' => ['label' => 'File attachments', 'default' => 'keep'],
        'assign' => ['label' => 'Graded activities', 'default' => 'modify'],
        'feedback' => ['label' => 'Closing survey', 'default' => 'keep'],
        'lesson' => ['label' => 'Lesson content', 'default' => 'keep'],
    ];

    /**
     * Render this form for a course, as HTML — the single entry point both
     * the initial page load (edit_template.php) and the AJAX course-change
     * path (classes/external/get_course_preview.php) call, so both paths
     * build the exact same fields the same way.
     *
     * @param \course_modinfo $modinfo
     * @return string
     */
    public static function render(\course_modinfo $modinfo): string {
        $presentmodnames = [];
        foreach ($modinfo->get_cms() as $cm) {
            $presentmodnames[$cm->modname] = true;
        }
        $presentmodnames = array_keys($presentmodnames);
        sort($presentmodnames);

        $numsections = count($modinfo->get_section_info_all()) - 1;

        $modtypes = [];
        foreach (get_module_types_names() as $modname => $displayname) {
            $modtypes[$modname] = $displayname;
        }

        $form = new self(null, [
            'presentmodnames' => $presentmodnames,
            'defaultmaxsections' => max(1, $numsections),
            'defaultallowedtypes' => $presentmodnames,
            'modtypes' => $modtypes,
        ], 'post', '', ['id' => 'tpl-config-form']);

        ob_start();
        $form->display();
        return ob_get_clean();
    }

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->disable_form_change_checker();

        $actionlabels = [
            'modify' => get_string('template_activity_modify', 'local_coursegen'),
            'keep' => get_string('template_activity_keep', 'local_coursegen'),
            'reference' => get_string('template_activity_reference', 'local_coursegen'),
            'exclude' => get_string('template_activity_exclude', 'local_coursegen'),
        ];

        $presentmodnames = $this->_customdata['presentmodnames'] ?? [];
        if (!empty($presentmodnames)) {
            $mform->addElement('header', 'kinddefaultshdr', get_string('template_kind_defaults_title', 'local_coursegen'));
            $mform->setExpanded('kinddefaultshdr');
            $mform->addElement('static', 'kinddefaultsdesc', '',
                get_string('template_kind_defaults_desc', 'local_coursegen'));

            foreach ($presentmodnames as $modname) {
                $meta = self::KIND_META[$modname] ?? ['label' => $modname, 'default' => 'keep'];
                // Never offer "Modify" for a kind the AI generator cannot
                // produce today — this is the same constraint already
                // enforced server-side for the per-activity dropdown (see
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

                $fieldname = "kinddefault_{$modname}";
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
        $mform->setDefault('maxsections', $this->_customdata['defaultmaxsections'] ?? 1);

        $mform->addElement('advcheckbox', 'nolimit', '', get_string('template_no_limit', 'local_coursegen'));
        $mform->setType('nolimit', PARAM_BOOL);
        // Replaces the previous hand-wired "disable the number field in JS
        // when the checkbox is ticked" — this is exactly what disabledIf is for.
        $mform->disabledIf('maxsections', 'nolimit', 'checked');

        $modtypes = $this->_customdata['modtypes'] ?? [];
        if (!empty($modtypes)) {
            $mform->addElement('header', 'allowedtypeshdr', get_string('template_allowed_types', 'local_coursegen'));
            $mform->setExpanded('allowedtypeshdr');
            $mform->addElement('static', 'allowedtypesdesc', '',
                get_string('template_allowed_types_desc', 'local_coursegen'));

            $defaultallowed = $this->_customdata['defaultallowedtypes'] ?? [];
            foreach ($modtypes as $modname => $displayname) {
                $fieldname = "allowedtype_{$modname}";
                $mform->addElement('advcheckbox', $fieldname, '', $displayname);
                $mform->setType($fieldname, PARAM_BOOL);
                $mform->setDefault($fieldname, in_array($modname, $defaultallowed, true) ? 1 : 0);
            }
        }
    }
}
