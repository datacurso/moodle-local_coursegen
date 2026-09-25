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

namespace local_coursegen\local\preview\lesson;

/**
 * Knows which class draws which page type, and loads pages from the store.
 *
 * Copied from mod/lesson/locallib.php (class lesson_page_type_manager, Moodle
 * 4.5): load_page() and load_all_pages(), reading the store instead of $DB
 * and never repairing a page order on the way, because a preview changes
 * nothing. Only the content page has its own class: it is the one type the
 * moulds carry, and the one whose view is content rather than a question form.
 * Any other type is drawn as a content page, which shows its contents and its
 * jumps, and says so in its docblock rather than pretending otherwise.
 *
 * @package    local_coursegen
 * @copyright  2009 Sam Hemelryk, 2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_page_type_manager {
    /** @var array qtype => class name. */
    protected $types = [
        20 => lesson_page_type_branchtable::class, // LESSON_PAGE_BRANCHTABLE.
    ];

    /**
     * The manager.
     *
     * @param lesson $lesson
     * @return lesson_page_type_manager
     */
    public static function get(lesson $lesson) {
        static $pagetypemanager;
        if (!($pagetypemanager instanceof lesson_page_type_manager)) {
            $pagetypemanager = new lesson_page_type_manager();
        }
        return $pagetypemanager;
    }

    /**
     * The class that draws a page of this type.
     *
     * @param mixed $qtype
     * @return string
     */
    protected function class_for($qtype): string {
        return $this->types[(int) $qtype] ?? lesson_page_type_branchtable::class;
    }

    /**
     * Loads one page.
     *
     * @param int $pageid
     * @param lesson $lesson
     * @return lesson_page
     */
    public function load_page($pageid, lesson $lesson) {
        if (!($page = $lesson->get_store()->get_record('lesson_pages', ['id' => $pageid, 'lessonid' => $lesson->id]))) {
            throw new \moodle_exception('cannotfindpages', 'lesson');
        }
        $pagetype = $this->class_for($page->qtype);
        return new $pagetype($page, $lesson);
    }

    /**
     * Every page, in the order they are walked.
     *
     * @param lesson $lesson
     * @return lesson_page[]
     */
    public function load_all_pages(lesson $lesson) {
        if (!($pages = $lesson->get_store()->get_records('lesson_pages', ['lessonid' => $lesson->id]))) {
            return []; // Records returned empty.
        }
        $pages = $this->typed_pages($pages, $lesson);
        $orderedpages = $this->walk_chain($pages);
        return $this->with_remaining_pages($orderedpages, $pages);
    }

    /**
     * Every raw row, as its own page type's object.
     *
     * @param array $pages
     * @param lesson $lesson
     * @return lesson_page[]
     */
    private function typed_pages(array $pages, lesson $lesson): array {
        foreach ($pages as $key => $page) {
            $pagetype = $this->class_for($page->qtype);
            $pages[$key] = new $pagetype($page, $lesson);
        }
        return $pages;
    }

    /**
     * The page, among the given ones, whose prevpageid names the last page
     * walked so far, if any.
     *
     * @param lesson_page[] $pages
     * @param int $lastpageid
     * @return lesson_page|null
     */
    private function next_in_chain(array $pages, int $lastpageid) {
        foreach ($pages as $page) {
            if ((int) $page->prevpageid === $lastpageid) {
                return $page;
            }
        }
        return null;
    }

    /**
     * Every page reachable by following prevpageid/nextpageid from the start,
     * in the order the chain walks them. Walked pages are removed from
     * $pages, so what is left afterwards is whatever the chain never reached.
     *
     * @param lesson_page[] $pages Passed by reference: walked pages are removed.
     * @return lesson_page[]
     */
    private function walk_chain(array &$pages): array {
        $orderedpages = [];
        $lastpageid = 0;
        $more = true;
        while ($more) {
            $next = $this->next_in_chain($pages, $lastpageid);
            if ($next === null) {
                $more = false;
                continue;
            }
            $orderedpages[$next->id] = $next;
            unset($pages[$next->id]);
            $lastpageid = $next->id;
            if ((int) $next->nextpageid === 0) {
                $more = false;
            }
        }
        return $orderedpages;
    }

    /**
     * The walked pages, with whatever the chain did not reach appended after.
     *
     * @param lesson_page[] $orderedpages
     * @param lesson_page[] $remaining
     * @return lesson_page[]
     */
    private function with_remaining_pages(array $orderedpages, array $remaining): array {
        foreach ($remaining as $page) {
            $orderedpages[$page->id] = $page;
        }
        return $orderedpages;
    }
}
