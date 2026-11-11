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

use core_h5p\local\library\autoloader;
use moodle_url;
use stdClass;

/**
 * Copied from core_h5p\player::display() and get_resize_code(), kept apart
 * from view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait h5p_embed {
    /**
     * Copied from core_h5p\player::display().
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
        $params = $this->with_display_params($params, $config);
        $fileurl = new moodle_url('/h5p/embed.php', $params);

        $template = new stdClass();
        $template->embedurl = $fileurl->out(false);

        // Check if the user can edit this content.
        if ($displayedit && $this->can_edit_content()) {
            $editurl = new moodle_url('/h5p/edit.php', ['url' => $url]);
            $template->editurl = $editurl->out(false);
        }

        $template->extraactions = $this->exported_actions($extraactions);

        $result = $OUTPUT->render_from_template('core_h5p/h5pembed', $template);
        $result .= $this->get_resize_code();
        return $result;
    }

    /**
     * The embed url's own parameters, with whichever of the display config's
     * optional ones the config carries.
     *
     * @param array $params
     * @param stdClass $config
     * @return array
     */
    protected function with_display_params(array $params, stdClass $config): array {
        $optparams = ['frame', 'export', 'embed', 'copyright'];
        foreach ($optparams as $optparam) {
            if (!empty($config->$optparam)) {
                $params[$optparam] = $config->$optparam;
            }
        }
        return $params;
    }

    /**
     * The extra actions, exported for the template.
     *
     * @param array $extraactions
     * @return array
     */
    protected function exported_actions(array $extraactions): array {
        global $OUTPUT;

        $exported = [];
        foreach ($extraactions as $action) {
            $exported[] = $action->export_for_template($OUTPUT);
        }
        return $exported;
    }

    /**
     * Copied from core_h5p\player::get_resize_code().
     *
     * @return string
     */
    protected function get_resize_code(): string {
        global $OUTPUT;

        $template = new stdClass();
        $template->resizeurl = autoloader::get_h5p_core_library_url('js/h5p-resizer.js');

        return $OUTPUT->render_from_template('core_h5p/h5presize', $template);
    }
}
