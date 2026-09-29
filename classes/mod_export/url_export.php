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
 * Class url_export
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_export extends base_export {
    /**
     * A URL's address, its raw description and its display settings.
     *
     * The description is the marker-bearing field the service fills in, so it
     * travels raw - formatting or filtering it here would destroy the mold.
     * The address travels verbatim too: a plain link is reused as is, while a
     * marked one tells the service to resolve it for the new course.
     *
     * @return array
     */
    public function parameters(): array {
        global $DB;

        $url = $DB->get_record('url', ['id' => $this->cm->instance]);
        if (!$url) {
            return $this->minimal_parameters();
        }

        return array_merge(
            $this->display_settings($url),
            [
                'name' => $this->cm->name,
                'section' => (int) $this->cm->sectionnum,
                'intro' => $url->intro ?? '',
                'externalurl' => $url->externalurl ?? '',
            ]
        );
    }

    /**
     * The display settings worth reproducing on the generated activity.
     *
     * mod_url stores them serialized in displayoptions but rebuilds that blob
     * from flat fields on save (see url_add_instance), so the flat shape is
     * what the generated activity can actually consume. Missing entries fall
     * back to the site defaults rather than travelling as nulls.
     *
     * @param \stdClass $url
     * @return array
     */
    private function display_settings($url): array {
        $options = [];
        if (!empty($url->displayoptions)) {
            $options = (array) unserialize_array($url->displayoptions);
        }
        $config = get_config('url');

        return [
            'display' => (int) ($url->display ?? $config->display ?? 0),
            'printintro' => (int) ($options['printintro'] ?? $config->printintro ?? 1),
            'popupwidth' => (int) ($options['popupwidth'] ?? $config->popupwidth ?? 620),
            'popupheight' => (int) ($options['popupheight'] ?? $config->popupheight ?? 450),
        ];
    }
}
