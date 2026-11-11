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

namespace local_coursegen\local\preview\scorm;

use context;
use local_coursegen\local\preview\json_file_storage;
use local_coursegen\local\preview\json_store;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scorm/lib.php');
require_once($CFG->dirroot . '/mod/scorm/locallib.php');

/**
 * A SCORM package's view page, drawn by mod_scorm's own code against the payload.
 *
 * mod/scorm/view.php prints the description, then scorm_print_launch(): the
 * organisations to choose from when the package has more than one, the table
 * of contents when the package is set to show it, and the form that enters
 * the player; then the standing of the reader's attempts. Those functions are
 * copied here under their own names, with the learning objects read from the
 * payload's scoes rather than the database, the context handed in, and the
 * links the page makes to the player made to the preview.
 *
 * Attempts are the readers' own and a template carries none, so the reader
 * is shown as someone who has not started: every object not attempted, no
 * attempt made, no grade reported, and no attempts to delete.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    use scorm_launch;
    use scorm_attempts;
    use scorm_toc_build;
    use scorm_toc_tree;
    use scorm_sco_data;
    use scorm_links;

    /** @var stdClass The scorm row. */
    protected stdClass $scorm;

    /** @var stdClass The course module. */
    protected stdClass $cm;

    /** @var context The context the package's text is formatted in. */
    protected context $context;

    /** @var json_store The package's rows. */
    protected json_store $store;

    /** @var moodle_url The preview this is drawn on. */
    protected moodle_url $here;

    /** @var stdClass Who is reading. */
    protected stdClass $user;

    /** @var json_file_storage|null The package's files, as the payload carries them. */
    protected ?json_file_storage $files;

    /**
     * Constructor.
     *
     * @param stdClass $scorm The scorm row.
     * @param stdClass $cm The course module.
     * @param context $context
     * @param json_store $store
     * @param moodle_url $here The preview page, which every link stays on.
     * @param stdClass $user The reader.
     * @param json_file_storage|null $files The package's files, for the page a preview opens.
     */
    public function __construct(stdClass $scorm, stdClass $cm, context $context, json_store $store, moodle_url $here,
            stdClass $user, ?json_file_storage $files = null) {
        $this->files = $files;
        $this->scorm = $scorm;
        $this->cm = $cm;
        $this->context = $context;
        $this->store = $store;
        $this->here = $here;
        $this->user = $user;
        foreach (['timeopen' => 0, 'timeclose' => 0, 'popup' => 0, 'options' => '', 'intro' => ''] as $field => $default) {
            if (!isset($this->scorm->{$field})) {
                $this->scorm->{$field} = $default;
            }
        }
    }

    /**
     * The page, as mod/scorm/view.php prints it after the header.
     *
     * The page may instead send the reader straight into the player when the
     * package is set to skip its own page; a preview is the page, so it is
     * printed.
     *
     * @return string
     */
    public function page(): string {
        global $OUTPUT;
        $scorm = $this->scorm;
        $output = '';

        $attemptstatus = '';
        if ($scorm->displayattemptstatus == SCORM_DISPLAY_ATTEMPTSTATUS_ALL ||
                 $scorm->displayattemptstatus == SCORM_DISPLAY_ATTEMPTSTATUS_ENTRY) {
            $attemptstatus = $this->scorm_get_attempt_status();
        }
        $output .= $OUTPUT->box($this->format_module_intro(), '', 'intro');

        // Check if SCORM available. No need to display warnings because activity dates are displayed at the top of the page.
        list($available, $warnings) = scorm_get_availability_status($scorm);
        if ($available) {
            $output .= $this->scorm_print_launch();
        }
        $output .= $OUTPUT->box($attemptstatus);

        if (!empty(get_config('scorm', 'forcejavascript'))) {
            $message = $OUTPUT->box(get_string("forcejavascriptmessage", "scorm"), "forcejavascriptmessage");
            $output .= $OUTPUT->render_from_template('local_coursegen/preview_noscript', ['content' => $message]);
        }
        return $output;
    }
}
