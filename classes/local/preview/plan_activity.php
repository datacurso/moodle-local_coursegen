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
 * Turns one plan entry into the shape an activity preview reads.
 *
 * A run is previewable twice over, and the two moments hold the same activity
 * in different shapes. While it is being reviewed it exists as a plan: the
 * mould's own markup with a draft written into it, one entry per part. Once it
 * has been generated it exists as the module's real parameters.
 *
 * The previews are written against the second shape, because that is the shape
 * the activity is finally built from. This converts the first into it, so the
 * same preview draws the draft and the finished thing, and what the teacher
 * approves looks like what they will get for the reason that it is drawn by
 * the same code.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plan_activity {
    /**
     * The activity parameters a plan entry stands for.
     *
     * @param array $entry One entry of template_plan.
     * @return array Parameters in the same shape the generated answer uses.
     */
    public static function to_parameters(array $entry): array {
        $parts = $entry['parts'] ?? [];
        $name = (string) ($entry['name'] ?? '');

        if (($entry['resource_type'] ?? '') === 'lesson') {
            $pages = [];
            foreach ($parts as $part) {
                $pages[] = [
                    'page_type' => 'content',
                    'title' => (string) ($part['title'] ?? ''),
                    'content_html' => (string) ($part['html'] ?? ''),
                    'buttons' => [],
                ];
            }
            return ['name' => $name, 'mod_settings' => ['pages' => $pages]];
        }

        // Every other type keeps its text in one field, so the parts are simply
        // concatenated in the order the mould authored them.
        $html = '';
        foreach ($parts as $part) {
            $html .= (string) ($part['html'] ?? '');
        }
        return [
            'name' => $name,
            'introeditor' => ['text' => $html, 'format' => FORMAT_HTML, 'itemid' => 0],
            'page' => $html,
        ];
    }

}
