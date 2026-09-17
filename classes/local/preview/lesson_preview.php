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

use block_contents;
use html_writer;
use moodle_url;

/**
 * A lesson, read the way a lesson is read.
 *
 * A lesson shows one page at a time, with its menu of pages down the side and
 * its navigation buttons at the foot of each one. Laying every page out on top
 * of each other instead shows what the lesson contains but not what it is, and
 * a teacher deciding whether to accept it is deciding about an activity their
 * students will walk through a page at a time.
 *
 * So it is read a page at a time here too. The buttons move between the pages
 * of the preview rather than through the lesson's own jumps, because the pages
 * being shown are drafts and the jumps point at pages of the activity that
 * does not exist yet.
 *
 * The shape of each page mirrors mod_lesson's own content page
 * (mod/lesson/pagetypes/branchtable.php): the contents in a box, then the
 * branch buttons in their container.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_preview extends activity_preview {
    /**
     * The page being read, with its navigation.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $pages = $this->pages();
        if (!$pages) {
            return $this->nothing_yet();
        }

        $at = max(0, min($this->page, count($pages) - 1));
        $page = $pages[$at];

        $out = $OUTPUT->box($this->content((string) ($page['content_html'] ?? '')), 'contents');
        $out .= $this->buttons($at, $pages);
        return $out;
    }

    /**
     * A lesson's page carries its own title, so the header shows that.
     *
     * The activity's own name is on every page of a real lesson, and the page's
     * title under it; the header is where both of those live.
     *
     * @return string
     */
    public function header_description(): string {
        $pages = $this->pages();
        if (!$pages) {
            return '';
        }
        $at = max(0, min($this->page, count($pages) - 1));
        $title = trim((string) ($pages[$at]['title'] ?? ''));
        return $title === '' ? '' : html_writer::tag('h3', format_string($title), ['class' => 'mb-0']);
    }

    /**
     * The lesson menu, listing every page with the one being read marked.
     *
     * mod_lesson lists the titles of its content pages down the left, and a
     * lesson read without that list is a different activity to look at. The
     * block carries the same markup lesson_menu_block_contents() builds, with
     * each title linking to that page of the preview.
     *
     * @return block_contents[]
     */
    public function side_blocks(): array {
        $pages = $this->pages();
        if (count($pages) < 2) {
            return [];
        }

        $at = max(0, min($this->page, count($pages) - 1));
        $items = '';
        foreach ($pages as $index => $page) {
            $title = trim((string) ($page['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $items .= html_writer::tag(
                'li',
                html_writer::link($this->page_url($index), format_string($title)),
                ['class' => $index === $at ? 'active' : '']
            );
        }
        if ($items === '') {
            return [];
        }

        $block = new block_contents();
        $block->title = get_string('lessonmenu', 'lesson');
        $block->attributes['class'] = 'menu block';
        $block->content = html_writer::div(html_writer::tag('ul', $items), 'menuwrapper');
        return [$block];
    }

    /**
     * The lesson's pages, as the draft or the answer holds them.
     *
     * @return array
     */
    private function pages(): array {
        $pages = ($this->parameters['mod_settings']['pages'] ?? []);
        return is_array($pages) ? array_values($pages) : [];
    }

    /**
     * The page's own navigation, drawn the way mod_lesson draws it.
     *
     * A content page's buttons are that page's answers: their labels are what
     * the author wrote on them, and where each one goes is the jump saved with
     * it. mod_lesson renders each as a plain button in a box
     * (mod/lesson/pagetypes/branchtable.php), laid out across or down
     * according to the page's own setting, so that is what is drawn here.
     *
     * The jumps are followed within the preview: a jump to a page of the
     * activity is a jump to that page of this preview, and one that leaves the
     * lesson has nowhere to go, so it is shown without going anywhere.
     *
     * @param int $at
     * @param array $pages Every page, in order.
     * @return string
     */
    private function buttons(int $at, array $pages): string {
        global $OUTPUT;

        $page = $pages[$at];
        $buttons = [];
        foreach (($page['buttons'] ?? []) as $button) {
            $label = trim(html_to_text((string) ($button['text'] ?? ''), 0, false));
            if ($label === '') {
                continue;
            }
            $target = $this->jump_target($at, $pages, $button['jumpto'] ?? null);
            $buttons[] = $target === null
                ? html_writer::tag('button', s($label), [
                    'type' => 'button',
                    'class' => 'btn btn-secondary',
                    'disabled' => 'disabled',
                ])
                : $OUTPUT->single_button($this->page_url($target), $label, 'get');
        }

        if (!$buttons) {
            return '';
        }

        // A page says whether its buttons sit across or down.
        $vertical = ((int) ($page['layout'] ?? 1)) === 0;
        return $OUTPUT->box(
            implode("\n", $buttons),
            'branchbuttoncontainer ' . ($vertical ? 'vertical' : 'horizontal')
        );
    }

    /**
     * Which page of the preview one of a page's jumps leads to.
     *
     * @param int $at Where the reader is now.
     * @param array $pages
     * @param mixed $jumpto The jump as the activity saved it.
     * @return int|null The page to open, or null when it leaves the lesson.
     */
    private function jump_target(int $at, array $pages, $jumpto): ?int {
        global $CFG;
        // The names mod_lesson gives the jumps it saves.
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');

        $jump = (int) $jumpto;

        if ($jump === LESSON_NEXTPAGE || $jump === LESSON_UNSEENPAGE || $jump === LESSON_UNANSWEREDPAGE) {
            return $at + 1 < count($pages) ? $at + 1 : null;
        }
        if ($jump === LESSON_PREVIOUSPAGE) {
            return $at > 0 ? $at - 1 : null;
        }
        if ($jump === LESSON_THISPAGE) {
            return $at;
        }
        if ($jump < 0) {
            // The end of the lesson, or a jump only a reader's history can
            // settle. Neither leads anywhere in a preview.
            return null;
        }

        foreach ($pages as $index => $page) {
            if ((int) ($page['id'] ?? 0) === $jump) {
                return $index;
            }
        }
        return null;
    }
}
