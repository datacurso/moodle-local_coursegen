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

use local_coursegen\utils\generated_image_attacher;
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
            // Each discussion's own images (its own real Moodle itemid on the
            // export side), never the whole-activity list - see generated_image_attacher.
            $discussionimages = $discussion['images'] ?? [];
            $this->add_discussion((object)$discussion, $discussionimages);
        }
    }

    /**
     * Add discussion to forum.
     *
     * Real images referenced by @@PLUGINFILE@@ tokens in the discussion
     * message are pre-loaded into a draft file area and passed as the
     * "inlineattachmentsid" option, so forum_add_discussion() moves them
     * into the real mod_forum/post/<postid> file area and rewrites the
     * token itself - same pattern as intro/page/lesson page attachment.
     *
     * @param object $discussion Discussion data.
     * @param array $images Real images available for this activity.
     */
    protected function add_discussion(object $discussion, array $images = []) {
        $options = [];
        $draftid = generated_image_attacher::resolve_draft_itemid((string)($discussion->message ?? ''), $images);
        if ($draftid !== 0) {
            $options[] = ['name' => 'inlineattachmentsid', 'value' => $draftid];
        }

        mod_forum_external::add_discussion(
            $this->cm->instance,
            $discussion->subject,
            $discussion->message,
            -1,
            $options
        );
    }
}
