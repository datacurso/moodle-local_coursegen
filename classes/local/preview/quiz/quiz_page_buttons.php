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

namespace local_coursegen\local\preview\quiz;

use moodle_url;
use single_button;
use stdClass;

/**
 * mod_quiz view's shell nav and start-attempt button, kept apart from view.php
 * only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait quiz_page_buttons {
    /**
     * renderer::view_page_tertiary_nav().
     *
     * @param stdClass $viewobj
     * @return string
     */
    protected function view_page_tertiary_nav(stdClass $viewobj): string {
        global $OUTPUT;
        $content = '';

        if ($viewobj->buttontext) {
            $attemptbtn = $this->start_attempt_button($viewobj->buttontext,
                    $viewobj->startattempturl, $viewobj->popuprequired, $viewobj->popupoptions);
            $content .= $attemptbtn;
        }

        // The "add question" link is for someone who may edit a quiz that
        // exists; nothing here does.

        if (!$content) {
            return '';
        }
        $row = $OUTPUT->render_from_template('local_coursegen/preview_container', ['classes' => 'row', 'content' => $content]);
        return $OUTPUT->render_from_template('local_coursegen/preview_container', [
            'classes' => 'container-fluid tertiary-navigation',
            'content' => $row,
        ]);
    }

    /**
     * renderer::start_attempt_button().
     *
     * @param string $buttontext
     * @param moodle_url $url
     * @param bool $popuprequired
     * @param array|null $popupoptions
     * @return string
     */
    protected function start_attempt_button($buttontext, moodle_url $url, $popuprequired = false, $popupoptions = null) {
        global $OUTPUT, $PAGE;

        $button = new single_button($url, $buttontext, 'post', single_button::BUTTON_PRIMARY);
        $button->class .= ' quizstartbuttondiv';
        if ($popuprequired) {
            $button->class .= ' quizsecuremoderequired';
        }

        $popupjsoptions = null;
        $startattemptlabel = get_string('startattempt', 'quiz');
        $amdargs = ['.quizstartbuttondiv [type=submit]', $startattemptlabel, '#mod_quiz_preflight_form', $popupjsoptions];

        $PAGE->requires->js_call_amd('mod_quiz/preflightcheck', 'init', $amdargs);

        return $OUTPUT->render($button);
    }

    /**
     * renderer::view_information().
     *
     * The counts of attempts and of overrides are links to reports on a quiz
     * that exists, and both draw nothing when there is nothing to count, which
     * is the case here.
     *
     * @param array $messages
     * @return string
     */
    protected function view_information(array $messages): string {
        global $OUTPUT;
        $output = '';

        // Output any access messages.
        if ($messages) {
            $messageshtml = $this->access_messages($messages);
            $output .= $OUTPUT->box($messageshtml, 'quizinfo');
        }

        return $output;
    }

    /**
     * renderer::view_page_buttons().
     *
     * @param stdClass $viewobj
     * @return string
     */
    protected function view_page_buttons(stdClass $viewobj): string {
        global $OUTPUT;
        $output = '';

        if (!$viewobj->quizhasquestions) {
            $noquestionslabel = get_string('noquestions', 'quiz');
            $notification = $OUTPUT->notification($noquestionslabel, 'warning', false);
            $output .= $OUTPUT->render_from_template('local_coursegen/preview_container', [
                'classes' => 'text-start mb-3',
                'content' => $notification,
            ]);
        }
        $output .= $this->access_messages($viewobj->preventmessages);

        return $output;
    }

    /**
     * renderer::access_messages().
     *
     * @param array $messages
     * @return string
     */
    protected function access_messages(array $messages): string {
        global $OUTPUT;
        $paragraphs = [];
        foreach ($messages as $message) {
            $paragraphs[] = ['text' => $message];
        }
        return $OUTPUT->render_from_template('local_coursegen/preview_paragraphs', [
            'classes' => 'text-start',
            'paragraphs' => $paragraphs,
        ]);
    }
}
