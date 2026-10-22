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

namespace local_coursegen\local;

/**
 * The H5P content types the AI service can generate, as the teacher picks them.
 *
 * Mirrors H5PContentTypeSelection of the course_ai service, which decides what the service can build; a type the
 * service does not know is refused there. Each type has a name for the list (lang string h5ptype_<type>).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5p_content_types {
    /**
     * Every content type, in the order the list shows them, and whether it is built around images.
     *
     * @var array<string,bool> Type => built around images.
     */
    public const TYPES = [
        'question_set' => false,
        'single_choice_set' => false,
        'multiple_choice' => false,
        'multimedia_choice' => true,
        'fill_in_the_blanks' => false,
        'advanced_blanks' => false,
        'drag_the_words' => false,
        'drag_and_drop' => true,
        'mark_the_words' => false,
        'sort_the_paragraphs' => false,
        'summary' => false,
        'essay' => false,
        'crossword' => false,
        'find_the_words' => false,
        'arithmetic_quiz' => false,
        'personality_quiz' => false,
        'questionnaire' => false,
        'column_page' => false,
        'course_presentation' => false,
        'interactive_book' => false,
        'accordion' => false,
        'timeline' => false,
        'documentation_tool' => false,
        'structure_strip' => false,
        'info_wall' => false,
        'branching_scenario' => false,
        'chart' => false,
        'flashcards' => true,
        'memory_game' => true,
        'image_pair' => true,
        'image_sequencing' => true,
        'image_hotspots' => true,
        'find_the_hotspot' => true,
        'find_multiple_hotspots' => true,
        'image_juxtaposition' => true,
        'image_slider' => true,
        'agamotto' => true,
        'collage' => true,
        'game_map' => true,
        'guess_the_answer' => true,
        'speak_the_words' => false,
        'speak_the_words_set' => false,
    ];

    /**
     * Whether a value is a content type of the list.
     *
     * @param string $type
     * @return bool
     */
    public static function is_valid(string $type): bool {
        return array_key_exists($type, self::TYPES);
    }

    /**
     * The list as the modal shows it.
     *
     * @return array[] Each with type, name, description, iconurl and usesimages.
     */
    public static function for_modal(): array {
        $list = [];
        foreach (self::TYPES as $type => $usesimages) {
            $list[] = [
                'type' => $type,
                'name' => get_string('h5ptype_' . $type, 'local_coursegen'),
                'description' => get_string('h5ptypedesc_' . $type, 'local_coursegen'),
                'iconurl' => self::icon_url($type),
                'usesimages' => $usesimages,
            ];
        }
        return $list;
    }

    /**
     * The picture H5P gives the content type, or the icon of the H5P activity when the type has none.
     *
     * @param string $type
     * @return string
     */
    private static function icon_url(string $type): string {
        global $CFG, $OUTPUT;

        if (file_exists($CFG->dirroot . '/local/coursegen/pix/h5p/' . $type . '.svg')) {
            return $OUTPUT->image_url('h5p/' . $type, 'local_coursegen')->out(false);
        }
        return $OUTPUT->image_url('monologo', 'h5pactivity')->out(false);
    }
}
