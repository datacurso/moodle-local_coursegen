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

/**
 * The part of the template configuration form that explains the markers an author types in a template.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait template_config_form_markers {
    /**
     * The marker section: what each marker is for, with a help button that explains how to type it.
     */
    private function definition_markers(): void {
        $mform = $this->_form;

        $title = get_string('template_markers_title', 'local_coursegen');
        $mform->addElement('header', 'markershdr', $title);
        $mform->setExpanded('markershdr', false);

        $label = get_string('template_reference_marker', 'local_coursegen');
        $example = get_string('template_reference_marker_example', 'local_coursegen');
        $mform->addElement('static', 'referencemarker', $label, $example);
        $mform->addHelpButton('referencemarker', 'template_reference_marker', 'local_coursegen');
    }
}
