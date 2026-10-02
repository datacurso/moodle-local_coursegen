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

namespace local_coursegen\local\service;

use core\context\course;
use core\context\system;
use core\exception\moodle_exception;
use local_coursegen\event\generation_warning;
use local_coursegen\local\warning_collector;
use local_coursegen\mod_settings\base_settings;
use local_coursegen\utils\text_editor_parameter_cleaner;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/modlib.php');

/**
 * Class create_mod_service
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_mod_service {
    /**
     * Create module from response of AI service.
     *
     * @param array $resultinfo Result info from response of AI service
     * @param object $course Course object
     * @param int $sectionnum Section number where the module will be created
     * @param int|null $beforemod Before module id where the module will be created
     *
     * @return object New course module.
     */
    public static function create_from_ai_result($resultinfo, $course, $sectionnum, $beforemod = null) {
        return self::create_from_ai_result_with_warnings($resultinfo, $course, $sectionnum, $beforemod)['cm'];
    }

    /**
     * Create module from response of AI service and report the non-fatal warnings.
     *
     * Steps that fail without invalidating the module (a file that could not be
     * downloaded, a question that could not be created, ...) are recorded as
     * warnings: each one triggers a generation_warning event in the course
     * context and is returned as a short string for the client.
     *
     * @param array $resultinfo Result info from response of AI service
     * @param object $course Course object
     * @param int $sectionnum Section number where the module will be created
     * @param int|null $beforemod Before module id where the module will be created
     *
     * @return array Array with 'cm' (the new course module) and 'warnings' (string[]).
     */
    public static function create_from_ai_result_with_warnings($resultinfo, $course, $sectionnum, $beforemod = null): array {
        // Warnings are request-scoped: forget anything left by a previous activity.
        warning_collector::reset();

        $modname = is_array($resultinfo) && is_string($resultinfo['resource_type'] ?? null) ? $resultinfo['resource_type'] : '';
        $settings = null;
        $warnings = [];
        try {
            self::validate_resultinfo($resultinfo);

            self::validate_mod_existence($modname);

            [ $module, $context, $cw, $cm, $data ] = prepare_new_moduleinfo_data($course, $modname, $sectionnum);

            $mform = self::create_mod_form_instance($modname, $data, $cw, $cm, $course);

            $parameters = self::prepare_parameters($modname, $resultinfo['parameters'], $sectionnum, $beforemod, $module->id);

            $newcm = add_moduleinfo($parameters, $course, $mform);

            // Modules without extra settings may omit mod_settings altogether.
            $modsettings = (array) ($parameters->mod_settings ?? []);

            // The handler is kept at hand so its warnings are audited even if a later step throws.
            $settings = self::settings_handler($modname, $newcm, $modsettings);
            if ($settings !== null) {
                $settings->add_settings();
            }
        } finally {
            // Whatever happened, the warnings collected so far are audited; when the
            // creation threw, the exception keeps propagating after the audit.
            $warnings = array_merge(warning_collector::drain(), $settings !== null ? $settings->get_warnings() : []);
            self::audit_warnings($course, $modname, $warnings);
        }

        return [
            'cm' => $newcm,
            'warnings' => warning_collector::to_messages($warnings),
        ];
    }

    /**
     * Fire one generation_warning per collected warning in the course context.
     *
     * Auditing never masks the generation outcome: a failure here is reported
     * through debugging() only.
     *
     * @param object $course Course object.
     * @param string $modname Module plugin name ('' when unknown).
     * @param array $warnings Warning entries.
     * @return void
     */
    private static function audit_warnings($course, string $modname, array $warnings): void {
        if (empty($warnings)) {
            return;
        }
        try {
            $context = course::instance((int) $course->id);
        } catch (\Throwable $e) {
            debugging('local_coursegen: could not resolve the course context to audit warnings: ' . $e->getMessage());
            $context = system::instance();
        }
        generation_warning::trigger_all($context, $modname, $warnings);
    }

    /**
     * Validate AI result structure.
     *
     * @param array $resultinfo Response payload from the AI service.
     * @return void
     * @throws moodle_exception If resource_type or parameters are missing.
     */
    private static function validate_resultinfo($resultinfo) {
        if (!isset($resultinfo['resource_type'])) {
            throw new moodle_exception('error_missing_resource_type', 'local_coursegen');
        }

        if (!isset($resultinfo['parameters'])) {
            throw new moodle_exception('error_missing_parameters', 'local_coursegen');
        }
    }

    /**
     * Build the module add/edit form instance for a given plugin.
     *
     * @param string $modname Module plugin name (e.g., 'page').
     * @param object $data Default form data object.
     * @param object $cw Course section record.
     * @param object $cm Course module record.
     * @param object $course Course record.
     * @return \moodleform_mod Module form instance.
     */
    private static function create_mod_form_instance($modname, $data, $cw, $cm, $course) {
        // This is necessary to void error when load the mod_form.php file.
        global $CFG;
        $modmoodleform = self::get_mod_form_file_path($modname);
        require_once($modmoodleform);

        $mformclassname = 'mod_' . $modname . '_mod_form';
        $mform = new $mformclassname($data, $cw->section, $cm, $course);

        return $mform;
    }

    /**
     * Ensure the module is installed, enabled and its mod_form exists.
     *
     * @param string $modname Module plugin name.
     * @return void
     * @throws moodle_exception If the mod_form file is not found, the module is not installed
     *                          or it is disabled by the administrator.
     */
    private static function validate_mod_existence($modname) {
        global $DB;

        $modmoodleform = self::get_mod_form_file_path($modname);
        if (!file_exists($modmoodleform)) {
            throw new moodle_exception('error_invalid_resource_type', 'local_coursegen', '', $modname);
        }

        // Existing on disk is not enough: the module must be installed on the
        // site and enabled by the administrator, otherwise the creation would
        // fail halfway (or succeed into an unusable, hidden activity type).
        $module = $DB->get_record('modules', ['name' => $modname]);
        if (!$module) {
            throw new moodle_exception('error_invalid_resource_type', 'local_coursegen', '', $modname);
        }
        if ((int) $module->visible !== 1) {
            throw new moodle_exception('error_module_disabled', 'local_coursegen', '', $modname);
        }
    }

    /**
     * Get absolute path to a module's mod_form.php.
     *
     * @param string $modname Module plugin name.
     * @return string Absolute file path.
     */
    public static function get_mod_form_file_path($modname) {
        global $CFG;
        return "$CFG->dirroot/mod/$modname/mod_form.php";
    }

    /**
     * Build and normalize parameters before creating the module.
     *
     * Cleans editor fields and sets section, ordering and module id.
     *
     * @param string $modname Module plugin name.
     * @param array|object $rawparameters Raw parameters from AI.
     * @param int $sectionnum Target section number.
     * @param int|null $beforemod Optional cm id to insert before.
     * @param int $moduleid Module id from 'modules' table.
     * @return object Parameters ready for add_moduleinfo().
     */
    private static function prepare_parameters($modname, $rawparameters, $sectionnum, $beforemod, $moduleid) {
        $cleanedparameters = text_editor_parameter_cleaner::clean_text_editor_objects($rawparameters);
        $parameters = (object)$cleanedparameters;
        $parameters->section = $sectionnum;
        $parameters->beforemod = $beforemod;
        $parameters->module = $moduleid;

        $parameters = self::process_mod_parameters($modname, $parameters);

        return $parameters;
    }

    /**
     * Normalize module-specific parameters using the plugin parameters class, if available.
     *
     * Resolves the handler for the given module and lets it adjust or enrich the
     * parameter object before creation.
     *
     * @param string $modname Module plugin name.
     * @param object $parameters Parameters object to process.
     * @return object Processed parameters object.
     */
    private static function process_mod_parameters($modname, $parameters) {
        $paramclass = self::get_parameter_class($modname);

        if (!self::is_valid_parameter_class($paramclass)) {
            // A package-type contract (mod_settings carrying file_path/file_name)
            // requires a parameters handler to download and attach the package.
            // Falling through silently would create a contentless activity, so
            // fail with a diagnostic instead (typical cause: stale class map
            // after deployment; purge the site caches).
            if (self::has_package_contract($parameters)) {
                throw new moodle_exception('error_parameters_handler_unresolved', 'local_coursegen', '', $paramclass);
            }
            return $parameters;
        }

        /** @var \local_coursegen\mod_parameters\base_parameters $paraminstance */
        $paraminstance = new $paramclass($parameters);
        $parameters = $paraminstance->get_parameters();

        return $parameters;
    }

    /**
     * Check whether the parameters carry a package-type contract.
     *
     * A package-type result includes mod_settings with the remote file_path
     * or file_name of the generated package; those fields can only be
     * consumed by a module parameters handler.
     *
     * @param object $parameters Parameters object from the AI result.
     * @return bool True when mod_settings carries package file fields.
     */
    private static function has_package_contract($parameters): bool {
        $modsettings = $parameters->mod_settings ?? null;
        if (is_object($modsettings)) {
            $modsettings = (array) $modsettings;
        }

        if (!is_array($modsettings)) {
            return false;
        }

        return isset($modsettings['file_path']) || isset($modsettings['file_name']);
    }

    /**
     * Get the fully qualified class name of the module-specific parameters class.
     *
     * @param string $modname Module plugin name.
     * @return string Fully-qualified class name.
     */
    private static function get_parameter_class($modname) {
        $paramclass = '\\local_coursegen\\mod_parameters\\' . $modname . '_parameters';
        return $paramclass;
    }

    /**
     * Check that a parameters class exists and extends base_parameters.
     *
     * @param string $class Class name to validate.
     * @return bool True if usable.
     */
    private static function is_valid_parameter_class($class) {
        $classexists = class_exists($class);
        $issubclass = is_subclass_of($class, \local_coursegen\mod_parameters\base_parameters::class);
        return $classexists && $issubclass;
    }

    /**
     * Build the post-creation settings handler of the module, if any.
     *
     * The caller runs add_settings() on the returned instance and reads its
     * warnings afterwards (also when a later step fails).
     *
     * @param string $modname Module plugin name.
     * @param object $newcm Newly created course module.
     * @param array $modsettings Settings to apply.
     * @return base_settings|null The handler, or null when there is nothing to apply.
     */
    private static function settings_handler(string $modname, $newcm, array $modsettings): ?base_settings {
        if (empty($modsettings)) {
            return null;
        }

        $classpath = self::get_settings_class($modname);
        if (!self::is_valid_settings_class($classpath)) {
            debugging(
                "local_coursegen: mod_settings received for '{$modname}' but no {$classpath} handler exists; settings discarded.",
                DEBUG_DEVELOPER
            );
            return null;
        }

        return new $classpath($newcm, $modsettings);
    }

    /**
     * Get the fully qualified class name of the module settings class.
     *
     * @param string $modname Module plugin name.
     * @return string Fully-qualified class name.
     */
    private static function get_settings_class($modname) {
        $paramclass = '\\local_coursegen\\mod_settings\\' . $modname . '_settings';
        return $paramclass;
    }

    /**
     * Check that a settings class exists and extends base_settings.
     *
     * @param string $class Class name to validate.
     * @return bool True if usable.
     */
    private static function is_valid_settings_class($class) {
        $classexists = class_exists($class);
        $issubclass = is_subclass_of($class, base_settings::class);
        return $classexists && $issubclass;
    }
}
