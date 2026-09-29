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
 * Class resource_export
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_export extends base_export {
    /**
     * A File's raw description, its appearance settings and its document's
     * identity.
     *
     * The generated activity always builds a NEW document; the mold's own file
     * never travels as bytes. Its name and extension do, because the generated
     * document is produced in the same format the author chose here.
     *
     * @return array
     */
    public function parameters(): array {
        global $DB;

        $resource = $DB->get_record('resource', ['id' => $this->cm->instance]);
        if (!$resource) {
            return $this->minimal_parameters();
        }

        $parameters = array_merge(
            $this->display_settings($resource),
            [
                'name' => $this->cm->name,
                'section' => (int) $this->cm->sectionnum,
                'intro' => $resource->intro ?? '',
            ]
        );

        $moldfile = $this->mold_file();
        if ($moldfile !== null) {
            $parameters['moldfile'] = $moldfile;
        }

        return $parameters;
    }

    /**
     * The appearance settings worth reproducing on the generated activity.
     *
     * mod_resource stores them serialized in displayoptions and, unlike
     * mod_url, it only writes the checkbox options when they are ON (see
     * resource_set_display_options). An absent key therefore means OFF, not
     * "fall back to the site default" - reading it the other way would turn
     * on options the author deliberately left off.
     *
     * @param \stdClass $resource
     * @return array
     */
    private function display_settings($resource): array {
        $options = [];
        if (!empty($resource->displayoptions)) {
            $options = (array) unserialize_array($resource->displayoptions);
        }
        $config = get_config('resource');

        return [
            'display' => (int) ($resource->display ?? $config->display ?? 0),
            // Only meaningful for AUTO/EMBED/FRAME, where mod_resource writes it.
            'printintro' => (int) ($options['printintro'] ?? $config->printintro ?? 1),
            'showsize' => (int) ($options['showsize'] ?? 0),
            'showtype' => (int) ($options['showtype'] ?? 0),
            'showdate' => (int) ($options['showdate'] ?? 0),
            'popupwidth' => (int) ($options['popupwidth'] ?? $config->popupwidth ?? 620),
            'popupheight' => (int) ($options['popupheight'] ?? $config->popupheight ?? 450),
            'filterfiles' => (int) ($resource->filterfiles ?? $config->filterfiles ?? 0),
        ];
    }

    /**
     * The identity of the document the mold carries, or null when it has none.
     *
     * Only what the service needs to reproduce the format: never the bytes.
     *
     * @return array|null
     */
    private function mold_file(): ?array {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $this->cm->context->id,
            'mod_resource',
            'content',
            0,
            'sortorder DESC, id ASC',
            false
        );
        $file = reset($files);
        if (!$file) {
            return null;
        }

        $filename = $file->get_filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return [
            'filename' => $filename,
            'mimetype' => (string) $file->get_mimetype(),
            'extension' => $extension,
        ];
    }
}
