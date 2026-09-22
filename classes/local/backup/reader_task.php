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

namespace local_coursegen\local\backup;

use backup_activity_task;

/**
 * A minimal backup_activity_task: answers the four questions a backup
 * structure needs from its task (course id, and whether user data and group
 * data travel with the activity) without producing an actual backup.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reader_task extends backup_activity_task {
    /** @var string[] Modules whose content backup files as user data. */
    private const PAGES_ARE_THE_MODULE = ['wiki'];

    /** @var int The course the activity belongs to. */
    protected int $courseid;

    /**
     * Constructor.
     *
     * @param string $name An identifier, used only in messages.
     * @param int $moduleid The course module being read.
     * @param int $courseid Its course.
     */
    public function __construct(string $name, int $moduleid, int $courseid) {
        $this->courseid = $courseid;
        parent::__construct($name, $moduleid, null);
    }

    /**
     * The course, answered without a plan to ask.
     *
     * The real task reads this from the backup plan it belongs to. There is no
     * plan here, so it is given at construction from the course module itself.
     *
     * @return int
     */
    public function get_courseid() {
        return $this->courseid;
    }

    /**
     * Every setting is off, except that a module's own pages travel with it.
     *
     * Backup files people's contributions - posts, entries, answers - only
     * when asked for user data, and a template carries none of that. A wiki's
     * pages are filed the same way, but they are the wiki: without them a
     * kept wiki is a title over nothing. So for a wiki alone, user data is on.
     *
     * @param string $name
     * @return bool
     */
    public function get_setting_value($name) {
        return $name === 'userinfo' && in_array($this->get_modulename(), self::PAGES_ARE_THE_MODULE, true);
    }

    /**
     * Every setting can be asked about, and every answer is the one above.
     *
     * @param string $name
     * @return bool
     */
    public function setting_exists($name) {
        return true;
    }

    /**
     * No settings are defined, because none are chosen.
     */
    protected function define_my_settings() {
        return;
    }

    /**
     * No steps are built, because nothing is executed.
     */
    protected function define_my_steps() {
        return;
    }
}
