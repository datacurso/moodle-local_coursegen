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
 * System instruction form for DataCurso plugin.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

use local_coursegen\local\service\system_instruction_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * System instruction form class.
 *
 * Custom data: tenantid (int), the current tenant, which owns the instruction;
 * names are validated for uniqueness within that tenant.
 */
class system_instruction_form extends \moodleform {
    /**
     * Define the form.
     */
    public function definition() {
        $mform = $this->_form;

        // System instruction name field.
        $mform->addElement('text', 'name', get_string('systeminstructionname', 'local_coursegen'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('name', 'systeminstructionname', 'local_coursegen');

        // System instruction content field (rich text editor).
        $mform->addElement(
            'editor',
            'content_editor',
            get_string('systeminstructioncontent', 'local_coursegen'),
            ['rows' => 15],
            $this->get_editor_options()
        );
        $mform->setType('content_editor', PARAM_RAW);
        $mform->addHelpButton('content_editor', 'systeminstructioncontent', 'local_coursegen');

        // Hidden fields. The tenant is never submitted: the page always uses the current tenant.
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Action buttons.
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Get editor options.
     *
     * @return array
     */
    private function get_editor_options() {
        return [
            'maxfiles' => 0,
            'trusttext' => true,
            'subdirs' => false,
        ];
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = get_string('required');
        } else {
            // Names are unique within the tenant; when editing, the record itself is excluded.
            $excludeid = !empty($data['id']) ? (int) $data['id'] : null;
            if (!system_instruction_service::validate_unique_name($name, $this->get_tenantid(), $excludeid)) {
                $errors['name'] = get_string('systeminstructionnameexists', 'local_coursegen');
            }
        }

        return $errors;
    }

    /**
     * Tenant owning the instruction, from the form custom data.
     *
     * @return int
     */
    private function get_tenantid(): int {
        return (int) ($this->_customdata['tenantid'] ?? \local_coursegen\local\tenancy::get_tenant_id());
    }
}
