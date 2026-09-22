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
        foreach ($pages as $key => $page) {
            $pagetype = $this->class_for($page->qtype);
            $pages[$key] = new $pagetype($page, $lesson);
        }

        $orderedpages = [];
        $lastpageid = 0;
        $morepages = true;
        while ($morepages) {
            $morepages = false;
            foreach ($pages as $page) {
                if ((int) $page->prevpageid === (int) $lastpageid) {
                    $morepages = true;
                    $orderedpages[$page->id] = $page;
                    unset($pages[$page->id]);
                    $lastpageid = $page->id;
                    if ((int) $page->nextpageid === 0) {
                        break 2;
                    } else {
                        break 1;
                    }
                }
            }
        }

        // Add remaining pages, which the chain did not reach.
        foreach ($pages as $page) {
            $orderedpages[$page->id] = $page;
        }

        return $orderedpages;
    }
}
