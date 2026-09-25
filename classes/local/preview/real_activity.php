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
 * The pieces of text are laid out in the shape the previews already read,
 * which is the shape a plan arrives in.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class real_activity {
    /** @var string[] Where a module keeps text somebody wrote, in reading order. */
    private const TEXT_FIELDS = [
        'contents', 'content', 'definition', 'questiontext', 'intro', 'page', 'summary', 'message', 'text',
    ];

    /** @var string[] What an element calls itself, in the same order. */
    private const TITLE_FIELDS = ['title', 'name', 'concept', 'subject', 'heading'];

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
            $pages = self::lesson_pages_from_root($root);
            return ['name' => $name, 'mod_settings' => ['pages' => $pages]];
        }

        // Every other type keeps its text in one field or in a list of them,
        // and its preview reads one body, so the pieces are laid end to end in
        // the order the module declares them.
        $html = self::concatenated_text($root);
        return [
            'name' => $name,
            'introeditor' => ['text' => $html, 'format' => FORMAT_HTML, 'itemid' => 0],
            'page' => $html,
        ];
    }

    /**
     * A lesson's pages, in the shape its preview reads.
     *
     * @param array $root
     * @return array
     */
    private static function lesson_pages_from_root(array $root): array {
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
        return $pages;
    }

    /**
     * Every text part of the activity, laid end to end.
     *
     * @param array $root
     * @return string
     */
    private static function concatenated_text(array $root): string {
        $html = '';
        foreach (self::text_parts($root) as $part) {
            $html .= $part;
        }
        return $html;
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
        $pages = self::flatten_pages($root);
        if (!$pages) {
            return [];
        }

        $byid = self::pages_by_id($pages);
        $chained = self::chain_from_first_page($pages, $byid);
        return array_merge($chained['ordered'], self::unlinked_pages($pages, $chained['seen']));
    }

    /**
     * Every page of every group under a lesson's own root element.
     *
     * @param array $root
     * @return array
     */
    private static function flatten_pages(array $root): array {
        $pages = [];
        foreach (($root['pages'] ?? []) as $group) {
            $pages = array_merge($pages, $group['page'] ?? []);
        }
        return $pages;
    }

    /**
     * Pages keyed by their own id.
     *
     * @param array $pages
     * @return array
     */
    private static function pages_by_id(array $pages): array {
        $byid = [];
        foreach ($pages as $page) {
            $byid[(string) ($page['id'] ?? '')] = $page;
        }
        return $byid;
    }

    /**
     * The page whose chain starts the lesson (prevpageid 0), or null when none does.
     *
     * @param array $pages
     * @return array|null
     */
    private static function first_page(array $pages): ?array {
        foreach ($pages as $page) {
            if ((string) ($page['prevpageid'] ?? '0') === '0') {
                return $page;
            }
        }
        return null;
    }

    /**
     * Walk the page chain from its first page, following nextpageid.
     *
     * @param array $pages
     * @param array $byid
     * @return array {ordered: array, seen: array} Seen is keyed by page id.
     */
    private static function chain_from_first_page(array $pages, array $byid): array {
        $current = self::first_page($pages);
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
        return ['ordered' => $ordered, 'seen' => $seen];
    }

    /**
     * Pages the chain walk never reached, in their original order.
     *
     * @param array $pages
     * @param array $seen Page id => true, from chain_from_first_page().
     * @return array
     */
    private static function unlinked_pages(array $pages, array $seen): array {
        $orphans = [];
        foreach ($pages as $page) {
            if (!isset($seen[(string) ($page['id'] ?? '')])) {
                $orphans[] = $page;
            }
        }
        return $orphans;
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
            $buttons = array_merge($buttons, self::buttons_of_group($group));
        }
        return $buttons;
    }

    /**
     * One answer group's buttons.
     *
     * @param array $group
     * @return array
     */
    private static function buttons_of_group(array $group): array {
        $buttons = [];
        foreach (($group['answer'] ?? []) as $answer) {
            $buttons[] = [
                'text' => html_to_text((string) ($answer['answer_text'] ?? ''), 0, false),
                'jumpto' => $answer['jumpto'] ?? null,
            ];
        }
        return $buttons;
    }

    /**
     * Every piece of written text in the activity, outermost first.
     *
     * @param array $node
     * @return string[]
     */
    private static function text_parts(array $node): array {
        $parts = [];
        $matched = self::matched_text_part($node);
        if ($matched !== null) {
            $parts[] = $matched;
        }
        return array_merge($parts, self::child_text_parts($node));
    }

    /**
     * The first TEXT_FIELDS value this node itself carries, headed by the
     * node's own title when it has one.
     *
     * @param array $node
     * @return string|null
     */
    private static function matched_text_part(array $node): ?string {
        foreach (self::TEXT_FIELDS as $field) {
            $value = $node[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $title = self::title_of($node);
                if ($title === '') {
                    return $value;
                }
                return \html_writer::tag('h4', $title) . $value;
            }
        }
        return null;
    }

    /**
     * Every text part carried by this node's own child elements.
     *
     * @param array $node
     * @return string[]
     */
    private static function child_text_parts(array $node): array {
        $parts = [];
        foreach ($node as $value) {
            if (is_array($value)) {
                $parts = array_merge($parts, self::text_parts_of_children($value));
            }
        }
        return $parts;
    }

    /**
     * Every text part carried by one child list.
     *
     * @param array $children
     * @return string[]
     */
    private static function text_parts_of_children(array $children): array {
        $parts = [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $parts = array_merge($parts, self::text_parts($child));
            }
        }
        return $parts;
    }

    /**
     * What an element calls itself.
     *
     * @param array $node
     * @return string
     */
    private static function title_of(array $node): string {
        foreach (self::TITLE_FIELDS as $field) {
            $value = $node[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return format_string($value);
            }
        }
        return '';
    }
}
