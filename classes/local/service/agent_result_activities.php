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

/**
 * Reads the result of the template agent so that every activity says where it goes.
 *
 * The agent names the section of each activity on the row itself, while the plugin creates and previews an activity
 * from the section number its parameters carry. An activity the generator of its type wrote from scratch comes back
 * with a section of zero in its parameters, so it would land in the wrong place. Here the section of the row is
 * laid into the parameters, and the way the activity is made is stated: from the tree of its template activity when
 * the agent says so, from its settings otherwise. Only a result that the agent made is touched: the result of any
 * other flow is left alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_result_activities {
    /** @var string The key that only the result of the template agent carries. */
    private const AGENT_MARK = 'agent_report';

    /**
     * Whether the result was made by the template agent.
     *
     * @param array $result The result of a run.
     * @return bool
     */
    public static function is_agent_result(array $result): bool {
        return array_key_exists(self::AGENT_MARK, $result);
    }

    /**
     * The result with the section of every activity laid into its parameters, when the agent made it.
     *
     * @param array $result The result of a run.
     * @return array The same result; its generated activities carry their section in their parameters.
     */
    public static function placed(array $result): array {
        if (!self::is_agent_result($result)) {
            return $result;
        }
        $activities = $result['generated_activities'] ?? [];
        $result['generated_activities'] = self::with_sections((array) $activities);
        return $result;
    }

    /**
     * The activities with the section of each row laid into its parameters.
     *
     * @param array $activities The generated activities of a result.
     * @return array
     */
    public static function with_sections(array $activities): array {
        $placed = [];
        foreach ($activities as $activity) {
            $placed[] = self::with_section((array) $activity);
        }
        return $placed;
    }

    /**
     * One activity with the section of its row in its parameters and how it is made stated.
     *
     * @param array $activity One generated activity.
     * @return array The activity; unchanged when it has neither parameters nor a section.
     */
    private static function with_section(array $activity): array {
        $hasparameters = isset($activity['parameters']);
        $hassection = isset($activity['section']);
        if (!$hasparameters && !$hassection) {
            return $activity;
        }
        $parameters = $activity['parameters'] ?? [];
        $parameters = (array) $parameters;
        if (!array_key_exists('from_structure', $parameters)) {
            $parameters['from_structure'] = false;
        }
        if ($hassection) {
            $parameters['section'] = (int) $activity['section'];
        }
        $activity['parameters'] = $parameters;
        return $activity;
    }
}
