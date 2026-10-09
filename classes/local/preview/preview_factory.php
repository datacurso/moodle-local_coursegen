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
        'folder' => folder_preview::class,
        'forum' => forum_preview::class,
        'glossary' => glossary_preview::class,
        'h5pactivity' => h5pactivity_preview::class,
        'imscp' => imscp_preview::class,
        'label' => label_preview::class,
        'lesson' => lesson_preview::class,
        'page' => page_preview::class,
        'quiz' => quiz_preview::class,
        'resource' => resource_preview::class,
        'scorm' => scorm_preview::class,
        'url' => url_preview::class,
        'wiki' => wiki_preview::class,
        'workshop' => workshop_preview::class,
    ];

    /**
     * The preview for one activity.
     *
     * @param string $modname The activity type.
     * @param array $parameters The activity's parameters, as the AI returned them.
     * @param int $cmid The template's course module the page is built on, or 0.
     * @return activity_preview
     */
    public static function for_activity(string $modname, array $parameters, int $cmid = 0): activity_preview {
        if (self::is_written_from_scratch($parameters)) {
            return new settings_preview($modname, $parameters, $cmid);
        }
        $class = self::PREVIEWS[$modname] ?? intro_preview::class;
        return new $class($parameters, $cmid);
    }

    /**
     * Whether the activity is created from what it carries in its settings rather than from a template tree.
     *
     * The template agent says it in the parameters: an activity it made by rewriting the words of its template
     * activity is created from that tree, and one it wrote with the generator of its type is created from its
     * settings, whatever tree it also carries. The previews written against the views of each module read the tree,
     * so they show the first kind and {@see settings_preview} shows the second. A result that does not say, for
     * example the one of a flow without the agent, is judged by whether it has a tree, and so is one that says it
     * is made from its tree but carries none.
     *
     * @param array $parameters The activity's parameters, as the AI returned them.
     * @return bool
     */
    private static function is_written_from_scratch(array $parameters): bool {
        $stated = array_key_exists('from_structure', $parameters);
        if ($stated && empty($parameters['from_structure'])) {
            return true;
        }
        $structure = $parameters['structure'] ?? [];
        return empty($structure);
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
