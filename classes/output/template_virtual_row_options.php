<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursegen\output;

use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_space;

/**
 * Build render contexts for virtual template rows.
 *
 * @package local_coursegen
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_virtual_row_options {
    /**
     * Build a virtual instance row context.
     *
     * @param template_instance $instance
     * @return array
     */
    public static function instance_row_context(template_instance $instance): array {
        $name = $instance->get('name');
        $typelabel = $instance->get('typelabel');
        $sourcename = $instance->get('sourcename');
        $modname = $instance->get('modname');
        $iconurl = self::instance_icon_url($modname);
        return [
            'instanceid' => (int) $instance->get('id'),
            'name' => $name,
            'typelabel' => $typelabel,
            'prompt' => (string) $instance->get('prompt'),
            'sourcecmid' => (int) $instance->get('sourcecmid'),
            'sourcename' => $sourcename,
            'modname' => (string) $modname,
            'iconurl' => $iconurl,
        ];
    }

    /**
     * Build a virtual space row context.
     *
     * @param template_space $space
     * @return array
     */
    public static function space_row_context(template_space $space): array {
        $modname = (string) $space->get('modname');
        $required = (bool) $space->get('required');
        $instruction = (string) $space->get('instruction');
        $typename = self::module_type_name($modname);
        $iconurl = self::instance_icon_url($modname);
        $badge = template_row_options::space_badge_label($required);
        return [
            'spaceid' => (int) $space->get('id'),
            'name' => $typename,
            'typelabel' => $typename,
            'modname' => $modname,
            'iconurl' => $iconurl,
            'required' => $required,
            'requiredvalue' => (int) $required,
            'badge' => $badge,
            'instruction' => $instruction,
            'hasinstruction' => $instruction !== '',
        ];
    }

    /**
     * Return the installed module's localized name.
     *
     * @param string $modname
     * @return string
     */
    private static function module_type_name(string $modname): string {
        if (\core_component::get_plugin_directory('mod', $modname) === null) {
            return $modname;
        }
        return get_string('modulename', 'mod_' . $modname);
    }

    /**
     * Resolve a virtual row icon through Moodle's image URL helper.
     *
     * @param string|null $modname Null for older rows.
     * @return string
     */
    public static function instance_icon_url(?string $modname): string {
        global $OUTPUT;
        if ($modname === null || $modname === '') {
            return '';
        }
        $icon = $OUTPUT->image_url('monologo', $modname);
        if (\core_component::has_monologo_icon('mod', $modname)) {
            $icon->param('filtericon', 1);
        }
        return $icon->out(false);
    }
}
