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

        // Add option to generate images for the course (gated by admin setting + capability).
        $cangenerateimages = self::can_generate_images_in_form();
        if ($cangenerateimages) {
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
        } else {
            $mform->addElement('hidden', 'local_coursegen_generate_images', 0);
            $mform->setType('local_coursegen_generate_images', PARAM_INT);
        }

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

    /**
     * Hooks to set default data to the form fields
     *
     * @param after_form_definition_after_data $hook Hook object with the form.
     */
    public static function after_form_definition_after_data(after_form_definition_after_data $hook): void {
        global $DB;
        $courseid = optional_param('id', 0, PARAM_INT);
        if (!empty($courseid)) {
            $context = \context_course::instance($courseid);

            // Create a draft area for the filepicker.
            $draftitemid = file_get_submitted_draft_itemid('local_coursegen_syllabus_pdf');

            // Copy existing file to draft area.
            file_prepare_draft_area(
                $draftitemid,
                $context->id,
                'local_coursegen',
                ai_context::CONTEXT_TYPE_SYLLABUS,
                0,
                ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['.pdf']]
            );

            // Get saved context type and system instruction selection from database.
            $contexttype = '';
            $selectedinstruction = '';
            $useinstruction = 0;

            // Get existing course context data from database.
            $contextdata = $DB->get_record('local_coursegen_course_context', ['courseid' => $courseid]);
            if ($contextdata) {
                $contexttype = $contextdata->context_type;
                $selectedinstruction = $contextdata->system_instruction_id;
                if (!empty($selectedinstruction)) {
                    $useinstruction = 1;
                }
            }

            $prompttext = !empty($contextdata->prompt_text) ? $contextdata->prompt_text : '';
            $selectedlang = !empty($contextdata->lang) ? $contextdata->lang : '';

            $editform = $hook->formwrapper;

            $editform->set_data([
                'local_coursegen_syllabus_pdf' => $draftitemid,
                'local_coursegen_context_type' => $contexttype,
                'local_coursegen_custom_prompt' => $prompttext,
                'local_coursegen_lang' => $selectedlang,
                'local_coursegen_use_system_instruction' => $useinstruction,
                'local_coursegen_select_system_instruction' => $selectedinstruction,
                'local_coursegen_generate_images' => 0,
            ]);
        }
    }

    /**
     * Hook to process the form submission.
     *
     * @param after_form_submission $hook Hook object with the form data.
     */
    public static function after_form_submission(after_form_submission $hook): void {
        global $DB;

        $data = $hook->get_data();
        $courseid = $data->id;
        $createaicourse = $data->local_coursegen_create_ai_course ?? 0;
        $contexttype = $data->local_coursegen_context_type ?? '';
        $draftitemid = $data->local_coursegen_syllabus_pdf ?? 0;
        $useinstruction = !empty($data->local_coursegen_use_system_instruction);
        $generateimages = $data->local_coursegen_generate_images ?? 0;
        $selectedlang = $data->local_coursegen_lang ?? null;

        try {
            if ($contexttype === ai_context::CONTEXT_TYPE_SYLLABUS) {
                // Save syllabus PDF from draft area.
                $success = ai_context::save_syllabus_from_draft($courseid, $draftitemid);

                if ($success) {
                    ai_context::upload_syllabus_to_ai($courseid);
                }
            }

            $promptmessage = null;

            if ($contexttype === ai_context::CONTEXT_TYPE_CUSTOM_PROMPT) {
                $promptmessage = trim($data->local_coursegen_custom_prompt ?? '');
            }

            $selectedinstruction = null;
            if ($useinstruction) {
                $selectedinstruction = $data->local_coursegen_select_system_instruction ?? null;
            }

            // Store the context type and selected option in the database.
            ai_context::save_course_context($courseid, $contexttype, $selectedinstruction, $selectedlang, $promptmessage);

            if (!empty($createaicourse)) {
                ai_course::start_course_planning(
                    $courseid,
                    $contexttype,
                    $selectedinstruction,
                    $data->fullname,
                    $promptmessage,
                    $generateimages,
                    $selectedlang
                );
            }
        } catch (\Exception $e) {
            \core\notification::error(get_string('error', 'local_coursegen', $e->getMessage()));
        }
    }

    /**
     * Hook to validate the form data.
     *
     * @param after_form_validation $hook Hook object with the form data.
     */
    public static function after_form_validation(after_form_validation $hook): void {
        $data = $hook->get_data();

        $createaicourse = isset($data['local_coursegen_create_ai_course']) ? (int)$data['local_coursegen_create_ai_course'] : 0;
        if ($createaicourse !== 1) {
            return;
        }

        $errors = [];
        $contexttype = isset($data['local_coursegen_context_type']) ? (string)$data['local_coursegen_context_type'] : '';
        $useinstruction = !empty($data['local_coursegen_use_system_instruction']);
        $allowed = [
            ai_context::CONTEXT_TYPE_SYLLABUS,
            ai_context::CONTEXT_TYPE_CUSTOM_PROMPT,
        ];
        if ($contexttype === '' || !in_array($contexttype, $allowed, true)) {
            $errors['local_coursegen_context_type'] = get_string('error_context_type_required', 'local_coursegen');
        } else if ($contexttype === ai_context::CONTEXT_TYPE_SYLLABUS) {
            $draftitemid = $data['local_coursegen_syllabus_pdf'] ?? 0;
            $info = $draftitemid ? file_get_draft_area_info($draftitemid) : null;
            $filecount = is_array($info) && array_key_exists('filecount', $info) ? (int)$info['filecount'] : 0;
            if (empty($draftitemid) || $filecount < 1) {
                $errors['local_coursegen_syllabus_pdf'] = get_string('error_syllabus_pdf_required', 'local_coursegen');
            }
        } else if ($contexttype === ai_context::CONTEXT_TYPE_CUSTOM_PROMPT) {
            $prompttext = trim($data['local_coursegen_custom_prompt'] ?? '');
            if (empty($prompttext)) {
                $errors['local_coursegen_custom_prompt'] = get_string('error_prompt_required', 'local_coursegen');
            }
        }

        if ($useinstruction) {
            $instructions = system_instruction::get_all();
            $instructionid = $data['local_coursegen_select_system_instruction'] ?? null;

            if (empty($instructions)) {
                $errors['local_coursegen_use_system_instruction'] = get_string(
                    'error_no_system_instructions_configured',
                    'local_coursegen'
                );
            } else if (empty($instructionid)) {
                $errors['local_coursegen_select_system_instruction'] = get_string(
                    'error_system_instruction_required',
                    'local_coursegen'
                );
            }
        }

        if (!empty($errors)) {
            $hook->add_errors($errors);
        }
    }

    /**
     * Check if image generation is allowed in the current course form context.
     *
     * Requires the admin master switch and the per-user capability.
     * Uses course context when editing an existing course, or category context
     * when creating a new one.
     *
     * @return bool
     */
    private static function can_generate_images_in_form(): bool {
        global $DB;

        if (!get_config('local_coursegen', 'enable_course_image_generation')) {
            return false;
        }

        $courseid = optional_param('id', 0, PARAM_INT);
        if ($courseid) {
            $context = \context_course::instance($courseid);
        } else {
            $categoryid = optional_param('category', 0, PARAM_INT);
            if ($categoryid) {
                $context = \context_coursecat::instance($categoryid);
            } else {
                $category = \core_course_category::get_default();
                $context = \context_coursecat::instance($category->id);
            }
        }

        return has_capability('local/coursegen:generatecourseimages', $context);
    }
}
