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

namespace local_coursegen\local\preview\forum;

use cm_info;
use mod_forum\local\container;
use moodle_url;
use stdClass;

/**
 * discussion_list::get_discussion_form(), posting back to the preview. Kept
 * apart from view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait forum_discussion_form {
    /**
     * discussion_list::get_discussion_form(), posting back to the preview.
     *
     * @param stdClass $user
     * @param cm_info $cm
     * @param int|null $groupid
     * @return string
     */
    protected function get_discussion_form(stdClass $user, cm_info $cm, ?int $groupid) {
        $forum = $this->forum();
        $forumrecord = container::get_legacy_data_mapper_factory()->get_forum_data_mapper()->to_legacy_object($forum);
        $modcontext = $this->context;
        $coursecontext = \context_course::instance($forum->get_course_id());
        $post = (object) [
            'course' => $forum->get_course_id(),
            'forum' => $forum->get_id(),
            'discussion' => 0,           // Ie discussion # not defined yet.
            'parent' => 0,
            'subject' => '',
            'userid' => $user->id,
            'message' => '',
            'messageformat' => editors_get_preferred_format(),
            'messagetrust' => 0,
            'groupid' => $groupid,
        ];
        $thresholdwarning = forum_check_throttling($forumrecord, $cm);

        $formparams = array(
            'course' => $forum->get_course_record(),
            'cm' => $cm,
            'coursecontext' => $coursecontext,
            'modcontext' => $modcontext,
            'forum' => $forumrecord,
            'post' => $post,
            'subscribe' => \mod_forum\subscriptions::is_subscribed($user->id, $forumrecord,
                null, $cm),
            'thresholdwarning' => $thresholdwarning,
            'inpagereply' => true,
            'edit' => 0
        );
        $posturl = new moodle_url($this->here);
        $mformpost = new \mod_forum_post_form($posturl, $formparams, 'post', '', array('id' => 'mformforum'));
        $discussionsubscribe = \mod_forum\subscriptions::get_user_default_subscription($forumrecord, $coursecontext, $cm, null);

        $groupidparam = array();
        if (isset($post->groupid)) {
            $groupidparam = array('groupid' => $post->groupid);
        }
        $params = array('reply' => 0, 'forum' => $forumrecord->id, 'edit' => 0) +
            $groupidparam +
            array(
                'userid' => $post->userid,
                'parent' => $post->parent,
                'discussion' => $post->discussion,
                'course' => $forum->get_course_id(),
                'discussionsubscribe' => $discussionsubscribe
            );
        $mformpost->set_data($params);

        return $mformpost->render();
    }
}
