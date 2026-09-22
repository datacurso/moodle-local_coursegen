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
 * A content page, drawn the way mod_lesson draws it.
 *
 * Ported from mod/lesson/pagetypes/branchtable.php (class
 * lesson_page_type_branchtable::display(), Moodle 4.5). The heading, the
 * contents box and the branch buttons are the original's. What changed: each
 * button leads where its jump leads within the preview instead of posting to
 * continue.php, the slideshow wrapper is written here because the renderer's
 * own is typed on core's lesson class, and the "content page viewed" event is
 * not raised, because nothing is being viewed that exists.
 *
 * @package    local_coursegen
 * @copyright  2009 Sam Hemelryk, 2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_page_type_branchtable extends lesson_page {
    /** @var int */
    protected $typeid = 20; // LESSON_PAGE_BRANCHTABLE.

    /**
     * The id mod_lesson gives this page type.
     *
     * @return int
     */
    public function get_typeid() {
        return $this->typeid;
    }

    /**
     * The page, as HTML.
     *
     * @param \renderer_base $renderer
     * @param mixed $attempt
     * @return string
     */
    public function display($renderer, $attempt) {
        global $PAGE, $OUTPUT;

        $options = new \stdClass;
        $options->para = false;
        $options->noclean = true;

        // The heading level depends on whether the theme's activity header displays a heading (usually the activity name).
        $headinglevel = $PAGE->activityheader->get_heading_level();
        $output = $renderer->heading(format_string($this->properties->title), $headinglevel);
        $output .= $renderer->box($this->get_contents(), 'contents');

        $buttons = [];
        foreach ($this->get_answers() as $answer) {
            if ($answer->answer === '') {
                // Not a branch!
                continue;
            }
            $url = $this->lesson->jump_url($this, (int) $answer->jumpto);
            $buttons[] = $renderer->single_button($url, strip_tags(format_text($answer->answer, FORMAT_MOODLE, $options)));
        }
        // Set the orientation.
        $orientation = 'vertical';
        if ($this->properties->layout) {
            $orientation = 'horizontal';
        }
        $output .= $renderer->box(implode("\n", $buttons), 'branchbuttoncontainer ' . $orientation);

        if (!$this->lesson->slideshow) {
            return $output;
        }
        return $OUTPUT->render_from_template('local_coursegen/preview_container', [
            'classes' => 'slideshow',
            'style' => $this->slideshow_style(),
            'content' => $output,
        ]);
    }

    /**
     * The slideshow wrapper's inline style, as mod_lesson_renderer::slideshow_start() writes it.
     *
     * A slideshow's background colour and size are the lesson's own settings,
     * not something a stylesheet can state.
     *
     * @return string
     */
    protected function slideshow_style(): string {
        $properties = $this->lesson->properties();
        return 'background-color:' . $properties->bgcolor . ';height:' .
            $properties->height . 'px;width:' . $properties->width . 'px;';
    }

    /**
     * A content page is listed in the lesson menu.
     *
     * @return bool
     */
    protected function get_displayinmenublock() {
        return true;
    }
}
