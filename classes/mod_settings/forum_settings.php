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

use core\context\module;
use core\exception\moodle_exception;
use local_coursegen\local\warning_collector;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/forum/lib.php');

/**
 * Class forum_settings
 *
 * Creates the initial discussions the AI result declares in mod_settings.
 * A discussion that cannot be created (malformed entry, user not allowed to
 * start discussions in this forum, forum library failure) is recorded as a
 * warning and the remaining discussions are still attempted; the forum
 * module itself already exists.
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
        $discussions = $this->modsettings['discussions'] ?? [];
        if (!is_array($discussions)) {
            return;
        }

        foreach ($discussions as $discussion) {
            $discussion = (object)(is_array($discussion) || is_object($discussion) ? $discussion : []);
            $subject = trim((string)($discussion->subject ?? ''));
            $this->attempt(function () use ($discussion): void {
                $this->add_discussion($discussion);
            }, warning_collector::STEP_FORUM_DISCUSSION, $subject);
        }
    }

    /**
     * Add a discussion to the forum, visible to all participants, posted by the current user.
     *
     * Uses the forum library directly (not the mod_forum web service), so the
     * permission check the web service performed is made here; the creation is
     * audited with the discussion_created event like a discussion posted
     * through the forum.
     *
     * @param object $discussion Discussion data: subject and message (HTML).
     * @throws \InvalidArgumentException When the subject or the message is missing.
     * @throws moodle_exception When the current user cannot start a discussion in this forum.
     */
    protected function add_discussion(object $discussion) {
        global $DB;

        $subject = trim((string)($discussion->subject ?? ''));
        $message = trim((string)($discussion->message ?? ''));
        if ($subject === '' || $message === '') {
            throw new \InvalidArgumentException('A forum discussion needs a subject and a message.');
        }

        $forum = $DB->get_record('forum', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('forum', $this->cm->coursemodule, 0, false, MUST_EXIST);
        $context = module::instance($cm->id);

        // Group -1 (all participants) is what the web service used for a user with access to all groups.
        if (!forum_user_can_post_discussion($forum, -1, -1, $cm, $context)) {
            throw new moodle_exception('cannotcreatediscussion', 'forum');
        }

        $record = new \stdClass();
        $record->course = $forum->course;
        $record->forum = $forum->id;
        $record->subject = $subject;
        $record->name = $subject;
        $record->message = (string)$discussion->message;
        $record->messageformat = FORMAT_HTML;
        // Trusted text follows the poster's capability in the module context, as a forum post does.
        $record->messagetrust = trusttext_trusted($context);
        $record->itemid = 0;
        $record->groupid = -1;
        $record->mailnow = 0;
        $record->timestart = 0;
        $record->timeend = 0;
        $record->timelocked = 0;
        $record->pinned = FORUM_DISCUSSION_UNPINNED;
        $record->attachments = null;

        $record->id = forum_add_discussion($record);

        \mod_forum\event\discussion_created::create([
            'context' => $context,
            'objectid' => $record->id,
            'other' => ['forumid' => $forum->id],
        ])->trigger();
    }
}
