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

namespace local_coursegen\local\preview;

/**
 * Picks the preview that matches one activity type.
 *
 * Types are added one at a time, and a type with nothing of its own yet still
 * previews: its description is the part every module shares, so that is the
 * fallback, and it degrades to showing less rather than to showing nothing.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_factory {
    /**
     * Every activity type, paired with the preview written against its own view.
     *
     * All nineteen the plugin supports are here. Several share a preview
     * because their pages are the same shape: a file and a folder both list
     * documents, and H5P, SCORM and an IMS package are all a player around
     * content the AI writes the text of.
     */
    private const PREVIEWS = [
        'assign' => assign_preview::class,
        'book' => book_preview::class,
        'choice' => choice_preview::class,
        'data' => data_preview::class,
        'feedback' => feedback_preview::class,
        'folder' => file_preview::class,
        'forum' => forum_preview::class,
        'glossary' => glossary_preview::class,
        'h5pactivity' => package_preview::class,
        'imscp' => package_preview::class,
        'label' => label_preview::class,
        'lesson' => lesson_preview::class,
        'page' => page_preview::class,
        'quiz' => quiz_preview::class,
        'resource' => file_preview::class,
        'scorm' => package_preview::class,
        'url' => url_preview::class,
        'wiki' => wiki_preview::class,
        'workshop' => workshop_preview::class,
    ];

    /**
     * The preview for one activity.
     *
     * @param string $modname The activity type.
     * @param array $parameters The activity's parameters, as the AI returned them.
     * @return activity_preview
     */
    public static function for_activity(string $modname, array $parameters, array $source = []): activity_preview {
        $class = self::PREVIEWS[$modname] ?? intro_preview::class;
        return new $class($parameters, $source);
    }

    /**
     * Whether this type is drawn from its own view rather than the fallback.
     *
     * @param string $modname
     * @return bool
     */
    public static function has_own_preview(string $modname): bool {
        return isset(self::PREVIEWS[$modname]);
    }
}
