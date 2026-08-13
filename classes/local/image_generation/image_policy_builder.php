<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursegen\local\image_generation;

/**
 * Builds the image generation policy sent to the AI service.
 *
 * Reads the current image generation configuration stored in the
 * local_coursegen plugin settings and combines it with the activity
 * definitions from {@see activities} into a structured array with the
 * global mode, override flags, and per-activity policies. Shared by both
 * the course planning flow and the single-activity flow, since both send
 * the same `image_policy` payload key to the AI service.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class image_policy_builder {
    /**
     * Build the image generation policy from plugin settings.
     *
     * @return array
     */
    public static function build(): array {
        $mode = get_config('local_coursegen', 'generationmode') ?: activities::MODE_DISABLED;
        $overridecourse = (bool) ((int) get_config('local_coursegen', 'overridecourse') === 1);
        $overrideactivity = (bool) ((int) get_config('local_coursegen', 'overrideactivity') === 1);

        $activitiesconfig = array_map(
            fn(array $definition): array => self::build_activity_policy($definition),
            activities::get_definitions()
        );

        return [
            'mode' => $mode,
            'overridecourse' => $overridecourse,
            'overrideactivity' => $overrideactivity,
            'activities' => $activitiesconfig,
        ];
    }

    /**
     * Build the image policy for a single activity type from its definition.
     *
     * @param array $definition Activity definition from activities::get_definitions()
     * @return array
     */
    private static function build_activity_policy(array $definition): array {
        $enabled = (int) get_config('local_coursegen', $definition['configenable']) === 1;

        $partsconfig = array_map(
            fn(array $part): array => self::build_part_policy($part),
            $definition['parts'] ?? []
        );

        return [
            'id' => $definition['id'],
            'enabled' => $enabled,
            'parts' => $partsconfig,
        ];
    }

    /**
     * Build the image policy for a single part from its definition.
     *
     * @param array $part Part definition from activities::get_definitions()
     * @return array
     */
    private static function build_part_policy(array $part): array {
        $enabled = (int) get_config('local_coursegen', $part['configenable']) === 1;

        $maximages = 0;
        $configmaximages = $part['configmaximages'] ?? null;
        if ($configmaximages !== null) {
            $savedmax = (int) get_config('local_coursegen', $configmaximages);
            if ($savedmax > 0) {
                $maximages = $savedmax;
            }
        }

        return [
            'id' => $part['id'],
            'enabled' => $enabled,
            'maximages' => $maximages,
        ];
    }
}
