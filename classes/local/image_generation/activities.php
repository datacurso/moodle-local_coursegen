<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursegen\local\image_generation;

/**
 * Activity definitions for image generation settings.
 *
 * This centralises the configuration keys and language string identifiers
 * for each supported activity or resource type.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activities {
    /** Generation mode: disabled. */
    public const MODE_DISABLED = 'disabled';

    /** Generation mode: automatic. */
    public const MODE_AUTO = 'auto';

    /** Generation mode: manual. */
    public const MODE_MANUAL = 'manual';

    /**
     * Get activity definitions.
     *
     * Each definition uses lowercase keys without underscores so it can be
     * consumed easily from templates, JS and external functions.
     *
     * @return array[]
     */
    public static function get_definitions(): array {
        return [
            [
                'id' => 'assign',
                'configenable' => 'enableimgassign',
                'configprompt' => 'promptimgassign',
                'defaultprompt' => get_string('default_prompt_assign', 'local_coursegen'),
                'stringactivity' => get_string('activity_assign', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_assign', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_assign_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgassign_intro',
                        'configmaximages' => 'maximgassign_intro',
                        'stringlabel' => get_string('activity_assign_part_intro', 'local_coursegen'),
                    ],
                    [
                        'id' => 'instructions',
                        'configenable' => 'enableimgassign_instructions',
                        'configmaximages' => 'maximgassign_instructions',
                        'stringlabel' => get_string('activity_assign_part_instructions', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'book',
                'configenable' => 'enableimgbook',
                'configprompt' => 'promptimgbook',
                'defaultprompt' => get_string('default_prompt_book', 'local_coursegen'),
                'stringactivity' => get_string('activity_book', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_book', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_book_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgbook_intro',
                        'configmaximages' => 'maximgbook_intro',
                        'stringlabel' => get_string('activity_book_part_intro', 'local_coursegen'),
                    ],
                    [
                        'id' => 'chapter',
                        'configenable' => 'enableimgbook_chapter',
                        'configmaximages' => 'maximgbook_chapter',
                        'stringlabel' => get_string('activity_book_part_chapter', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'choice',
                'configenable' => 'enableimgchoice',
                'configprompt' => 'promptimgchoice',
                'defaultprompt' => get_string('default_prompt_choice', 'local_coursegen'),
                'stringactivity' => get_string('activity_choice', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_choice', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_choice_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgchoice_intro',
                        'configmaximages' => 'maximgchoice_intro',
                        'stringlabel' => get_string('activity_choice_part_intro', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'data',
                'configenable' => 'enableimgdata',
                'configprompt' => 'promptimgdata',
                'defaultprompt' => get_string('default_prompt_data', 'local_coursegen'),
                'stringactivity' => get_string('activity_data', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_data', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_data_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgdata_intro',
                        'configmaximages' => 'maximgdata_intro',
                        'stringlabel' => get_string('activity_data_part_intro', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'feedback',
                'configenable' => 'enableimgfeedback',
                'configprompt' => 'promptimgfeedback',
                'defaultprompt' => get_string('default_prompt_feedback', 'local_coursegen'),
                'stringactivity' => get_string('activity_feedback', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_feedback', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_feedback_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgfeedback_intro',
                        'configmaximages' => 'maximgfeedback_intro',
                        'stringlabel' => get_string('activity_feedback_part_intro', 'local_coursegen'),
                    ],
                    [
                        'id' => 'label',
                        'configenable' => 'enableimgfeedback_label',
                        'configmaximages' => 'maximgfeedback_label',
                        'stringlabel' => get_string('activity_feedback_part_label', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'folder',
                'configenable' => 'enableimgfolder',
                'configprompt' => 'promptimgfolder',
                'defaultprompt' => get_string('default_prompt_folder', 'local_coursegen'),
                'stringactivity' => get_string('activity_folder', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_folder', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_folder_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgfolder_intro',
                        'configmaximages' => 'maximgfolder_intro',
                        'stringlabel' => get_string('activity_folder_part_intro', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'forum',
                'configenable' => 'enableimgforum',
                'configprompt' => 'promptimgforum',
                'defaultprompt' => get_string('default_prompt_forum', 'local_coursegen'),
                'stringactivity' => get_string('activity_forum', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_forum', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_forum_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgforum_intro',
                        'configmaximages' => 'maximgforum_intro',
                        'stringlabel' => get_string('activity_forum_part_intro', 'local_coursegen'),
                    ],
                    [
                        'id' => 'discussions',
                        'configenable' => 'enableimgforum_discussions',
                        'configmaximages' => 'maximgforum_discussions',
                        'stringlabel' => get_string('activity_forum_part_discussions', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'glossary',
                'configenable' => 'enableimgglossary',
                'configprompt' => 'promptimgglossary',
                'defaultprompt' => get_string('default_prompt_glossary', 'local_coursegen'),
                'stringactivity' => get_string('activity_glossary', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_glossary', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_glossary_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgglossary_intro',
                        'configmaximages' => 'maximgglossary_intro',
                        'stringlabel' => get_string('activity_glossary_part_intro', 'local_coursegen'),
                    ],
                    [
                        'id' => 'entries',
                        'configenable' => 'enableimgglossary_entries',
                        'configmaximages' => 'maximgglossary_entries',
                        'stringlabel' => get_string('activity_glossary_part_entries', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'label',
                'configenable' => 'enableimglabel',
                'configprompt' => 'promptimglabel',
                'defaultprompt' => get_string('default_prompt_label', 'local_coursegen'),
                'stringactivity' => get_string('activity_label', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_label', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_label_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimglabel_intro',
                        'configmaximages' => 'maximglabel_intro',
                        'stringlabel' => get_string('activity_label_part_intro', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'lesson',
                'configenable' => 'enableimglesson',
                'configprompt' => 'promptimglesson',
                'defaultprompt' => get_string('default_prompt_lesson', 'local_coursegen'),
                'stringactivity' => get_string('activity_lesson', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_lesson', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_lesson_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimglesson_intro',
                        'configmaximages' => 'maximglesson_intro',
                        'stringlabel' => get_string('activity_lesson_part_intro', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'page',
                'configenable' => 'enableimgpage',
                'configprompt' => 'promptimgpage',
                'defaultprompt' => get_string('default_prompt_page', 'local_coursegen'),
                'stringactivity' => get_string('activity_page', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_page', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_page_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgpage_intro',
                        'configmaximages' => 'maximgpage_intro',
                        'stringlabel' => get_string('activity_page_part_intro', 'local_coursegen'),
                    ],
                    [
                        'id' => 'page',
                        'configenable' => 'enableimgpage_page',
                        'configmaximages' => 'maximgpage_page',
                        'stringlabel' => get_string('activity_page_part_page', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'quiz',
                'configenable' => 'enableimgquiz',
                'configprompt' => 'promptimgquiz',
                'defaultprompt' => get_string('default_prompt_quiz', 'local_coursegen'),
                'stringactivity' => get_string('activity_quiz', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_quiz', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_quiz_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgquiz_intro',
                        'configmaximages' => 'maximgquiz_intro',
                        'stringlabel' => get_string('activity_quiz_part_intro', 'local_coursegen'),
                    ],
                    [
                        'id' => 'questions',
                        'configenable' => 'enableimgquiz_questions',
                        'configmaximages' => 'maximgquiz_questions',
                        'stringlabel' => get_string('activity_quiz_part_questions', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'resource',
                'configenable' => 'enableimgresource',
                'configprompt' => 'promptimgresource',
                'defaultprompt' => get_string('default_prompt_resource', 'local_coursegen'),
                'stringactivity' => get_string('activity_resource', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_resource', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_resource_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'document',
                        'configenable' => 'enableimgresource_document',
                        'configmaximages' => 'maximgresource_document',
                        'stringlabel' => get_string('activity_resource_part_document', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'url',
                'configenable' => 'enableimgurl',
                'configprompt' => 'promptimgurl',
                'defaultprompt' => get_string('default_prompt_url', 'local_coursegen'),
                'stringactivity' => get_string('activity_url', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_url', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_url_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgurl_intro',
                        'configmaximages' => 'maximgurl_intro',
                        'stringlabel' => get_string('activity_url_part_intro', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'wiki',
                'configenable' => 'enableimgwiki',
                'configprompt' => 'promptimgwiki',
                'defaultprompt' => get_string('default_prompt_wiki', 'local_coursegen'),
                'stringactivity' => get_string('activity_wiki', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_wiki', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_wiki_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgwiki_intro',
                        'configmaximages' => 'maximgwiki_intro',
                        'stringlabel' => get_string('activity_wiki_part_intro', 'local_coursegen'),
                    ],
                    [
                        'id' => 'pages',
                        'configenable' => 'enableimgwiki_pages',
                        'configmaximages' => 'maximgwiki_pages',
                        'stringlabel' => get_string('activity_wiki_part_pages', 'local_coursegen'),
                    ],
                ],
            ],
            [
                'id' => 'workshop',
                'configenable' => 'enableimgworkshop',
                'configprompt' => 'promptimgworkshop',
                'defaultprompt' => get_string('default_prompt_workshop', 'local_coursegen'),
                'stringactivity' => get_string('activity_workshop', 'local_coursegen'),
                'stringtooltip' => get_string('tooltip_enable_workshop', 'local_coursegen'),
                'stringpromptlabel' => get_string('prompt_workshop_label', 'local_coursegen'),
                'parts' => [
                    [
                        'id' => 'intro',
                        'configenable' => 'enableimgworkshop_intro',
                        'configmaximages' => 'maximgworkshop_intro',
                        'stringlabel' => get_string('activity_workshop_part_intro', 'local_coursegen'),
                    ],
                ],
            ],
        ];
    }
}
