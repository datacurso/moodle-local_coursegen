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

use cm_info;
use context;
use html_writer;
use local_coursegen\local\preview\json_store;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/data/lib.php');
require_once($CFG->dirroot . '/mod/data/locallib.php');

/**
 * mod_data's view code, ported to run against the payload.
 *
 * Copied from mod/data/view.php, renderer.php and classes/output
 * (zero_state_action_bar, empty_database_action_bar, add_entries_action,
 * action_bar::get_create_fields) (Moodle 4.5). Method names are the functions
 * they came from. What changed: the fields are read from a json_store; there
 * are no entries, because entries are the readers' and a template carries
 * none, so the database is drawn either without fields or with its fields and
 * no entries, the two states a database is in before anyone adds to it; and
 * every button that would create a field, import a preset or add an entry to
 * the template's real database keeps its place but leads back to the preview.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** @var stdClass */
    protected stdClass $data;
    /** @var cm_info */
    protected cm_info $cm;
    /** @var context */
    protected context $context;
    /** @var json_store */
    protected json_store $store;
    /** @var moodle_url */
    protected moodle_url $here;

    /**
     * Constructor.
     *
     * @param stdClass $data
     * @param cm_info $cm
     * @param context $context
     * @param json_store $store
     * @param moodle_url $here
     */
    public function __construct(stdClass $data, cm_info $cm, context $context, json_store $store, moodle_url $here) {
        $this->data = $data;
        $this->cm = $cm;
        $this->context = $context;
        $this->store = $store;
        $this->here = $here;
    }

    /**
     * manager::has_fields(), against the store.
     *
     * @return bool
     */
    protected function has_fields(): bool {
        return $this->store->record_exists('data_fields', ['dataid' => $this->data->id]);
    }

    /**
     * mod/data/view.php from the header to the footer.
     *
     * @return string
     */
    public function page(): string {
        global $OUTPUT;

        $data = $this->data;
        $cm = $this->cm;
        $context = $this->context;
        $out = '';

        $currentgroup = groups_get_activity_group($cm, true);
        $groupmode = groups_get_activity_groupmode($cm);
        $canmanageentries = has_capability('mod/data:manageentries', $context);

        if (!$this->has_fields()) {
            return $this->render_database_zero_state();
        }

        list($showactivity, $warnings) = data_get_time_availability_status($data, $canmanageentries, $context);

        if ($showactivity) {
            // Nobody has added an entry to a database that is being previewed.
            $numentries = 0;
            if ($data->entriesleft = data_get_entries_left_to_add($data, $numentries, $canmanageentries)) {
                $strentrieslefttoadd = get_string('entrieslefttoadd', 'data', $data);
                $out .= $OUTPUT->notification($strentrieslefttoadd);
            }

            if ($data->entrieslefttoview = data_get_entries_left_to_view($data, $numentries, $canmanageentries)) {
                $strentrieslefttoaddtoview = get_string('entrieslefttoaddtoview', 'data', $data);
                $out .= $OUTPUT->notification($strentrieslefttoaddtoview);
            }

            if ($groupmode != NOGROUPS) {
                $out .= html_writer::div(groups_print_activity_menu($cm, $this->here, true), 'mb-3');
            }

            // data_search_entries() finds nothing; $maxcount == 0.
            $out .= $this->render_empty_database($currentgroup, $groupmode);
        }

        return $out;
    }

    /**
     * mod_data_renderer::render_database_zero_state().
     *
     * @return string
     */
    protected function render_database_zero_state(): string {
        global $OUTPUT;
        $data = $this->zero_state_action_bar();
        if (empty($data)) {
            // No actions for the user.
            $data['title'] = get_string('activitynotready');
            $data['intro'] = get_string('comebacklater');
            $data['noitemsimgurl'] = $OUTPUT->image_url('noentries_zero_state', 'mod_data')->out();
        } else {
            $data['title'] = get_string('startbuilding', 'mod_data');
            $data['intro'] = get_string('createactivity', 'mod_data');
            $data['noitemsimgurl'] = $OUTPUT->image_url('view_zero_state', 'mod_data')->out();
        }

        return $OUTPUT->render_from_template('mod_data/zero_state', $data);
    }

    /**
     * zero_state_action_bar::export_for_template(), with every button leading back to the preview.
     *
     * @return array
     */
    protected function zero_state_action_bar(): array {
        global $OUTPUT;
        $data = [];
        // The real bar offers presets, fields and an import to one who may
        // manage templates. Nobody may act on an activity that does not
        // exist: none is offered.
        return $data;
    }

    /**
     * action_bar::get_create_fields(), with every field type leading back to the preview.
     *
     * @param bool $isprimarybutton
     * @return \action_menu
     */
    protected function get_create_fields(bool $isprimarybutton = false): \action_menu {
        // Get the list of possible fields (plugins).
        $plugins = \core_component::get_plugin_list('datafield');
        $menufield = [];
        foreach ($plugins as $plugin => $fulldir) {
            $menufield[$plugin] = get_string('pluginname', "datafield_{$plugin}");
        }
        asort($menufield);
        $fieldselect = new \action_menu();
        $triggerclasses = ['btn'];
        $triggerclasses[] = $isprimarybutton ? 'btn-primary' : 'btn-secondary';
        $fieldselect->set_menu_trigger(get_string('newfield', 'mod_data'), join(' ', $triggerclasses));
        foreach ($menufield as $fieldtype => $fieldname) {
            $fieldselect->add(new \action_menu_link(
                new moodle_url($this->here, ['newtype' => $fieldtype]),
                new \pix_icon('field/' . $fieldtype, $fieldname, 'data'),
                $fieldname,
                false
            ));
        }
        $fieldselect->set_additional_classes('singlebutton');
        return $fieldselect;
    }

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
