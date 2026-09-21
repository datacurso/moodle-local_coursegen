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
 * One page of a lesson, read the way mod_lesson reads it.
 *
 * Ported from mod/lesson/locallib.php (abstract class lesson_page, Moodle
 * 4.5): the part a view uses. get_answers() reads the store instead of $DB;
 * get_contents() rewrites file URLs against the module context the payload
 * names instead of $PAGE->cm, and filters against the page's context.
 *
 * @package    local_coursegen
 * @copyright  2009 Sam Hemelryk, 2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class lesson_page extends lesson_base {
    /** @var lesson The lesson this page belongs to. */
    protected $lesson = null;

    /** @var lesson_page_answer[]|null The answers, once read. */
    protected $answers = null;

    /**
     * Constructor.
     *
     * @param \stdClass $properties
     * @param lesson $lesson
     */
    public function __construct($properties, lesson $lesson) {
        parent::__construct($properties);
        $this->lesson = $lesson;
    }

    /**
     * The id mod_lesson gives this page type.
     *
     * @return int
     */
    abstract protected function get_typeid();

    /**
     * The page, as HTML.
     *
     * @param \renderer_base $renderer
     * @param mixed $attempt
     * @return string
     */
    abstract public function display($renderer, $attempt);

    /**
     * The answers of this page, in id order.
     *
     * @return lesson_page_answer[]
     */
    final public function get_answers() {
        if ($this->answers === null) {
            $this->answers = [];
            $answers = $this->lesson->get_store()->get_records(
                'lesson_answers',
                ['pageid' => $this->properties->id, 'lessonid' => $this->lesson->id],
                'id'
            );
            if (!$answers) {
                return [];
            }
            foreach ($answers as $answer) {
                $this->answers[count($this->answers)] = new lesson_page_answer($answer);
            }
        }
        return $this->answers;
    }

    /**
     * The lesson this page belongs to.
     *
     * @return lesson
     */
    final protected function get_lesson() {
        return $this->lesson;
    }

    /**
     * The page's contents, with its files reachable and its text filtered.
     *
     * @return string
     */
    public function get_contents() {
        global $PAGE;
        if (!empty($this->properties->contents)) {
            if (!isset($this->properties->contentsformat)) {
                $this->properties->contentsformat = FORMAT_HTML;
            }
            $context = $PAGE->context;
            // The files a page refers to belong to the module the payload
            // describes, so they are addressed by the context it names.
            $contextid = $this->lesson->get_contextid() ?? $context->id;
            $contents = file_rewrite_pluginfile_urls($this->properties->contents, 'pluginfile.php', $contextid, 'mod_lesson',
                                                     'page_contents', $this->properties->id);  // Must do this BEFORE format_text()!
            return format_text($contents, $this->properties->contentsformat,
                               ['context' => $context, 'noclean' => true,
                                'overflowdiv' => true]);  // Page edit is marked with XSS, we want all content here.
        } else {
            return '';
        }
    }

    /**
     * Whether this page is listed in the lesson menu.
     *
     * @return bool
     */
    protected function get_displayinmenublock() {
        return false;
    }
}
