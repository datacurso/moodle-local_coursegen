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
 * mod_lesson is the one type handled in full here, because it is the one
 * type whose backup shape is not already the shape a preview reads: it does
 * not number its pages, it chains them by prevpageid/nextpageid, and it
 * wraps both its pages and each page's answers in Moodle's own two-level
 * backup group ("pages" holding "page", "answers" holding "answer"). Every
 * private method below exists to undo exactly one of those three facts.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class kept_activity {
    /**
     * One activity of the payload, in the shape its preview reads.
     *
     * Example, for a kept lesson named "My Lesson" with one page:
     * ```php
     * $activity = [
     *     'resource_type' => 'lesson',
     *     'parameters' => [
     *         'name' => 'My Lesson',
     *         'structure' => ['lesson' => [['pages' => [...]]]],
     *     ],
     * ];
     * // returns:
     * ['name' => 'My Lesson', 'mod_settings' => ['pages' => [...]]]
     * ```
     *
     * For any other module type, returns instead:
     * ```php
     * ['name' => ..., 'introeditor' => ['text' => ..., ...], 'page' => ...]
     * ```
     *
     * @param array $activity The activity as the payload describes it.
     * @return array
     */
    public static function to_parameters(array $activity): array {
        $modname = $activity['resource_type'] ?? '';
        $modname = (string) $modname;

        $parameters = $activity['parameters'] ?? [];
        $structure = $parameters['structure'] ?? [];
        $modstructure = $structure[$modname] ?? [];
        $root = $modstructure[0] ?? [];

        $name = $parameters['name'] ?? '';
        $name = (string) $name;
        $name = format_string($name);

        if ($modname === 'lesson') {
            return self::lesson_activity_parameters($root, $name);
        }

        // Every module table carries its own intro, and a type with no
        // dedicated preview class shows only that: this is what
        // intro_preview::render() reads.
        $intro = $root['intro'] ?? '';
        $intro = (string) $intro;
        return [
            'name' => $name,
            'introeditor' => ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0],
            'page' => $intro,
        ];
    }

    /**
     * A lesson's parameters, in the shape its preview reads.
     *
     * Example:
     * ```php
     * $root = ['pages' => [['page' => [$pageA, $pageB]]]];
     * $name = 'My Lesson';
     * // returns:
     * ['name' => 'My Lesson', 'mod_settings' => ['pages' => [entryA, entryB]]]
     * ```
     *
     * @param array $root The lesson's own raw backup node.
     * @param string $name The activity's already-formatted name.
     * @return array
     */
    private static function lesson_activity_parameters(array $root, string $name): array {
        $orderedpages = self::lesson_pages_in_order($root);
        $pages = [];
        foreach ($orderedpages as $page) {
            $pages[] = self::lesson_page_entry($page);
        }
        return ['name' => $name, 'mod_settings' => ['pages' => $pages]];
    }

    /**
     * One lesson page, in the shape its preview reads.
     *
     * Example:
     * ```php
     * $page = [
     *     'id' => 101, 'title' => 'Page A', 'contents' => '<p>Welcome</p>',
     *     'layout' => 1, 'qtype' => 20, 'display' => 1,
     *     'prevpageid' => '0', 'nextpageid' => '102',
     *     'answers' => [['answer' => [['answer_text' => 'Continue', 'jumpto' => 102]]]],
     * ];
     * // returns:
     * [
     *     'id' => 101, 'layout' => 1, 'qtype' => 20, 'display' => 1,
     *     'page_type' => 'content', 'title' => 'Page A',
     *     'content_html' => '<p>Welcome</p>',
     *     'buttons' => [['text' => 'Continue', 'jumpto' => 102]],
     * ]
     * ```
     *
     * @param array $page One raw page node, as lesson_pages_in_order() returns it.
     * @return array
     */
    private static function lesson_page_entry(array $page): array {
        $buttons = self::buttons_of($page);

        $title = $page['title'] ?? '';
        $title = (string) $title;

        $contenthtml = $page['contents'] ?? '';
        $contenthtml = (string) $contenthtml;

        return [
            // The page's own id and layout, because a page's buttons
            // are jumps to pages and a page says how they are laid out.
            'id' => $page['id'] ?? null,
            'layout' => $page['layout'] ?? 1,
            // What kind of page it is and whether it is shown, which
            // is what decides if the lesson's menu lists it.
            'qtype' => $page['qtype'] ?? null,
            'display' => $page['display'] ?? 1,
            'page_type' => 'content',
            'title' => $title,
            'content_html' => $contenthtml,
            'buttons' => $buttons,
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
     * Example:
     * ```php
     * // $pageA: id 101, prevpageid '0', nextpageid '102'.
     * // $pageB: id 102, prevpageid '101', nextpageid '0'.
     * $root = ['pages' => [['page' => [$pageA, $pageB]]]];
     * // returns:
     * [$pageA, $pageB]
     * ```
     *
     * @param array $root The lesson's own raw backup node (one "structure" entry).
     * @return array The raw page nodes, walk-ordered.
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
     * Example:
     * ```php
     * $root = ['pages' => [['page' => [$pageA, $pageB]]]];
     * // returns:
     * [$pageA, $pageB]
     * ```
     *
     * @param array $root The lesson's own raw backup node.
     * @return array The raw page nodes, in no particular order.
     */
    private static function flatten_lesson_pages(array $root): array {
        $pages = [];
        $groups = $root['pages'] ?? [];
        foreach ($groups as $group) {
            $grouppages = $group['page'] ?? [];
            $pages = array_merge($pages, $grouppages);
        }
        return $pages;
    }

    /**
     * Every page, keyed by its own id.
     *
     * Example:
     * ```php
     * $pages = [$pageA, $pageB]; // ids 101 and 102
     * // returns:
     * ['101' => $pageA, '102' => $pageB]
     * ```
     *
     * @param array $pages Raw page nodes, as flatten_lesson_pages() returns them.
     * @return array Page id (string) => raw page node.
     */
    private static function lesson_pages_by_id(array $pages): array {
        $byid = [];
        foreach ($pages as $page) {
            $id = $page['id'] ?? '';
            $id = (string) $id;
            $byid[$id] = $page;
        }
        return $byid;
    }

    /**
     * The page nobody names as "next": where the walk starts.
     *
     * Example:
     * ```php
     * $pages = [$pageA, $pageB]; // $pageA's prevpageid is '0'
     * // returns:
     * $pageA
     * ```
     *
     * @param array $pages Raw page nodes, as flatten_lesson_pages() returns them.
     * @return array|null The page whose prevpageid is '0', or null if none is.
     */
    private static function first_lesson_page(array $pages): ?array {
        foreach ($pages as $page) {
            $prevpageid = $page['prevpageid'] ?? '0';
            $prevpageid = (string) $prevpageid;
            if ($prevpageid === '0') {
                return $page;
            }
        }
        return null;
    }

    /**
     * Walks the chain from a page to the one it names as next, until it
     * loops back on a page already walked.
     *
     * Example:
     * ```php
     * $current = $pageA; // id 101, nextpageid '102'
     * $byid = ['101' => $pageA, '102' => $pageB]; // $pageB's nextpageid is '0'
     * // returns:
     * [$pageA, $pageB] // the walk stops: $byid has no id '0'
     * ```
     *
     * @param array|null $current The page to start from, e.g. first_lesson_page()'s result.
     * @param array $byid Page id (string) => raw page node, as lesson_pages_by_id() returns it.
     * @return array The raw page nodes, in walk order.
     */
    private static function walk_lesson_page_chain(?array $current, array $byid): array {
        $ordered = [];
        $seen = [];
        while ($current !== null) {
            $id = $current['id'] ?? '';
            $id = (string) $id;
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $ordered[] = $current;
            $nextid = $current['nextpageid'] ?? '0';
            $nextid = (string) $nextid;
            $current = $byid[$nextid] ?? null;
        }
        return $ordered;
    }

    /**
     * Pages the chain walk never reached, appended after the ones it did.
     *
     * Example:
     * ```php
     * $ordered = [$pageA, $pageB];
     * $pages = [$pageA, $pageB, $pageC]; // $pageC's prevpageid points at no real page
     * // returns:
     * [$pageA, $pageB, $pageC] // $pageC is still shown, just last
     * ```
     *
     * @param array $ordered Raw page nodes, as walk_lesson_page_chain() returns them.
     * @param array $pages Every raw page node, as flatten_lesson_pages() returns them.
     * @return array $ordered with any page missing from it appended at the end.
     */
    private static function append_orphan_pages(array $ordered, array $pages): array {
        $seen = self::lesson_page_ids($ordered);
        foreach ($pages as $page) {
            $id = $page['id'] ?? '';
            $id = (string) $id;
            if (!isset($seen[$id])) {
                $ordered[] = $page;
            }
        }
        return $ordered;
    }

    /**
     * The ids of a list of pages, as lookup keys.
     *
     * Example:
     * ```php
     * $pages = [$pageA, $pageB]; // ids 101 and 102
     * // returns:
     * ['101' => true, '102' => true]
     * ```
     *
     * @param array $pages Raw page nodes.
     * @return array Page id (string) => true.
     */
    private static function lesson_page_ids(array $pages): array {
        $ids = [];
        foreach ($pages as $page) {
            $id = $page['id'] ?? '';
            $id = (string) $id;
            $ids[$id] = true;
        }
        return $ids;
    }

    /**
     * One page's navigation, which mod_lesson keeps as that page's answers.
     *
     * Example:
     * ```php
     * $page = ['answers' => [['answer' => [['answer_text' => 'Continue', 'jumpto' => 102]]]]];
     * // returns:
     * [['text' => 'Continue', 'jumpto' => 102]]
     * ```
     *
     * @param array $page One raw page node.
     * @return array List of ['text' => string, 'jumpto' => mixed].
     */
    private static function buttons_of(array $page): array {
        $answers = self::lesson_answers_of($page);
        $buttons = [];
        foreach ($answers as $answer) {
            $answertext = $answer['answer_text'] ?? '';
            $answertext = (string) $answertext;
            $text = html_to_text($answertext, 0, false);
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
     * Example:
     * ```php
     * $page = ['answers' => [['answer' => [['answer_text' => 'Continue', 'jumpto' => 102]]]]];
     * // returns:
     * [['answer_text' => 'Continue', 'jumpto' => 102]]
     * ```
     *
     * @param array $page One raw page node.
     * @return array Raw answer nodes, in no particular order.
     */
    private static function lesson_answers_of(array $page): array {
        $answers = [];
        $groups = $page['answers'] ?? [];
        foreach ($groups as $group) {
            $groupanswers = $group['answer'] ?? [];
            $answers = array_merge($answers, $groupanswers);
        }
        return $answers;
    }
}
