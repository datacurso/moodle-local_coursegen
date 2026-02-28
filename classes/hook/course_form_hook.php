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

namespace local_coursegen\hook;

use core_course\hook\after_form_definition;
use core_course\hook\after_form_definition_after_data;
use core_course\hook\after_form_submission;
use core_course\hook\after_form_validation;
use local_coursegen\ai_context;
use local_coursegen\ai_course;
use local_coursegen\system_instruction;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Hook to extend the course form with custom fields.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_form_hook {
    /**
     * Hook to add custom fields to the course form.
     *
     * @param after_form_definition $hook Hook object with the form.
     * @throws \coding_exception
     */
    public static function after_form_definition(after_form_definition $hook): void {
        global $PAGE;
        $mform = $hook->mform;

        // Add a section for custom fields.
        $mform->addElement(
            'header',
            'local_coursegen_header',
            get_string('custom_fields_header', 'local_coursegen')
        );
        $mform->setExpanded('local_coursegen_header', true);

        $languages = get_string_manager()->get_list_of_languages(null, 'iso6391');
        $options = [];
        foreach ($languages as $code => $name) {
            $options[$code] = "$name ($code)";
        }
        $attributes = [
            'multiple' => false,
            'noselectionstring' => get_string('choosedots'),
        ];
        $mform->addElement(
            'autocomplete',
            'local_coursegen_lang',
            get_string('ai_response_language', 'local_coursegen'),
            $options,
            $attributes
        );

        $mform->addHelpButton('local_coursegen_lang', 'ai_response_language', 'local_coursegen');

        // Default to the current user language, matching available codes when possible.
        $defaultcode = current_language();
        $mform->setDefault('local_coursegen_lang', $defaultcode);

        // Add option to generate images for the course.
        $mform->addElement(
            'select',
            'local_coursegen_generate_images',
            get_string('course_generate_images_field', 'local_coursegen'),
            [
                0 => get_string('noimages', 'local_coursegen'),
                1 => get_string('yesimages', 'local_coursegen'),
            ]
        );
        $mform->setType('local_coursegen_generate_images', PARAM_INT);
        $mform->setDefault('local_coursegen_generate_images', 0);
        $mform->addHelpButton(
            'local_coursegen_generate_images',
            'course_generate_images_field',
            'local_coursegen'
        );

        // Add context type selector.
        $contexttypes = [
            '' => get_string('choosedots'),
            ai_context::CONTEXT_TYPE_CUSTOM_PROMPT => get_string('context_type_customprompt', 'local_coursegen'),
            ai_context::CONTEXT_TYPE_SYLLABUS => get_string('context_type_syllabus', 'local_coursegen'),
        ];
        $mform->addElement(
            'select',
            'local_coursegen_context_type',
            get_string('context_type_field', 'local_coursegen'),
            $contexttypes
        );
        $mform->setDefault('local_coursegen_context_type', '');

        // Add custom prompt field (shown only when context type is custom prompt).
        $mform->addElement(
            'textarea',
            'local_coursegen_custom_prompt',
            get_string('custom_prompt_field', 'local_coursegen'),
            ['rows' => 6, 'cols' => 60]
        );
        $mform->addHelpButton('local_coursegen_custom_prompt', 'custom_prompt_field', 'local_coursegen');
        $mform->setType('local_coursegen_custom_prompt', PARAM_TEXT);
        $mform->hideIf(
            'local_coursegen_custom_prompt',
            'local_coursegen_context_type',
            'neq',
            ai_context::CONTEXT_TYPE_CUSTOM_PROMPT
        );

        // Add field to upload syllabus PDF (shown only when context type is syllabus).
        $mform->addElement(
            'filepicker',
            'local_coursegen_syllabus_pdf',
            get_string('syllabus_pdf_field', 'local_coursegen'),
            null,
            [
                'accepted_types' => ['.pdf'],
                'maxfiles' => 1,
                'subdirs' => 0,
            ]
        );
        $mform->addHelpButton('local_coursegen_syllabus_pdf', 'syllabus_pdf_field', 'local_coursegen');
        $mform->hideIf('local_coursegen_syllabus_pdf', 'local_coursegen_context_type', 'neq', ai_context::CONTEXT_TYPE_SYLLABUS);

        // Add checkbox to enable the use of a system instruction as a complement.
        $mform->addElement(
            'advcheckbox',
            'local_coursegen_use_system_instruction',
            get_string('use_system_instruction_field', 'local_coursegen'),
            get_string('use_system_instruction_field_label', 'local_coursegen')
        );
        $mform->addHelpButton('local_coursegen_use_system_instruction', 'use_system_instruction_field', 'local_coursegen');

        // Get system instructions from the database.
        $instructions = system_instruction::get_all();
        $hasinstructions = !empty($instructions);
        if ($hasinstructions) {
            foreach ($instructions as $instruction) {
                $instructionoptions[$instruction->id] = $instruction->name;
            }

            // Add system instruction selector (shown only when the checkbox is enabled).
            $mform->addElement(
                'select',
                'local_coursegen_select_system_instruction',
                get_string('custom_system_instruction_select_field', 'local_coursegen'),
                $instructionoptions
            );
            $mform->addHelpButton(
                'local_coursegen_select_system_instruction',
                'custom_system_instruction_select_field',
                'local_coursegen'
            );
            $mform->hideIf(
                'local_coursegen_select_system_instruction',
                'local_coursegen_use_system_instruction',
                'notchecked'
            );
        } else {
            // Show notice when there are no system instructions configured (only if the checkbox is enabled).
            $manageinstructionsurl = (new \moodle_url('/local/coursegen/manage_system_instructions.php'))->out();
            $mform->addElement(
                'static',
                'local_coursegen_select_system_instruction_notice',
                get_string('custom_system_instruction_select_field', 'local_coursegen'),
                get_string('no_system_instructions_configured_notice', 'local_coursegen', $manageinstructionsurl)
            );
            $mform->hideIf(
                'local_coursegen_select_system_instruction_notice',
                'local_coursegen_use_system_instruction',
                'notchecked'
            );
        }

        // Add hidden field for AI creation to identify the form submission.
        $mform->addElement('hidden', 'local_coursegen_create_ai_course', 0);
        $mform->setType('local_coursegen_create_ai_course', PARAM_INT);
    }
}
