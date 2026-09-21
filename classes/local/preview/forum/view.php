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
use context;
use core\output\notification;
use help_icon;
use mod_forum\grades\forum_gradeitem;
use mod_forum\local\container;
use mod_forum\local\entities\forum as forum_entity;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/forum/lib.php');

/**
 * mod_forum's view code, ported to run against the payload.
 *
 * Copied from mod/forum/view.php, lib.php (forum_search_form,
 * forum_activity_actionbar), classes/output/forum_actionbar.php,
 * classes/output/quick_search_form.php and
 * classes/local/renderers/discussion_list.php (Moodle 4.5). Method names are
 * the functions they came from. What changed: the forum is built as
 * mod_forum's own entity from the payload's row, so its exporters and
 * capability manager run unchanged; there are no discussions, because
 * discussions are the readers' and a template carries none, so the list is
 * drawn in the state a forum is in before anyone posts; and every form and
 * link that would search, post to or subscribe to the template's real forum
 * keeps its place but leads back to the preview.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** @var stdClass The forum row. */
    protected stdClass $record;
    /** @var cm_info */
    protected cm_info $cm;
    /** @var stdClass */
    protected stdClass $course;
    /** @var context */
    protected context $context;
    /** @var moodle_url Where the preview is read. */
    protected moodle_url $here;
    /** @var forum_entity|null */
    protected ?forum_entity $forum = null;

    /**
     * Constructor.
     *
     * @param stdClass $record The forum row, as the payload carries it.
     * @param cm_info $cm The template's course module, for what the entity reads off one.
     * @param stdClass $course
     * @param context $context
     * @param moodle_url $here
     */
    public function __construct(stdClass $record, cm_info $cm, stdClass $course, context $context, moodle_url $here) {
        $this->record = $record;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = $context;
        $this->here = $here;
    }

    /**
     * The forum as mod_forum's entity, built from the payload's row.
     *
     * @return forum_entity
     */
    public function forum(): forum_entity {
        if ($this->forum !== null) {
            return $this->forum;
        }
        $record = clone $this->record;
        // Columns the entity reads that backup does not carry.
        $record->course = $record->course ?? $this->course->id;
        $record->grade_forum_notify = $record->grade_forum_notify ?? 0;
        foreach (['assessed', 'assesstimestart', 'assesstimefinish', 'scale', 'grade_forum', 'maxbytes', 'maxattachments',
            'forcesubscribe', 'trackingtype', 'rsstype', 'rssarticles', 'timemodified', 'warnafter', 'blockafter',
            'blockperiod', 'completiondiscussions', 'completionreplies', 'completionposts', 'displaywordcount',
            'lockdiscussionafter', 'duedate', 'cutoffdate'] as $column) {
            $record->$column = $record->$column ?? 0;
        }
        $record->type = $record->type ?? 'general';
        $record->intro = $record->intro ?? '';
        $record->introformat = $record->introformat ?? FORMAT_HTML;
        $this->forum = container::get_entity_factory()->get_forum_from_stdclass(
            $record,
            $this->context,
            $this->cm->get_course_module_record(),
            $this->course
        );
        return $this->forum;
    }

    /**
     * mod/forum/view.php from the header to the footer.
     *
     * @return string
     */
    public function page(): string {
        global $USER;
        $out = '';
        $out .= $this->forum_activity_actionbar($this->forum(), null, $this->course, '');
        $out .= $this->discussion_list($USER, $this->cm, null);
        return $out;
    }

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

    /**
     * discussion_list::render_new_discussion().
     *
     * @param stdClass $user
     * @param int|null $groupid
     * @return string
     */
    protected function render_new_discussion(stdClass $user, ?int $groupid): string {
        global $OUTPUT;
        $forumexporter = container::get_exporter_factory()->get_forum_exporter($user, $this->forum(), $groupid);

        $forumview = [
            'forum' => (array) $forumexporter->export($OUTPUT),
        ];

        return $OUTPUT->render_from_template('mod_forum/forum_new_discussion_actionbar', $forumview);
    }

    /**
     * discussion_list::render(), for a forum in which nobody has posted yet.
     *
     * @param stdClass $user
     * @param cm_info $cm
     * @param int|null $groupid
     * @return string
     */
    protected function discussion_list(stdClass $user, cm_info $cm, ?int $groupid): string {
        global $OUTPUT;

        $forum = $this->forum();
        $course = $forum->get_course_record();
        $forumgradeitem = forum_gradeitem::load_from_forum_entity($forum);
        $capabilitymanager = container::get_manager_factory()->get_capability_manager($forum);

        $forumexporter = container::get_exporter_factory()->get_forum_exporter($user, $forum, $groupid);

        $hasanyactions = false;
        $hasanyactions = $hasanyactions || $capabilitymanager->can_favourite_discussion($user);
        $hasanyactions = $hasanyactions || $capabilitymanager->can_pin_discussions($user);
        $hasanyactions = $hasanyactions || $capabilitymanager->can_manage_forum($user);

        $forumview = [
            'forum' => (array) $forumexporter->export($OUTPUT),
            'contextid' => $forum->get_context()->id,
            'cmid' => $cm->id,
            'groupid' => $groupid,
            'name' => format_string($forum->get_name()),
            'courseid' => $course->id,
            'coursename' => format_string($course->shortname),
            'experimentaldisplaymode' => false,
            'gradingcomponent' => $forumgradeitem->get_grading_component_name(),
            'gradingcomponentsubtype' => $forumgradeitem->get_grading_component_subtype(),
            'sendstudentnotifications' => $forum->should_notify_students_default_when_grade_for_forum(),
            'gradeonlyactiveusers' => $forumgradeitem->should_grade_only_active_users(),
            'hasanyactions' => $hasanyactions,
            'groupchangemenu' => groups_print_activity_menu($cm, $this->here, true),
            'hasmore' => false,
            'notifications' => $this->get_notifications($user, $groupid, $capabilitymanager),
            'settings' => [
                'excludetext' => true,
                'togglemoreicon' => true,
                'excludesubscription' => true
            ],
            'totaldiscussioncount' => 0,
            'userid' => $user->id,
            'visiblediscussioncount' => 0,
            // view.php passes false: the button to start a discussion is in
            // the action bar above, not in the list.
            'enablediscussioncreation' => false,
        ];

        // The form the button above would open. Without the button it has
        // no way to be reached, and a preview has nowhere to post it to.
        $forumview['newdiscussionhtml'] = '';

        return $OUTPUT->render_from_template($this->template($forum), $forumview);
    }

    /**
     * renderer factory: the discussion list template for a forum's type.
     *
     * @param forum_entity $forum
     * @return string
     */
    protected function template(forum_entity $forum): string {
        $type = $forum->get_type();
        if ($type === 'news') {
            if ($forum->get_course_id() == SITEID) {
                return 'mod_forum/frontpage_news_discussion_list';
            }
            return 'mod_forum/news_discussion_list';
        }
        if ($type === 'qanda') {
            return 'mod_forum/qanda_discussion_list';
        }
        if ($type === 'blog') {
            return 'mod_forum/blog_discussion_list';
        }
        return 'mod_forum/discussion_list';
    }

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

        $params = array('reply' => 0, 'forum' => $forumrecord->id, 'edit' => 0) +
            (isset($post->groupid) ? array('groupid' => $post->groupid) : array()) +
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

    /**
     * discussion_list::get_notifications().
     *
     * @param stdClass $user
     * @param int|null $groupid
     * @param \mod_forum\local\managers\capability $capabilitymanager
     * @return array
     */
    protected function get_notifications(stdClass $user, ?int $groupid, $capabilitymanager): array {
        global $OUTPUT;
        $notifications = [];
        $forum = $this->forum();

        if ($forum->is_cutoff_date_reached()) {
            $notifications[] = (new notification(
                    get_string('cutoffdatereached', 'forum'),
                    notification::NOTIFY_INFO
            ))->set_show_closebutton();
        }

        if ($forum->has_blocking_enabled()) {
            $notifications[] = (new notification(
                get_string('thisforumisthrottled', 'forum', [
                    'blockafter' => $forum->get_block_after(),
                    'blockperiod' => get_string('secondstotime' . $forum->get_block_period())
                ]),
                notification::NOTIFY_INFO
            ))->set_show_closebutton();
        }

        if ($forum->is_in_group_mode() && !$capabilitymanager->can_access_all_groups($user)) {
            if ($groupid === null) {
                $isvisiblegroupsmode = $forum->get_effective_group_mode() == VISIBLEGROUPS;
                $isgroupmember = !empty(groups_get_user_groups($forum->get_course_id(), $user->id)[0]);

                if (
                    !$capabilitymanager->can_post_to_my_groups($user)
                    && $isvisiblegroupsmode
                    && $isgroupmember
                ) {
                    $notifications[] = (new notification(
                        get_string('cannotadddiscussionall', 'mod_forum'),
                        notification::NOTIFY_WARNING
                    ))->set_show_closebutton();
                } else {
                    $notifications[] = (new notification(
                        get_string('cannotadddiscussiongroup', 'mod_forum'),
                        notification::NOTIFY_WARNING
                    ))->set_show_closebutton();
                }
            } else if (!$capabilitymanager->can_access_group($user, $groupid)) {
                $notifications[] = (new notification(
                    get_string('cannotadddiscussion', 'mod_forum'),
                    notification::NOTIFY_WARNING
                ))->set_show_closebutton();
            }
        }

        if ('qanda' === $forum->get_type() && !$capabilitymanager->can_manage_forum($user)) {
            $notifications[] = (new notification(
                get_string('qandanotify', 'forum'),
                notification::NOTIFY_INFO
            ))->set_show_closebutton()->set_extra_classes(['mt-3']);
        }

        if ('eachuser' === $forum->get_type()) {
            $notifications[] = (new notification(
                get_string('allowsdiscussions', 'forum'),
                notification::NOTIFY_INFO)
            )->set_show_closebutton();
        }

        return array_map(function($notification) use ($OUTPUT) {
            return $notification->export_for_template($OUTPUT);
        }, $notifications);
    }
}
