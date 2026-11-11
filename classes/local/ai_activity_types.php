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
 * The activity types the AI content service can write content for.
 *
 * A type is supported when the service has a content contract for it and the
 * module is installed and enabled on this site. Every template allows all of
 * them: the admin does not narrow the list per template.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_activity_types {
    /**
     * Every activity type the AI content service has a content contract for.
     *
     * Mirrors the ActivityPromptRegistry of the course_ai service, which has
     * one prompt file per modname in app/agents/activity_prompts/, and must be
     * kept in sync with it. It decides which types offer "Modify with AI" on an
     * activity a template already contains, which can be marked as a mold, and
     * which a professor may add as new activities: never everything installed on
     * the site, since the service can only be asked for content of a type it has
     * a contract for.
     *
     * @var string[] Module names, alphabetical.
     */
    public const MODNAMES = [
        'assign', 'book', 'choice', 'data', 'feedback', 'folder', 'forum',
        'glossary', 'h5pactivity', 'imscp', 'label', 'lesson', 'page', 'quiz',
        'resource', 'scorm', 'url', 'wiki', 'workshop',
    ];

    /**
     * Module names that are both AI-supported and available on this site.
     *
     * Follows the order of MODNAMES, which is alphabetical.
     *
     * @return string[] Module names, e.g. ['book', 'forum', ...].
     */
    public static function installed(): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $typenames = get_module_types_names();
        $installed = [];
        foreach (self::MODNAMES as $modname) {
            if (isset($typenames[$modname])) {
                $installed[] = $modname;
            }
        }
        return $installed;
    }
}
