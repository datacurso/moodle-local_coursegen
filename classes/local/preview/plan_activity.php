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
                    // The piece of the mould this one fills, named by the id
                    // the mould's own module gave it.
                    'id' => self::element_id((string) ($part['key'] ?? '')),
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

    /**
     * A draft with the navigation of the mould it will be written into.
     *
     * A plan says what each piece of an activity will say. How a reader moves
     * between those pieces is not the plan's to decide: the mould settled it,
     * and the delivered activity inherits it, so a preview that invented its
     * own buttons would be showing navigation nobody will ever see.
     *
     * The pieces line up with the mould's in order, because that is the order
     * they were planned in, one for each piece the mould offered.
     *
     * @param array $parameters The draft, as to_parameters() built it.
     * @param string $modname
     * @param int $sourcecmid The mould this activity is written into.
     * @param \local_coursegen\local\models\course_session $session
     * @return array
     */
    public static function with_mould_navigation(
        array $parameters,
        string $modname,
        int $sourcecmid,
        \local_coursegen\local\models\course_session $session
    ): array {
        if ($modname !== 'lesson' || $sourcecmid === 0 || empty($parameters['mod_settings']['pages'])) {
            return $parameters;
        }

        $coursedata = json_decode((string) $session->get('coursedata'), true);
        $templateid = (int) ($coursedata['templateid'] ?? 0);
        if ($templateid <= 0) {
            return $parameters;
        }

        $mould = [];
        $payload = \local_coursegen\local\service\template_export_service::build_init_payload($templateid);
        foreach (($payload['activities'] ?? []) as $activity) {
            if ((int) ($activity['cmid'] ?? 0) === $sourcecmid) {
                $mould = real_activity::to_parameters($activity)['mod_settings']['pages'] ?? [];
                break;
            }
        }
        if (!$mould) {
            return $parameters;
        }

        // Each piece is matched to the piece of the mould it fills, by the
        // id it carries, rather than by where it sits: a mould offers pieces
        // to fill and pieces to leave alone, so the two lists are not the
        // same length and counting through them lines up the wrong pages.
        $bymouldid = [];
        foreach ($mould as $page) {
            $bymouldid[(string) ($page['id'] ?? '')] = $page;
        }

        foreach ($parameters['mod_settings']['pages'] as $index => $page) {
            $from = $bymouldid[(string) ($page['id'] ?? '')] ?? null;
            if ($from === null) {
                continue;
            }
            $page['buttons'] = $from['buttons'] ?? [];
            $page['layout'] = $from['layout'] ?? 1;
            $parameters['mod_settings']['pages'][$index] = $page;
        }
        return $parameters;
    }

    /**
     * The id inside the name a plan gives one piece of a mould.
     *
     * A piece is named for what it is and which one it is, as in "page-24399",
     * so the activity's own id for it is the tail.
     *
     * @param string $key
     * @return int|null
     */
    private static function element_id(string $key): ?int {
        $at = strrpos($key, '-');
        if ($at === false) {
            return null;
        }
        $id = substr($key, $at + 1);
        return ctype_digit($id) ? (int) $id : null;
    }
}
