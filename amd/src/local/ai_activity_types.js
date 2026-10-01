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
 * The activity types the AI content service can write content for.
 *
 * @module     local_coursegen/local/ai_activity_types
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Every activity type the AI content service has a content contract for.
 *
 * Mirrors local_coursegen\local\ai_activity_types::MODNAMES (PHP) and the
 * ActivityPromptRegistry of the course_ai service, which has one prompt file
 * per modname; the three must be kept equal. A test checks this list against
 * the PHP one.
 *
 * @type {string[]} Module names, alphabetical.
 */
export const MODNAMES = [
    'assign', 'book', 'choice', 'data', 'feedback', 'folder', 'forum',
    'glossary', 'h5pactivity', 'imscp', 'label', 'lesson', 'page', 'quiz',
    'resource', 'scorm', 'url', 'wiki', 'workshop',
];
