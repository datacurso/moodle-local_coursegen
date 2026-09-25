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
            $orderedpages = self::lesson_pages_in_order($root);
            $pages = [];
            foreach ($orderedpages as $page) {
                $buttons = self::buttons_of($page);
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
                    'buttons' => $buttons,
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
        $pages = self::flatten_lesson_pages($root);
        if (!$pages) {
            return [];
        }
        $byid = self::lesson_pages_by_id($pages);
        $first = self::first_lesson_page($pages);
        $ordered = self::walk_lesson_page_chain($first, $byid);
        return self::append_orphan_pages($ordered, $pages);
    }

    /**
     * Every page of a lesson, still in the backup's own grouping.
     *
     * @param array $root
     * @return array
     */
    private static function flatten_lesson_pages(array $root): array {
        $pages = [];
        foreach (($root['pages'] ?? []) as $group) {
            $pages = array_merge($pages, $group['page'] ?? []);
        }
        return $pages;
    }

    /**
     * Every page, keyed by its own id.
     *
     * @param array $pages
     * @return array
     */
    private static function lesson_pages_by_id(array $pages): array {
        $byid = [];
        foreach ($pages as $page) {
            $byid[(string) ($page['id'] ?? '')] = $page;
        }
        return $byid;
    }

    /**
     * The page nobody names as "next": where the walk starts.
     *
     * @param array $pages
     * @return array|null
     */
    private static function first_lesson_page(array $pages): ?array {
        foreach ($pages as $page) {
            if ((string) ($page['prevpageid'] ?? '0') === '0') {
                return $page;
            }
        }
        return null;
    }

    /**
     * Walks the chain from a page to the one it names as next, until it
     * loops back on a page already walked.
     *
     * @param array|null $current
     * @param array $byid
     * @return array
     */
    private static function walk_lesson_page_chain(?array $current, array $byid): array {
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
        return $ordered;
    }

    /**
     * Pages the chain walk never reached, appended after the ones it did.
     *
     * @param array $ordered
     * @param array $pages
     * @return array
     */
    private static function append_orphan_pages(array $ordered, array $pages): array {
        $seen = self::lesson_page_ids($ordered);
        foreach ($pages as $page) {
            if (!isset($seen[(string) ($page['id'] ?? '')])) {
                $ordered[] = $page;
            }
        }
        return $ordered;
    }

    /**
     * The ids of a list of pages, as lookup keys.
     *
     * @param array $pages
     * @return array
     */
    private static function lesson_page_ids(array $pages): array {
        $ids = [];
        foreach ($pages as $page) {
            $ids[(string) ($page['id'] ?? '')] = true;
        }
        return $ids;
    }

    /**
     * One page's navigation, which mod_lesson keeps as that page's answers.
     *
     * @param array $page
     * @return array
     */
    private static function buttons_of(array $page): array {
        $answers = self::lesson_answers_of($page);
        $buttons = [];
        foreach ($answers as $answer) {
            $text = html_to_text((string) ($answer['answer_text'] ?? ''), 0, false);
            $buttons[] = [
                'text' => $text,
                'jumpto' => $answer['jumpto'] ?? null,
            ];
        }
        return $buttons;
    }

    /**
     * A page's answers, still in the backup's own grouping.
     *
     * @param array $page
     * @return array
     */
    private static function lesson_answers_of(array $page): array {
        $answers = [];
        foreach (($page['answers'] ?? []) as $group) {
            $answers = array_merge($answers, $group['answer'] ?? []);
        }
        return $answers;
    }
}
