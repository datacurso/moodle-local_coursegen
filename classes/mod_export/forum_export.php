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

namespace local_coursegen\mod_export;

/**
 * Class forum_export
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_export extends base_export {
    /**
     * A Forum's raw description, its settings and its initial discussions.
     *
     * Unlike url/resource, every forum setting is a plain column - there is no
     * serialized blob to unpack. The discussions are this type's internal
     * elements: their bodies are marker-bearing, so they travel raw and in the
     * order they were authored.
     *
     * @return array
     */
    public function parameters(): array {
        global $DB;

        $forum = $DB->get_record('forum', ['id' => $this->cm->instance]);
        if (!$forum) {
            return $this->minimal_parameters();
        }

        $parameters = array_merge(
            $this->settings_columns($forum),
            [
                'name' => $this->cm->name,
                'section' => (int) $this->cm->sectionnum,
                'intro' => $forum->intro ?? '',
                // Moodle zeroes the rating window unless this flag says it is
                // in use (see forum_add_instance), so it travels with it
                // instead of being inferred on the way back in.
                'ratingtime' => (!empty($forum->assesstimestart) && !empty($forum->assesstimefinish)) ? 1 : 0,
            ]
        );

        $discussions = $this->discussions((int) $forum->id);
        if ($discussions) {
            $parameters['mod_settings'] = ['discussions' => $discussions];
        }

        return $parameters;
    }

    /**
     * The mod_forum settings worth reproducing on the generated activity.
     *
     * Identity/placement columns (id, course, name, timemodified) are left out
     * on purpose - they describe THIS forum, never the new one.
     *
     * @param \stdClass $forum
     * @return array
     */
    private function settings_columns($forum): array {
        $fields = [
            'type', 'forcesubscribe', 'trackingtype',
            'maxbytes', 'maxattachments', 'displaywordcount',
            'lockdiscussionafter', 'blockperiod', 'blockafter', 'warnafter',
            'grade_forum', 'grade_forum_notify',
            'assessed', 'scale', 'assesstimestart', 'assesstimefinish',
            'completiondiscussions', 'completionreplies', 'completionposts',
            'duedate', 'cutoffdate', 'rsstype', 'rssarticles',
        ];

        return $this->whitelisted_settings($forum, $fields);
    }

    /**
     * Every initial discussion of one forum, as authored.
     *
     * A discussion's body lives on its first post, not on the discussion row.
     * Moodle lists discussions by pinned/last-reply order, which is a reading
     * order, not the authoring one - so they are ordered by id, the only
     * stable "as written" sequence.
     *
     * @param int $forumid
     * @return array
     */
    private function discussions(int $forumid): array {
        global $DB;

        $sql = 'SELECT d.id, d.name, p.message
                  FROM {forum_discussions} d
                  JOIN {forum_posts} p ON p.id = d.firstpost
                 WHERE d.forum = :forumid
              ORDER BY d.id ASC';
        $records = $DB->get_records_sql($sql, ['forumid' => $forumid]);

        $discussions = [];
        foreach ($records as $record) {
            $discussions[] = [
                'subject' => $record->name,
                'message' => $record->message ?? '',
            ];
        }
        return $discussions;
    }
}
