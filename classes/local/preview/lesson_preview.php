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
 * A lesson's pages, drawn the way mod_lesson draws them.
 *
 * A real lesson shows one page at a time and moves between them with its
 * navigation buttons. There is nothing to navigate here, and a preview whose
 * point is to show what the whole activity will contain would be a poor one if
 * it showed a seventh of it, so every page is laid out in order, each with the
 * heading, the content box and the buttons its own page would have.
 *
 * The shape of each page mirrors mod_lesson's own content page
 * (mod/lesson/pagetypes/branchtable.php): a heading, the contents in a box,
 * then the branch buttons in their container.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_preview extends activity_preview {
    /**
     * Every page of the lesson, in order.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $pages = ($this->parameters['mod_settings']['pages'] ?? []);
        if (!$pages) {
            return $OUTPUT->notification(
                get_string('courseai_preview_empty', 'local_coursegen'),
                \core\output\notification::NOTIFY_INFO
            );
        }

        $out = '';
        foreach ($pages as $index => $page) {
            $out .= \html_writer::start_div('cg-preview-page');
            $out .= $OUTPUT->heading(format_string((string) ($page['title'] ?? '')), 3);
            $out .= $OUTPUT->box($this->content((string) ($page['content_html'] ?? '')), 'contents');
            $out .= $this->buttons($page);
            $out .= \html_writer::end_div();
        }
        return $out;
    }

    /**
     * A lesson puts its description on its first page, not in the header.
     *
     * @return string
     */
    public function header_description(): string {
        return '';
    }

    /**
     * The lesson menu, when the lesson is set to show one.
     *
     * mod_lesson lists the titles of its content pages down the left, and a
     * lesson read without that list is a different activity to look at. The
     * block is built with the same markup lesson_menu_block_contents() builds,
     * minus the links, because there is nowhere to navigate in a preview.
     *
     * @return \block_contents[]
     */
    public function side_blocks(): array {
        if (empty($this->parameters['displayleft'])) {
            return [];
        }
        $pages = ($this->parameters['mod_settings']['pages'] ?? []);
        if (!$pages) {
            return [];
        }

        $items = '';
        foreach ($pages as $page) {
            $title = trim((string) ($page['title'] ?? ''));
            if ($title !== '') {
                $items .= \html_writer::tag('li', format_string($title));
            }
        }
        if ($items === '') {
            return [];
        }

        $block = new \block_contents();
        $block->title = get_string('lessonmenu', 'lesson');
        $block->attributes['class'] = 'menu block';
        $block->content = \html_writer::div(\html_writer::tag('ul', $items), 'menuwrapper');
        return [$block];
    }

    /**
     * One page's navigation buttons, shown but never usable.
     *
     * The buttons are part of what the page looks like, so they are drawn; they
     * are disabled because a preview navigates nowhere, and because every page
     * is already on screen there is nowhere to go.
     *
     * @param array $page
     * @return string
     */
    private function buttons(array $page): string {
        global $OUTPUT;

        $labels = [];
        foreach (($page['buttons'] ?? []) as $button) {
            $text = trim((string) ($button['text'] ?? ''));
            if ($text !== '') {
                $labels[] = $text;
            }
        }
        if (!$labels) {
            $fallback = trim((string) ($page['button_text'] ?? ''));
            if ($fallback === '') {
                return '';
            }
            $labels[] = $fallback;
        }

        $buttons = '';
        foreach ($labels as $label) {
            $buttons .= \html_writer::tag('button', s($label), [
                'type' => 'button',
                'class' => 'btn btn-secondary',
                'disabled' => 'disabled',
            ]);
        }
        return $OUTPUT->box($buttons, 'branchbuttoncontainer horizontal');
    }
}
