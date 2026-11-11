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

namespace local_coursegen\local\preview\feedback;

use cm_info;
use context;
use local_coursegen\local\preview\json_store;
use moodle_url;
use stdClass;

/**
 * mod_feedback's view code, run here against the payload instead of the database.
 *
 * Copied from mod/feedback/view.php, classes/output/standard_action_bar.php,
 * classes/output/summary.php and the parts of mod_feedback_structure and
 * mod_feedback_completion the page reads (Moodle 4.5). Method names are the
 * functions they came from. What changed: the items are read from a
 * json_store; nobody has answered, because answers are the readers' and a
 * template carries none; and the buttons that would edit, preview or answer
 * the template's real feedback keep their place but lead back to the preview.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    use feedback_actionbar;

    /** @var stdClass */
    protected stdClass $feedback;
    /** @var cm_info */
    protected cm_info $cm;
    /** @var stdClass */
    protected stdClass $course;
    /** @var context */
    protected context $context;
    /** @var json_store */
    protected json_store $store;
    /** @var moodle_url */
    protected moodle_url $here;
    /** @var callable The module's format_module_intro(). */
    protected $intro;

    /**
     * Constructor.
     *
     * @param stdClass $feedback
     * @param cm_info $cm
     * @param stdClass $course
     * @param context $context
     * @param json_store $store
     * @param moodle_url $here
     * @param callable $intro
     */
    public function __construct(
        stdClass $feedback,
        cm_info $cm,
        stdClass $course,
        context $context,
        json_store $store,
        moodle_url $here,
        callable $intro
    ) {
        $this->feedback = $feedback;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = $context;
        $this->store = $store;
        $this->here = $here;
        $this->intro = $intro;
    }

    /**
     * mod_feedback_structure::get_items(), against the store.
     *
     * @param bool $hasvalueonly
     * @return stdClass[]
     */
    protected function get_items($hasvalueonly = false) {
        $allitems = $this->store->get_records('feedback_item', ['feedback' => $this->feedback->id], 'position');
        $idx = 1;
        foreach ($allitems as $id => $item) {
            $itemnr = null;
            if (!empty($item->hasvalue)) {
                $itemnr = $idx;
                $idx++;
            }
            $allitems[$id]->itemnr = $itemnr;
        }
        if ($hasvalueonly && $allitems) {
            return array_filter($allitems, [$this, 'item_has_value']);
        }
        return $allitems;
    }

    /**
     * Whether an item counts towards the "answered" total.
     *
     * @param stdClass $item
     * @return bool
     */
    protected function item_has_value(stdClass $item): bool {
        return !empty($item->hasvalue);
    }

    /**
     * mod_feedback_structure::is_open().
     *
     * @return bool
     */
    protected function is_open() {
        $checktime = time();
        return (!$this->feedback->timeopen || $this->feedback->timeopen <= $checktime) &&
            (!$this->feedback->timeclose || $this->feedback->timeclose >= $checktime);
    }

    /**
     * mod_feedback_completion::can_complete(), for a feedback in a course.
     *
     * @return bool
     */
    protected function can_complete() {
        return has_capability('mod/feedback:complete', $this->context);
    }

    /**
     * mod_feedback_completion::can_submit(): nobody has submitted yet.
     *
     * @return bool
     */
    protected function can_submit() {
        return true;
    }

    /**
     * mod_feedback_structure::page_after_submit().
     *
     * @return string|null
     */
    protected function page_after_submit() {
        $pageaftersubmit = $this->feedback->page_after_submit ?? '';
        if (empty($pageaftersubmit)) {
            return null;
        }
        $pageaftersubmitformat = $this->feedback->page_after_submitformat ?? FORMAT_HTML;

        $context = $this->context;
        $output = file_rewrite_pluginfile_urls($pageaftersubmit,
                'pluginfile.php', $context->id, 'mod_feedback', 'page_after_submit', 0);

        return format_text($output, $pageaftersubmitformat, array('overflowdiv' => true));
    }

    /**
     * mod/feedback/view.php from the header to the footer.
     *
     * @return string
     */
    public function page(): string {
        global $OUTPUT;

        $feedback = $this->feedback;
        $cm = $this->cm;
        $context = $this->context;
        $out = '';

        $viewcompletion = $this->is_open() && $this->can_complete() && $this->can_submit();

        $out .= $OUTPUT->box_start('generalbox feedback_description');
        $out .= ($this->intro)($feedback);
        // The real page offers editing the questions, previewing them and
        // answering. Nobody may act on an activity that does not exist: none is offered.
        $out .= $OUTPUT->box_end();

        if (has_capability('mod/feedback:edititems', $context)) {

            $out .= $OUTPUT->heading(get_string('overview', 'feedback'), 3);

            $groupselect = groups_print_activity_menu($cm, $this->here, true);

            $out .= $groupselect;
            $out .= $OUTPUT->render_from_template('local_coursegen/preview_container', [
                'classes' => 'clearer',
                'content' => '&nbsp;',
            ]);
            // mod_feedback\output\summary: nobody has answered.
            $summary = (object) [
                'completedcount' => 0,
                'itemscount' => count($this->get_items(true)),
            ];
            $out .= $OUTPUT->render_from_template('mod_feedback/summary', $summary);

            if ($pageaftersubmit = $this->page_after_submit()) {
                $out .= $OUTPUT->heading(get_string("page_after_submit", "feedback"), 3);
                $out .= $OUTPUT->box($pageaftersubmit, 'generalbox feedback_after_submit');
            }
        }

        // The analysis link and the mapped courses are drawn only on a page
        // without secondary navigation; a module page has it.

        if ($this->can_complete()) {
            $out .= $OUTPUT->box_start('generalbox boxaligncenter');
            if (!$this->is_open()) {
                $out .= $OUTPUT->notification(get_string('feedback_is_not_open', 'feedback'));
                $out .= $OUTPUT->continue_button($this->here);
            } else if (!$this->can_submit()) {
                $out .= $OUTPUT->notification(get_string('this_feedback_is_already_submitted', 'feedback'));
            }
            $out .= $OUTPUT->box_end();
        }

        return $out;
    }

}
