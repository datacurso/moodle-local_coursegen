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

namespace local_coursegen\local\preview\h5pactivity;

use context;
use core_h5p\local\library\autoloader;
use core_h5p\factory;
use core_h5p\helper;
use local_coursegen\local\preview\json_file;
use local_coursegen\local\preview\json_store;
use moodle_url;
use stdClass;

/**
 * An H5P activity's view page, drawn by mod_h5pactivity's own code against the payload.
 *
 * mod/h5pactivity/view.php tells a reader who cannot submit that they are
 * previewing, offers the attempts report to one who may review them, and
 * hands the package to core_h5p\player::display(), which draws the frame the
 * content plays in and the link to edit it. Those are copied here under their
 * own names: the readiness checks are the same capability checks, the counts
 * are over rows the payload does not carry, and the package is the file the
 * payload names, played from where the payload says it is.
 *
 * What plays inside the frame is the H5P content itself, served by core_h5p
 * from the package; the page around it is what is drawn here.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** @var stdClass The h5pactivity row. */
    protected stdClass $instance;

    /** @var context The activity's context. */
    protected context $context;

    /** @var json_store The activity's rows. */
    protected json_store $store;

    /** @var json_file|null The package. */
    protected ?json_file $file;

    /** @var moodle_url The preview this is drawn on. */
    protected moodle_url $here;

    /**
     * Constructor.
     *
     * @param stdClass $instance The h5pactivity row.
     * @param context $context
     * @param json_store $store
     * @param json_file|null $file The package, when the payload carries one.
     * @param moodle_url $here The preview page, which every link stays on.
     */
    public function __construct(stdClass $instance, context $context, json_store $store, ?json_file $file, moodle_url $here) {
        $this->instance = $instance;
        $this->context = $context;
        $this->store = $store;
        $this->file = $file;
        $this->here = $here;
    }

    /**
     * The page, as mod/h5pactivity/view.php prints it after the header.
     *
     * @return string
     */
    public function page(): string {
        global $OUTPUT;
        $output = '';

        if (!$this->can_submit() && !isguestuser()) {
            $message = get_string('previewmode', 'mod_h5pactivity');
            $output .= $OUTPUT->notification($message, \core\output\notification::NOTIFY_INFO, false);
            if (!$this->is_tracking_enabled()) {
                if (has_capability('moodle/course:manageactivities', $this->context)) {
                    $url = $this->url_to(['update' => 1]);
                    $message = get_string('trackingdisabled_enable', 'mod_h5pactivity', $url->out());
                } else {
                    $message = get_string('trackingdisabled', 'mod_h5pactivity');
                }
                $output .= $OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING);
            }
        }

        $extraactions = [];
        // The real page offers the attempts report and, below the player,
        // editing the content. Nobody may act on an activity that does not
        // exist: neither is offered.

        if ($this->file === null || $this->file->get_url() === null) {
            return $output . $OUTPUT->notification(get_string('courseai_preview_empty', 'local_coursegen'),
                \core\output\notification::NOTIFY_INFO);
        }

        $core = (new factory())->get_core();
        $config = helper::decode_display_options($core, (int) $this->instance->displayoptions);
        $output .= $this->display($this->file->get_url(), $config, true, 'mod_h5pactivity', false, $extraactions);
        return $output;
    }

    /**
     * Ported from core_h5p\player::display().
     *
     * @param string $url
     * @param stdClass $config
     * @param bool $preventredirect
     * @param string $component
     * @param bool $displayedit
     * @param array $extraactions
     * @return string
     */
    protected function display(string $url, stdClass $config, bool $preventredirect = true, string $component = '',
            bool $displayedit = false, array $extraactions = []): string {
        global $OUTPUT;
        $params = [
                'url' => $url,
                'preventredirect' => $preventredirect,
                'component' => $component,
            ];

        $optparams = ['frame', 'export', 'embed', 'copyright'];
        foreach ($optparams as $optparam) {
            if (!empty($config->$optparam)) {
                $params[$optparam] = $config->$optparam;
            }
        }
        $fileurl = new moodle_url('/h5p/embed.php', $params);

        $template = new stdClass();
        $template->embedurl = $fileurl->out(false);

        if ($displayedit) {
            // Check if the user can edit this content.
            if ($this->can_edit_content()) {
                $template->editurl = (new moodle_url('/h5p/edit.php', ['url' => $url]))->out(false);
            }
        }

        $template->extraactions = [];
        foreach ($extraactions as $action) {
            $template->extraactions[] = $action->export_for_template($OUTPUT);
        }

        $result = $OUTPUT->render_from_template('core_h5p/h5pembed', $template);
        $result .= $this->get_resize_code();
        return $result;
    }

    /**
     * Ported from core_h5p\player::get_resize_code().
     *
     * @return string
     */
    protected function get_resize_code(): string {
        global $OUTPUT;

        $template = new stdClass();
        $template->resizeurl = autoloader::get_h5p_core_library_url('js/h5p-resizer.js');

        return $OUTPUT->render_from_template('core_h5p/h5presize', $template);
    }

    /**
     * core_h5p\api::can_edit_content(), for a module's package: who may add the module may edit it.
     *
     * @return bool
     */
    protected function can_edit_content(): bool {
        $context = context::instance_by_id($this->file->get_contextid(), IGNORE_MISSING) ?: $this->context;
        return has_capability('mod/h5pactivity:addinstance', $context);
    }

    /**
     * mod_h5pactivity\local\manager::can_submit().
     *
     * @return bool
     */
    protected function can_submit(): bool {
        global $USER;
        return has_capability('mod/h5pactivity:submit', $this->context, $USER, false);
    }

    /**
     * mod_h5pactivity\local\manager::can_view_all_attempts().
     *
     * @return bool
     */
    protected function can_view_all_attempts(): bool {
        if (!$this->instance->enabletracking) {
            return false;
        }
        return has_capability('mod/h5pactivity:reviewattempts', $this->context);
    }

    /**
     * mod_h5pactivity\local\manager::is_tracking_enabled().
     *
     * @return bool
     */
    protected function is_tracking_enabled(): bool {
        return (bool) $this->instance->enabletracking;
    }

    /**
     * mod_h5pactivity\local\manager::count_attempts(): the attempts made, which the payload does not carry.
     *
     * @return int
     */
    protected function count_attempts(): int {
        return $this->store->count_records('h5pactivity_attempts', ['h5pactivityid' => $this->instance->id, 'completion' => 1]);
    }

    /**
     * A link to the preview with extra parameters, where the page linked to another of the module's pages.
     *
     * @param array $params
     * @return moodle_url
     */
    protected function url_to(array $params): moodle_url {
        $url = new moodle_url($this->here);
        foreach ($params as $name => $value) {
            $url->param($name, $value);
        }
        return $url;
    }
}
