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
 * Course AI i18n loader.
 *
 * @module     local_coursegen/local/courseai/i18n
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_strings} from 'core/str';

const STRING_KEYS = [
    'courseai_header_title',
    'courseai_header_subtitle',
    'courseai_step_context',
    'courseai_step_planning',
    'courseai_step_generating',
    'courseai_context_title',
    'courseai_context_subtitle',
    'courseai_prompt_placeholder',
    'courseai_prompt_arialabel',
    'courseai_chip_remove_syllabus',
    'courseai_chip_view_guideline',
    'courseai_chip_remove_guideline',
    'courseai_btn_syllabus',
    'courseai_btn_syllabus_title',
    'courseai_btn_guidelines',
    'courseai_btn_guidelines_title',
    'courseai_guidelines_dialog_label',
    'courseai_guidelines_search_placeholder',
    'courseai_guidelines_note_one',
    'courseai_guidelines_list_label',
    'courseai_lang_title',
    'courseai_lang_arialabel',
    'courseai_images_title',
    'courseai_images_label',
    'courseai_images_arialabel',
    'courseai_hint_enter',
    'courseai_btn_generate',
    'courseai_form_tip',
    'courseai_progress_label',
    'courseai_toggle_details',
    'courseai_prv_sub_init',
    'courseai_prv_live_note',
    'courseai_plan_actions_hint',
    'courseai_btn_adjust',
    'courseai_btn_approve',
    'courseai_adjust_placeholder',
    'courseai_btn_cancel',
    'courseai_delete_section_confirm_title',
    'courseai_delete_section_confirm_body',
    'courseai_delete_activity_confirm_title',
    'courseai_delete_activity_confirm_body',
    'courseai_btn_send_adjust',
    'courseai_btn_back_context',
    'courseai_btn_cancel_flow',
    'courseai_btn_cancel_and_exit',
    'courseai_modal_close',
    'courseai_modal_subtitle_text',
    'courseai_modal_fullcontext',
    'courseai_no_results',
    'courseai_category_general',
    'courseai_state_planning',
    'courseai_state_starting',
    'courseai_state_structuring',
    'courseai_state_completed',
    'courseai_state_error',
    'courseai_progress_percent',
    'courseai_plan_counter',
    'courseai_plan_adding',
    'courseai_plan_sections_counter',
    'courseai_plan_review_title',
    'courseai_plan_review_subtitle',
    'courseai_plan_detailed_title',
    'courseai_plan_detailed_subtitle',
    'courseai_plan_detailed_stats',
    'courseai_plan_review_hint_detailed',
    'courseai_plan_detailed_done_title',
    'courseai_plan_detailed_done_subtitle',
    'courseai_plan_detailed_markdown_title',
    'courseai_plan_detailed_markdown_subtitle',
    'courseai_generating_details',
    'courseai_generating_details_for',
    'courseai_section_label',
    'courseai_section_progress_with_total',
    'courseai_section_progress_no_total',
    'courseai_activities_count',
    'courseai_processing_status',
    'courseai_error_stream_url',
    'courseai_error_connection',
    'courseai_error_create_course',
    'courseai_error_init_session',
    'courseai_error_upload_syllabus',
    'courseai_error_send_feedback',
    'courseai_error_init_filepicker',
    'courseai_error_generic',
    'courseai_generate_starting',
    'courseai_generate_uploading_syllabus',
    'courseai_course_creating',
    'courseai_course_creating_subtitle',
    'courseai_completion_title',
    'courseai_completion_summary_default',
    'courseai_completion_summary_no_images',
    'courseai_completion_summary_with_images',
    'courseai_completion_btn_open_course',
    'courseai_completion_btn_create_another',
    'courseai_back_to_context',
    'courseai_live_note_detailed',
    'courseai_activity_default',
    'courseai_activity_quiz',
    'courseai_activity_book',
    'courseai_activity_assign',
    'courseai_activity_forum',
    'courseai_activity_lesson',
    'courseai_activity_url',
    'courseai_activity_resource',
    'courseai_activity_page',
    'courseai_activity_data',
    'courseai_activity_glossary',
    'courseai_plan_default_unnamed',
    'courseai_status_approving',
    'courseai_status_adjusting',
    'courseai_btn_regenerate',
    'courseai_btn_pause',
    'courseai_chapters_label',
    'courseai_questions_label',
    'courseai_notes_label',
    'courseai_images_suggested_label',
    'courseai_images_select_all',
    'courseai_image_count_one',
    'courseai_image_count_many',
    'courseai_review_title',
    'courseai_review_subtitle',
    'courseai_review_step_label',
    'courseai_review_fullname_label',
    'courseai_review_fullname_placeholder',
    'courseai_review_shortname_label',
    'courseai_review_shortname_placeholder',
    'courseai_review_shortname_note',
    'courseai_review_category_label',
    'courseai_review_category_loading',
    'courseai_review_cancel',
    'courseai_review_confirm',
];

/**
 * Load all courseai strings from lang pack.
 *
 * @returns {Promise<Object>}
 */
export const loadCourseaiStrings = async() => {
    const values = await get_strings(STRING_KEYS.map((key) => ({key, component: 'local_coursegen'})));
    return Object.fromEntries(STRING_KEYS.map((key, i) => [key, values[i]]));
};
