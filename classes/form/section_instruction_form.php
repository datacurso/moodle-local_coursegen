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

namespace local_coursegen\form;

use context;
use context_system;
use core_form\dynamic_form;
use local_coursegen\local\models\template_section;
use moodle_url;

/**
 * The editor of a template section's instruction, shown inside its card in the template editor.
 *
 * The form is never submitted on its own: the template's save reads its text and its draft area and
 * keeps both with the section (see template_persistence_service). It only gives the instruction the
 * site's editor, with pictures, links and files.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_instruction_form extends dynamic_form {
    /** Name of the editor field. */
    public const FIELD = 'instruction';

    /**
     * Options of the editor and of its file area.
     *
     * @return array
     */
    public static function editor_options(): array {
        return [
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => 0,
            'trusttext' => false,
            'context' => context_system::instance(),
            'subdirs' => true,
        ];
    }

    /**
     * The fields: the section and the template it belongs to, and the editor.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'sectionid');
        $mform->setType('sectionid', PARAM_INT);
        $mform->addElement('hidden', 'templateid');
        $mform->setType('templateid', PARAM_INT);
        $mform->addElement(
            'editor',
            self::FIELD . '_editor',
            get_string('template_section_instruction', 'local_coursegen'),
            ['rows' => 8],
            self::editor_options()
        );
        $mform->setType(self::FIELD . '_editor', PARAM_RAW);
    }

    /**
     * The system context: templates belong to the site.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Only someone who creates or edits templates may load it.
     */
    protected function check_access_for_dynamic_submission(): void {
        $context = $this->get_context_for_dynamic_submission();
        if (!has_capability('local/coursegen:edittemplates', $context)) {
            require_capability('local/coursegen:createtemplates', $context);
        }
    }

    /**
     * Nothing is saved from here: the template's save keeps the instruction.
     *
     * @return array
     */
    public function process_dynamic_submission(): array {
        return [];
    }

    /**
     * Load the instruction the section already has, with its files in a new draft area.
     */
    public function set_data_for_dynamic_submission(): void {
        $sectionid = $this->optional_param('sectionid', 0, PARAM_INT);
        $templateid = $this->optional_param('templateid', 0, PARAM_INT);
        $record = $templateid > 0
            ? template_section::get_record(['templateid' => $templateid, 'sectionid' => $sectionid])
            : false;
        $data = (object) [
            'sectionid' => $sectionid,
            'templateid' => $templateid,
            self::FIELD => $record ? (string) $record->get('instruction') : '',
            self::FIELD . 'format' => $record ? (int) $record->get('instructionformat') : FORMAT_HTML,
        ];
        $data = file_prepare_standard_editor(
            $data,
            self::FIELD,
            self::editor_options(),
            context_system::instance(),
            'local_coursegen',
            template_section::INSTRUCTION_FILEAREA,
            $record ? (int) $record->get('id') : null
        );
        $this->set_data($data);
    }

    /**
     * The page the form lives on.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        $templateid = $this->optional_param('templateid', 0, PARAM_INT);
        return new moodle_url('/local/coursegen/edit_template.php', ['id' => $templateid]);
    }
}
