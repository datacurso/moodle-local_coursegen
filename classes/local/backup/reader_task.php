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
 * The little a module's own structure needs to know about who is asking.
 *
 * A module's backup structure is declared by a step, and a step belongs to a
 * task, which is how the declaration learns which activity it is describing.
 * The real task exists to produce a whole backup: it builds directories, adds
 * a dozen steps and settles every setting the person taking the backup chose.
 * None of that happens here, because nothing is being backed up. What is
 * wanted is only the declaration, and the declaration needs a task that can
 * answer four questions about the activity and one about the settings.
 *
 * The settings it answers are the ones a structure asks before deciding
 * whether to include a branch: whether the people's own data travels with the
 * activity, and whether groups do. Both are no. An activity is being read to
 * describe how it is built, not who has used it, so attempts, grades, timers
 * and submissions have no place in the answer, and a module that asks about
 * something else gets the same no rather than an error, because a module this
 * code has never seen must not be able to break it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reader_task extends backup_activity_task {
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
     * Whatever a structure asks about the backup being taken, the answer is no.
     *
     * @param string $name
     * @return bool
     */
    public function get_setting_value($name) {
        return false;
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
