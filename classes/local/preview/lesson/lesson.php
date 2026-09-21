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

use local_coursegen\local\preview\json_store;
use moodle_url;

/**
 * A lesson, read from the payload the way mod_lesson reads it from the database.
 *
 * Ported from mod/lesson/locallib.php (class lesson, Moodle 4.5): the part a
 * view of a content page uses. load_page(), load_all_pages() and the page
 * type manager are the original's; what changed is where the rows come from
 * (the json_store handed in instead of $DB), who can manage (the reader is
 * previewing as the person who will create it, so yes), and where the links
 * go (to the preview, through the builder handed in, instead of to view.php).
 *
 * @package    local_coursegen
 * @copyright  2009 Sam Hemelryk, 2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson extends lesson_base {
    // The jumps a lesson saves, named as mod/lesson/locallib.php:34-47 names
    // them. Copied rather than required, because requiring that file loads
    // the whole of mod_lesson's own classes for the sake of six integers.
    /** @var int This page. */
    const THISPAGE = 0;
    /** @var int Next page: any page not seen before. */
    const UNSEENPAGE = 1;
    /** @var int Next page: any page not answered correctly. */
    const UNANSWEREDPAGE = 2;
    /** @var int Jump to next page. */
    const NEXTPAGE = -1;
    /** @var int End of lesson. */
    const EOL = -9;
    /** @var int Jump to previous page. */
    const PREVIOUSPAGE = -40;

    /** @var json_store Where the rows are read from. */
    protected json_store $store;

    /** @var \stdClass The course module, as much of one as the payload gives. */
    protected $cm;

    /** @var callable(int $pageid): moodle_url Where a link to a page goes. */
    protected $urls;

    /** @var callable(): moodle_url Where leaving the lesson goes. */
    protected $exit;

    /** @var int|null The module context the payload names, for file URLs. */
    protected ?int $contextid;

    /** @var lesson_page[] Pages loaded so far, by id. */
    protected $pages = [];

    /** @var bool Whether every page has been loaded. */
    protected $loadedallpages = false;

    /**
     * Constructor.
     *
     * @param \stdClass|array $properties The lesson row.
     * @param json_store $store
     * @param \stdClass $cm
     * @param callable $urls pageid => moodle_url of that page in the preview.
     * @param callable $exit () => moodle_url of where the end of the lesson goes.
     * @param int|null $contextid The module context id the payload names.
     */
    public function __construct($properties, json_store $store, \stdClass $cm, callable $urls, callable $exit, ?int $contextid) {
        parent::__construct($properties);
        $this->store = $store;
        $this->cm = $cm;
        $this->urls = $urls;
        $this->exit = $exit;
        $this->contextid = $contextid;
    }

    /**
     * The store the lesson's rows are read from.
     *
     * @return json_store
     */
    public function get_store(): json_store {
        return $this->store;
    }

    /**
     * The module context id the payload names, or null.
     *
     * @return int|null
     */
    public function get_contextid(): ?int {
        return $this->contextid;
    }

    /**
     * The course module.
     *
     * @return \stdClass
     */
    public function get_cm() {
        return $this->cm;
    }

    /**
     * Whether the reader may manage the lesson.
     *
     * The preview is read by the person who will create the lesson, and the
     * page they compare it against is the one they see as its manager.
     *
     * @return bool
     */
    public function can_manage() {
        return true;
    }

    /**
     * Where a page is opened in the preview.
     *
     * @param int $pageid
     * @return moodle_url
     */
    public function page_url(int $pageid): moodle_url {
        return ($this->urls)($pageid);
    }

    /**
     * Where a jump from a page leads in the preview.
     *
     * A jump names a page, or one of the lesson's own directions: the next
     * page, the previous one, this one, or the end. The end leaves the lesson.
     *
     * @param lesson_page $page The page the jump is made from.
     * @param int $jumpto
     * @return moodle_url
     */
    public function jump_url(lesson_page $page, int $jumpto): moodle_url {
        if ($jumpto > 0) {
            return $this->page_url($jumpto);
        }
        if ($jumpto === self::THISPAGE) {
            return $this->page_url((int) $page->id);
        }
        if ($jumpto === self::NEXTPAGE || $jumpto === self::UNSEENPAGE || $jumpto === self::UNANSWEREDPAGE) {
            return (int) $page->nextpageid > 0 ? $this->page_url((int) $page->nextpageid) : ($this->exit)();
        }
        if ($jumpto === self::PREVIOUSPAGE) {
            return (int) $page->prevpageid > 0 ? $this->page_url((int) $page->prevpageid) : ($this->exit)();
        }
        return ($this->exit)();
    }

    /**
     * Loads the requested page.
     *
     * @param int $pageid
     * @return lesson_page
     */
    public function load_page($pageid) {
        if (!array_key_exists($pageid, $this->pages)) {
            $manager = lesson_page_type_manager::get($this);
            $this->pages[$pageid] = $manager->load_page($pageid, $this);
        }
        return $this->pages[$pageid];
    }

    /**
     * Loads ALL of the pages for this lesson, in the order they are walked.
     *
     * @return lesson_page[]
     */
    public function load_all_pages() {
        if (!$this->loadedallpages) {
            $manager = lesson_page_type_manager::get($this);
            $this->pages = $manager->load_all_pages($this);
            $this->loadedallpages = true;
        }
        return $this->pages;
    }
}
