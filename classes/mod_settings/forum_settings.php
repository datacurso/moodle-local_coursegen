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

namespace local_coursegen\mod_settings;

use local_coursegen\utils\text_editor_parameter_cleaner;
use mod_forum_external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . "/mod/forum/externallib.php");

/**
 * Class forum_settings
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_settings extends base_settings {
    /**
     * Add specific settings for forum module.
     */
    public function add_settings() {
        foreach ($this->modsettings['discussions'] as $discussion) {
            $this->add_discussion((object)$discussion);
        }
    }

    /**
     * Add discussion to forum.
     *
     * @param object $discussion Discussion data.
     */
    protected function add_discussion(object $discussion) {
        // The message may reference files (the template source's images, the teacher's files, the files the
        // AI service made); they are copied into a draft area that the post saves into its own file area.
        $draftitemid = file_get_unused_draft_itemid();
        $message = text_editor_parameter_cleaner::prepare_editor_text(
            (string) $discussion->message,
            $draftitemid,
            $this->sourcecourseid
        );
        $options = [['name' => 'inlineattachmentsid', 'value' => $draftitemid]];
        mod_forum_external::add_discussion($this->cm->instance, $discussion->subject, $message, -1, $options);
    }
}
