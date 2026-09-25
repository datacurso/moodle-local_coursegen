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
 * An activity the run will keep, read for the same preview as one it will write.
 *
 * A template keeps activities as they are, and they are part of the course
 * being decided about, so they are previewed too. What is read is not the
 * activity on the site: it is the description of it that travelled in the
 * payload, which its own module produced and which says everything the
 * activity is made of.
 *
 * That matters because a preview must not reach the real thing. Reading the
 * payload instead means the page cannot, whatever it is asked for.
 *
 * The result is laid out in the shape the previews already read, which is
 * the shape a plan arrives in.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class kept_activity {
    /**
     * One activity of the payload, in the shape its preview reads.
     *
     * @param array $activity The activity as the payload describes it.
     * @return array
     */
    public static function to_parameters(array $activity): array {
        $modname = (string) ($activity['resource_type'] ?? '');
        $parameters = $activity['parameters'] ?? [];
        $root = (($parameters['structure'] ?? [])[$modname] ?? [])[0] ?? [];
        $name = format_string((string) ($parameters['name'] ?? ''));

        if ($modname === 'lesson') {
            $pages = [];
            foreach (self::lesson_pages_in_order($root) as $page) {
                $pages[] = [
                    // The page's own id and layout, because a page's buttons
                    // are jumps to pages and a page says how they are laid out.
                    'id' => $page['id'] ?? null,
                    'layout' => $page['layout'] ?? 1,
                    // What kind of page it is and whether it is shown, which
                    // is what decides if the lesson's menu lists it.
                    'qtype' => $page['qtype'] ?? null,
                    'display' => $page['display'] ?? 1,
                    'page_type' => 'content',
                    'title' => (string) ($page['title'] ?? ''),
                    'content_html' => (string) ($page['contents'] ?? ''),
                    'buttons' => self::buttons_of($page),
                ];
            }
            return ['name' => $name, 'mod_settings' => ['pages' => $pages]];
        }

        // Every module table carries its own intro, and a type with no
        // dedicated preview class shows only that: this is what
        // intro_preview::render() reads.
        $intro = (string) ($root['intro'] ?? '');
        return [
            'name' => $name,
            'introeditor' => ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0],
            'page' => $intro,
        ];
    }

    /**
     * A lesson's pages, in the order a student walks them.
     *
     * mod_lesson does not number its pages: each names the one before it and
     * the one after it, so the order is a chain to follow rather than a column
     * to sort by. A page whose chain is broken still comes back, after the
     * ones that are not.
     *
     * @param array $root
     * @return array
     */
    private static function lesson_pages_in_order(array $root): array {
        $pages = [];
        foreach (($root['pages'] ?? []) as $group) {
            $pages = array_merge($pages, $group['page'] ?? []);
        }
        if (!$pages) {
            return [];
        }

        // The page nobody names as "next" is the first one, found in the
        // same pass that indexes every page by id for the walk below.
        $byid = [];
        $current = null;
        foreach ($pages as $page) {
            $byid[(string) ($page['id'] ?? '')] = $page;
            if ($current === null && (string) ($page['prevpageid'] ?? '0') === '0') {
                $current = $page;
            }
        }

        $ordered = [];
        $seen = [];
        while ($current !== null) {
            $id = (string) ($current['id'] ?? '');
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $ordered[] = $current;
            $current = $byid[(string) ($current['nextpageid'] ?? '0')] ?? null;
        }
        foreach ($pages as $page) {
            if (!isset($seen[(string) ($page['id'] ?? '')])) {
                $ordered[] = $page;
            }
        }
        return $ordered;
    }

    /**
     * One page's navigation, which mod_lesson keeps as that page's answers.
     *
     * @param array $page
     * @return array
     */
    private static function buttons_of(array $page): array {
        $buttons = [];
        foreach (($page['answers'] ?? []) as $group) {
            foreach (($group['answer'] ?? []) as $answer) {
                $buttons[] = [
                    'text' => html_to_text((string) ($answer['answer_text'] ?? ''), 0, false),
                    'jumpto' => $answer['jumpto'] ?? null,
                ];
            }
        }
        return $buttons;
    }
}
