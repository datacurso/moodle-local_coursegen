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
 * What the preview of an activity written from scratch shows, for each type of activity.
 *
 * An activity that the generator of its type wrote has no template tree to be drawn from: what it is made of
 * travels in its settings. This says, for each type, which lists of its settings the preview shows, how each item
 * is titled and where its text is, and which plain facts of its parameters go beside them. Adding a type is adding
 * one entry; a type that is not listed shows its description only.
 *
 * A group is: the place its list is read from ("root": the settings of the module or the parameters), the keys that
 * lead to the list ("path"), the language string that names it ("label"), the key of the title of each item
 * ("title", empty when an item has none) and the base name of the field that holds its text ("body", read the way
 * an editor field is).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_preview_declarations {
    /** @var string The list is inside the settings of the module. */
    public const ROOT_SETTINGS = 'mod_settings';

    /** @var string The list is a parameter of the activity itself. */
    public const ROOT_PARAMETERS = 'parameters';

    /** @var array[] The groups every type shows, by name of the module. */
    private const GROUPS = [
        'assign' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['rubric', 'criteria'], 'label' => 'courseai_settingspreview_rubric',
                'title' => 'description', 'body' => ''],
        ],
        'book' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['chapters'], 'label' => 'courseai_settingspreview_chapters',
                'title' => 'title', 'body' => 'content'],
        ],
        'choice' => [
            ['root' => self::ROOT_PARAMETERS, 'path' => ['option'], 'label' => 'courseai_settingspreview_options',
                'title' => '', 'body' => ''],
        ],
        'data' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['fields'], 'label' => 'courseai_settingspreview_fields',
                'title' => 'name', 'body' => 'description'],
        ],
        'feedback' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['questions'], 'label' => 'courseai_settingspreview_questions',
                'title' => 'name', 'body' => ''],
        ],
        'forum' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['discussions'], 'label' => 'courseai_settingspreview_discussions',
                'title' => 'subject', 'body' => 'message'],
        ],
        'glossary' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['entries'], 'label' => 'courseai_settingspreview_entries',
                'title' => 'concept', 'body' => 'definition'],
        ],
        'lesson' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['pages'], 'label' => 'courseai_settingspreview_pages',
                'title' => 'title', 'body' => 'content_html'],
        ],
        'quiz' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['questions'], 'label' => 'courseai_settingspreview_questions',
                'title' => 'name', 'body' => 'questiontext'],
        ],
        'wiki' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['pages'], 'label' => 'courseai_settingspreview_pages',
                'title' => 'title', 'body' => 'newcontent'],
        ],
        'workshop' => [
            ['root' => self::ROOT_SETTINGS, 'path' => ['criteria'], 'label' => 'courseai_settingspreview_criteria',
                'title' => 'description', 'body' => ''],
        ],
    ];

    /** @var array[] The plain facts of the parameters each type shows, as [key of the parameter, language string]. */
    private const FACTS = [
        'url' => [['externalurl', 'courseai_settingspreview_address']],
    ];

    /**
     * The groups of lists the preview of a type shows.
     *
     * @param string $modname Name of the module.
     * @return array[] Empty for a type that shows its description only.
     */
    public static function groups_of(string $modname): array {
        return self::GROUPS[$modname] ?? [];
    }

    /**
     * The facts of the parameters the preview of a type shows.
     *
     * @param string $modname Name of the module.
     * @return array[] [key of the parameter, language string] for each fact.
     */
    public static function facts_of(string $modname): array {
        return self::FACTS[$modname] ?? [];
    }

    /**
     * The names of the modules that have an entry of their own.
     *
     * @return string[]
     */
    public static function modnames(): array {
        $withgroups = array_keys(self::GROUPS);
        $withfacts = array_keys(self::FACTS);
        $all = array_merge($withgroups, $withfacts);
        return array_values(array_unique($all));
    }
}
