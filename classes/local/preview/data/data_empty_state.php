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

namespace local_coursegen\local\preview\data;

/**
 * mod_data's "fields exist, no entries yet" state: the empty database
 * message, its action bar, and whether the reader could add an entry (a
 * template's database always previews as if they could not, since there is
 * no course to check membership/group access against). Kept apart from
 * view.php only because together they crossed the 250-line cap - both are
 * the same "database with nothing added" concern, split for size alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait data_empty_state {

    /**
     * mod_data_renderer::render_empty_database().
     *
     * @param int $currentgroup
     * @param int $groupmode
     * @return string
     */
    protected function render_empty_database(int $currentgroup, int $groupmode): string {
        global $OUTPUT;
        $data = $this->empty_database_action_bar($currentgroup, $groupmode);
        $data['noitemsimgurl'] = $OUTPUT->image_url('view_zero_state', 'mod_data')->out();

        return $OUTPUT->render_from_template('mod_data/view_noentries', $data);
    }

    /**
     * empty_database_action_bar::export_for_template(), with every button leading back to the preview.
     *
     * @param int $currentgroup
     * @param int $groupmode
     * @return array
     */
    protected function empty_database_action_bar(int $currentgroup, int $groupmode): array {
        global $OUTPUT;
        // The real bar offers adding and importing entries. Nobody may act on
        // an activity that does not exist: neither is offered.
        $data = ['addentrybutton' => null];
        return $data;
    }

    /**
     * add_entries_action::export_for_template().
     *
     * @param int $currentgroup
     * @param int $groupmode
     * @return stdClass|null
     */
    protected function add_entries_action(int $currentgroup, int $groupmode): ?stdClass {
        global $OUTPUT;
        if ($this->data_user_can_add_entry($this->data, $currentgroup, $groupmode, $this->context)) {
            $button = new \single_button(new moodle_url($this->here), get_string('add', 'mod_data'), 'get', \single_button::BUTTON_PRIMARY);
            return $button->export_for_template($OUTPUT);
        }
        return null;
    }

    /**
     * mod/data/lib.php data_user_can_add_entry(), with the fields read from the store and no entries yet.
     *
     * @param stdClass $data
     * @param int $currentgroup
     * @param int $groupmode
     * @param context $context
     * @return bool
     */
    protected function data_user_can_add_entry($data, $currentgroup, $groupmode, $context) {
        // Don't let add entry to a database that has no fields.
        if (!$this->has_fields()) {
            return false;
        }

        if (has_capability('mod/data:manageentries', $context)) {
            // no entry limits apply if user can manage

        } else if (!has_capability('mod/data:writeentry', $context)) {
            return false;

        } else if (data_in_readonly_period($data)) {
            // Check whether we're in a read-only period
            return false;
        }
        // data_atmaxentries(): the reader has added none.

        if (!$groupmode or has_capability('moodle/site:accessallgroups', $context)) {
            return true;
        }

        if ($currentgroup) {
            return groups_is_member($currentgroup);
        } else {
            //else it might be group 0 in visible mode
            if ($groupmode == VISIBLEGROUPS){
                return true;
            } else {
                return false;
            }
        }
    }
}
