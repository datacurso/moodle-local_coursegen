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

namespace local_coursegen\mod_parameters;

use local_coursegen\local\url_content_validator;

defined('MOODLE_INTERNAL') || die();

/**
 * Class url_parameters
 *
 * Validates that the AI-generated URL still points to current, usable content
 * before the URL activity is created. When the page is not reachable, has no
 * readable content, or no longer relates to the activity topic, the module
 * creation is aborted with a clear error instead of generating a dead or
 * outdated link.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_parameters extends base_parameters {
    /**
     * Validate the external URL and leave the parameters unchanged when valid.
     *
     * @return object Adjusted parameters for the URL module.
     * @throws \moodle_exception When the URL content is not current or usable.
     */
    public function get_parameters() {
        $url = trim((string)($this->parameters->externalurl ?? ''));
        if ($url === '') {
            return $this->parameters;
        }

        $topic = trim((string)($this->parameters->name ?? ''));

        $validator = new url_content_validator();
        $result = $validator->validate($url, $topic === '' ? null : $topic);
        if (!$result->is_valid()) {
            $reason = get_string($result->get_reason(), 'local_coursegen', $result->get_reason_params());
            throw new \moodle_exception('error_invalid_url_content', 'local_coursegen', '', $url . ' — ' . $reason);
        }

        return $this->parameters;
    }
}