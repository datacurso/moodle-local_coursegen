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
 * A forum's opening discussions, drawn the way mod_forum lists them.
 *
 * A forum's page is its list of discussions, so that is what this is: each one
 * with the subject that opens it and the message underneath, in the post layout
 * the forum uses.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_preview extends activity_preview {
    /**
     * Every discussion the forum starts with.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $discussions = $this->items('discussions');
        if (!$discussions) {
            return $this->nothing_yet();
        }

        $out = '';
        foreach ($discussions as $discussion) {
            $subject = format_string((string) ($discussion['subject'] ?? $discussion['name'] ?? ''));
            $out .= $OUTPUT->box(
                \html_writer::tag('h4', $subject, ['class' => 'discussionname'])
                    . \html_writer::div($this->content($this->field($discussion, 'message')), 'post-content-container'),
                'forumpost generalbox'
            );
        }
        return $out;
    }
}
