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

namespace local_coursegen\mod_settings;

/**
 * Class assign_settings
 *
 * Creates the AI-generated rubric definition for the assignment and activates
 * the rubric grading method, so the rubric is immediately usable for grading.
 * Without this, the rubric the AI service sends in mod_settings was discarded
 * and the assignment stayed on the rubric method with no definition.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_settings extends base_settings {
    /**
     * Create the AI-generated rubric for the assignment, if any.
     */
    public function add_settings() {
        global $CFG;

        $rubric = $this->modsettings['rubric'] ?? null;
        if (empty($rubric)) {
            // No rubric was generated, so the service never requested the rubric
            // grading method; there is nothing to create or to deactivate.
            return;
        }

        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $context = \context_module::instance($this->cm->coursemodule);
        $manager = get_grading_manager($context, 'mod_assign', 'submissions');

        // A rubric WAS requested, so add_moduleinfo already activated the rubric
        // method (advancedgradingmethod_submissions). From here on, every path
        // that cannot produce a usable definition must deactivate the method,
        // otherwise the assignment is left on "rubric not defined" and cannot
        // be graded.
        $criteria = $this->build_criteria((array) ($rubric['criteria'] ?? []));
        if (empty($criteria)) {
            debugging('coursegen: AI rubric had no usable criteria; falling back to simple grading.',
                DEBUG_DEVELOPER);
            $this->reset_grading_method($manager);
            return;
        }

        try {
            // Create the definition BEFORE activating the method: if the definition
            // fails, the method is deactivated below and the assignment degrades to
            // simple direct grading instead of an ungradeable "rubric not defined".
            $controller = $manager->get_controller('rubric');

            $name = trim((string) ($rubric['name'] ?? ''));
            $definition = (object) [
                'name' => $name !== '' ? $name : get_string('pluginname', 'gradingform_rubric'),
                'description_editor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
                'status' => \gradingform_controller::DEFINITION_STATUS_READY,
                'rubric' => [
                    'criteria' => $criteria,
                    'options' => \gradingform_rubric_controller::get_default_options(),
                ],
            ];
            $controller->update_definition($definition);

            $manager->set_active_method('rubric');
        } catch (\Throwable $exception) {
            debugging(
                'coursegen: could not create the assignment rubric: ' . $exception->getMessage(),
                DEBUG_DEVELOPER
            );
            $this->reset_grading_method($manager);
        }
    }

    /**
     * Deactivate the advanced grading method so the assignment stays gradeable.
     *
     * Used when a rubric was requested but no usable definition could be
     * created: add_moduleinfo already activated the rubric method, so leaving
     * it active without a definition would make the assignment ungradeable.
     *
     * @param \grading_manager $manager Grading manager for the module context.
     */
    private function reset_grading_method(\grading_manager $manager): void {
        try {
            $manager->set_active_method('');
        } catch (\Throwable $exception) {
            debugging(
                'coursegen: could not reset the grading method: ' . $exception->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Map the AI rubric criteria to the shape gradingform_rubric expects.
     *
     * New criteria and levels must be keyed 'NEWID%d'; the AI sends the level
     * score under 'points' while Moodle stores it as 'score'. Criteria without
     * a description or with fewer than two usable levels are skipped.
     *
     * @param array $criteria AI rubric criteria (rows).
     * @return array Criteria array for gradingform_rubric_controller::update_definition().
     */
    private function build_criteria(array $criteria): array {
        $result = [];
        $sort = 1;
        foreach ($criteria as $criterion) {
            $description = trim((string) ($criterion['description'] ?? ''));
            if ($description === '') {
                continue;
            }

            $levels = [];
            $levelid = 1;
            foreach ((array) ($criterion['levels'] ?? []) as $level) {
                $definition = trim((string) ($level['definition'] ?? ''));
                if ($definition === '') {
                    continue;
                }
                $levels['NEWID' . $levelid] = [
                    'definition' => $definition,
                    'score' => (float) ($level['points'] ?? 0),
                ];
                $levelid++;
            }
            if (count($levels) < 2) {
                continue;
            }

            $result['NEWID' . $sort] = [
                'sortorder' => $sort,
                'description' => $description,
                'levels' => $levels,
            ];
            $sort++;
        }
        return $result;
    }
}
