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

namespace local_coursegen\local\image_generation;

use local_coursegen\local\tenant_config;

/**
 * Per-tenant image generation settings.
 *
 * Wraps every configuration key declared by {@see activities} (plus the
 * global mode and override flags) behind tenant_config: each tenant has its
 * own values, and a key the tenant does not store resolves to its default.
 *
 * @package    local_coursegen
 * @copyright  2026 Josue Condori <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class image_settings {
    /** @var string Setting holding the global generation mode. */
    public const KEY_MODE = 'generationmode';

    /** @var string Setting allowing course level overrides. */
    public const KEY_OVERRIDE_COURSE = 'overridecourse';

    /** @var string Setting allowing activity level overrides. */
    public const KEY_OVERRIDE_ACTIVITY = 'overrideactivity';

    /** @var int Maximum number of images accepted per activity part. */
    public const MAX_IMAGES = 5;

    /** @var string[] Accepted generation modes. */
    private const MODES = [activities::MODE_DISABLED, activities::MODE_AUTO, activities::MODE_MANUAL];

    /**
     * Effective image generation settings of a tenant, keyed by configuration name.
     *
     * Flags are returned as 0/1 integers, maximum images as a non-negative
     * integer and the mode as one of the {@see activities} MODE_* constants.
     *
     * @param int $tenantid Tenant id.
     * @return array<string, int|string>
     */
    public static function get_settings(int $tenantid): array {
        $settings = [
            self::KEY_MODE => self::read_mode($tenantid),
            self::KEY_OVERRIDE_COURSE => self::read_flag(self::KEY_OVERRIDE_COURSE, $tenantid),
            self::KEY_OVERRIDE_ACTIVITY => self::read_flag(self::KEY_OVERRIDE_ACTIVITY, $tenantid),
        ];

        foreach (activities::get_definitions() as $definition) {
            $settings[$definition['configenable']] = self::read_flag($definition['configenable'], $tenantid);
            foreach ($definition['parts'] ?? [] as $part) {
                $settings[$part['configenable']] = self::read_flag($part['configenable'], $tenantid);
                if (isset($part['configmaximages'])) {
                    $settings[$part['configmaximages']] = self::read_maximages($part['configmaximages'], $tenantid);
                }
            }
        }

        return $settings;
    }

    /**
     * Stores the settings submitted by the management page for a tenant.
     *
     * The payload mirrors the web service: generationmode, overridecourse,
     * overrideactivity and an activities list of {id, enabled, parts: [{id,
     * enabled, maximages}]}. Activities missing from the list are left
     * untouched; parts missing from a submitted activity are switched off;
     * unknown activity ids are ignored; maximages is capped at MAX_IMAGES.
     *
     * @param array $payload Submitted settings.
     * @param int $tenantid Tenant id.
     * @return void
     * @throws \invalid_parameter_exception When the generation mode is unknown.
     */
    public static function save_settings(array $payload, int $tenantid): void {
        $mode = (string) ($payload[self::KEY_MODE] ?? '');
        if (!in_array($mode, self::MODES, true)) {
            throw new \invalid_parameter_exception('Unknown image generation mode: ' . $mode);
        }

        tenant_config::set(self::KEY_MODE, $mode, $tenantid);
        tenant_config::set(self::KEY_OVERRIDE_COURSE, self::to_flag($payload[self::KEY_OVERRIDE_COURSE] ?? 0), $tenantid);
        tenant_config::set(self::KEY_OVERRIDE_ACTIVITY, self::to_flag($payload[self::KEY_OVERRIDE_ACTIVITY] ?? 0), $tenantid);

        $submitted = self::index_by_id($payload['activities'] ?? []);
        foreach (activities::get_definitions() as $definition) {
            if (!array_key_exists($definition['id'], $submitted)) {
                continue;
            }
            self::save_activity($definition, $submitted[$definition['id']], $tenantid);
        }
    }

    /**
     * Stores the flags of one activity and of each of its parts.
     *
     * @param array $definition Activity definition from activities::get_definitions().
     * @param array $activity Submitted activity settings.
     * @param int $tenantid Tenant id.
     * @return void
     */
    private static function save_activity(array $definition, array $activity, int $tenantid): void {
        tenant_config::set($definition['configenable'], self::to_flag($activity['enabled'] ?? 0), $tenantid);

        $submittedparts = self::index_by_id($activity['parts'] ?? []);
        foreach ($definition['parts'] ?? [] as $part) {
            $submittedpart = $submittedparts[$part['id']] ?? null;
            $enabled = $submittedpart ? self::to_flag($submittedpart['enabled'] ?? 0) : 0;
            tenant_config::set($part['configenable'], $enabled, $tenantid);

            if (isset($part['configmaximages'])) {
                $maximages = $submittedpart ? self::cap_maximages($submittedpart['maximages'] ?? 0) : 0;
                tenant_config::set($part['configmaximages'], $maximages, $tenantid);
            }
        }
    }

    /**
     * Returns the effective generation mode of a tenant.
     *
     * @param int $tenantid Tenant id.
     * @return string
     */
    private static function read_mode(int $tenantid): string {
        $mode = (string) tenant_config::get(self::KEY_MODE, '', $tenantid);
        return in_array($mode, self::MODES, true) ? $mode : activities::MODE_DISABLED;
    }

    /**
     * Returns an on/off setting of a tenant as 0 or 1.
     *
     * @param string $name Setting name.
     * @param int $tenantid Tenant id.
     * @return int
     */
    private static function read_flag(string $name, int $tenantid): int {
        return (int) tenant_config::get($name, 0, $tenantid) === 1 ? 1 : 0;
    }

    /**
     * Returns the maximum number of images of a part for a tenant (never negative).
     *
     * @param string $name Setting name.
     * @param int $tenantid Tenant id.
     * @return int
     */
    private static function read_maximages(string $name, int $tenantid): int {
        return max(0, (int) tenant_config::get($name, 0, $tenantid));
    }

    /**
     * Normalises a submitted on/off value to 0 or 1.
     *
     * @param mixed $value Submitted value.
     * @return int
     */
    private static function to_flag($value): int {
        return !empty($value) ? 1 : 0;
    }

    /**
     * Caps a submitted maximum number of images to [0, MAX_IMAGES].
     *
     * @param mixed $value Submitted value.
     * @return int
     */
    private static function cap_maximages($value): int {
        return min(max(0, (int) $value), self::MAX_IMAGES);
    }

    /**
     * Indexes submitted activities or parts by their id, skipping malformed entries.
     *
     * @param array $items Submitted list.
     * @return array<string, array>
     */
    private static function index_by_id(array $items): array {
        $indexed = [];
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['id'])) {
                $indexed[(string) $item['id']] = $item;
            }
        }
        return $indexed;
    }
}
