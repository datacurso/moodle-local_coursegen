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
    use forum_actionbar;
    use forum_discussion_list;
    use forum_discussion_form;
    use forum_notifications;

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
}
