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
                'id' => 'book',
                'configenable' => 'enableimgbook',
                'configprompt' => 'promptimgbook',
                'defaultprompt' => get_string('default_prompt_book', 'local_coursegen'),
                'stringactivity' => 'activity_book',
                'stringtooltip' => 'tooltip_enable_book',
                'stringpromptlabel' => 'prompt_book_label',
                'iconclass' => 'fa-book',
            ],
            [
                'id' => 'quiz',
                'configenable' => 'enableimgquiz',
                'configprompt' => 'promptimgquiz',
                'defaultprompt' => get_string('default_prompt_quiz', 'local_coursegen'),
                'stringactivity' => 'activity_quiz',
                'stringtooltip' => 'tooltip_enable_quiz',
                'stringpromptlabel' => 'prompt_quiz_label',
                'iconclass' => 'fa-pencil-square-o',
            ],
        ];
    }
}
