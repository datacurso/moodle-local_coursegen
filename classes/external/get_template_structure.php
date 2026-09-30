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

/**
 * External API for the professor-facing template guided form: the template's
 * section/activity structure (with the admin-defined lock state applied) and
 * the catalog of activity types the professor may add.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use external_api;
use external_function_parameters;
use external_value;
use context_system;
use local_coursegen\local\models\template;
use local_coursegen\local\service\supported_activity_types;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Builds the guided-form structure for a given template.
 */
class get_template_structure extends external_api {
    use get_template_structure_rows;
    use get_template_structure_schema;

    /** @var string The catalog entry field holding a module's display name, which the catalog is sorted by. */
    private const CATALOG_NAME_FIELD = 'displayname';

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'Template ID'),
        ]);
    }

    /**
     * Return the template's structure, locked/reference state and allowed activity catalog.
     *
     * @param int $templateid Template ID.
     * @return array
     */
    public static function execute($templateid) {
        global $OUTPUT;

        $parameterdescription = self::execute_parameters();
        $params = self::validate_parameters($parameterdescription, ['templateid' => $templateid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $template = template::get_record(['id' => $params['templateid']]);
        if (!$template) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }

        $courseid = $template->get('courseid');
        $course  = get_course($courseid);
        $modinfo = get_fast_modinfo($course);

        $sectionsettings = self::section_settings($template);
        $activitysettings = self::activity_settings($template);
        $instancesbysection = self::instances_by_section($template);
        $sections = self::sections($course, $modinfo, $sectionsettings, $activitysettings, $instancesbysection, $OUTPUT);

        $nolimit = (bool) $template->get('nolimit');
        $maxsections = $template->get('maxsections') ?? 0;
        $maxsections = (int) $maxsections;
        // The stored value already IS the extra allowance - how many sections
        // the professor may add ON TOP of the template's own - so the
        // template's sections never get subtracted from it.
        if ($nolimit) {
            $remaining = 0;
        } else {
            $remaining = max(0, $maxsections);
        }

        $installedtypes = supported_activity_types::installed();
        $allowedactivities = self::allowed_activities($installedtypes, $OUTPUT);

        return [
            'nolimit' => $nolimit,
            'maxsections' => $maxsections,
            'remainingsections' => $remaining,
            'sections' => $sections,
            'allowedactivities' => $allowedactivities,
        ];
    }
}
