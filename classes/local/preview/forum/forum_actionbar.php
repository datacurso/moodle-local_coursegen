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

use help_icon;
use mod_forum\local\container;
use mod_forum\local\entities\forum as forum_entity;
use stdClass;

/**
 * mod/forum/classes/output/forum_actionbar.php, rendered through
 * mod_forum/forum_actionbar. Kept apart from view.php only because together
 * they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait forum_actionbar {
    /**
     * mod/forum/classes/output/forum_actionbar.php, rendered through mod_forum/forum_actionbar.
     *
     * @param forum_entity $forum
     * @param int|null $groupid
     * @param stdClass $course
     * @param string $search
     * @return string
     */
    protected function forum_activity_actionbar(forum_entity $forum, ?int $groupid, stdClass $course, string $search): string {
        global $USER, $OUTPUT;
        $actionurl = $this->here->out(false);
        $helpicon = new help_icon('search', 'core');
        $hiddenfields = [
            (object) ['name' => 'id', 'value' => $course->id],
        ];
        // view.php offers a button to start a discussion to anyone who may
        // post. Nobody may post to an activity that does not exist, so the
        // preview offers none; the search box stays, because it is part of
        // what a forum page looks like and searches nothing here.
        $shownewdiscussionbtn = '';
        $data = [
            'action' => $actionurl,
            'hiddenfields' => $hiddenfields,
            'query' => $search,
            'helpicon' => $helpicon->export_for_template($OUTPUT),
            'inputname' => 'search',
            'searchstring' => get_string('searchforums', 'mod_forum'),
            'newdiscussionbtn' => $shownewdiscussionbtn,
        ];
        $legacydatamapperfactory = container::get_legacy_data_mapper_factory();
        $forumobject = $legacydatamapperfactory->get_forum_data_mapper()->to_legacy_object($forum);
        $context = $forum->get_context();
        $activeenrolled = is_enrolled($context, $USER, '', true);
        $canmanage = has_capability('mod/forum:managesubscriptions', $context);
        $cansubscribe = $activeenrolled && !($forum->get_subscription_mode() === FORUM_FORCESUBSCRIBE) &&
            (!($forum->get_subscription_mode() === FORUM_DISALLOWSUBSCRIBE) || $canmanage);
        if ($cansubscribe) {
            // Subscribing is to the template's real forum; the link stays in the preview.
            if (!\mod_forum\subscriptions::is_subscribed($USER->id, $forumobject, null, $forum->get_course_module_record())) {
                $data['subscribetoforum'] = $this->here->out(false);
            } else {
                $data['unsubscribefromforum'] = $this->here->out(false);
            }
        }
        return $OUTPUT->render_from_template('mod_forum/forum_actionbar', $data);
    }
}
