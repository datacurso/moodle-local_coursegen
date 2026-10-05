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
 * Capability definitions for DataCurso
 *
 * Documentation: {@link https://moodledev.io/docs/apis/subsystems/access}
 *
 * @package    local_coursegen
 * @category   access
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/coursegen:view_syllabus' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],
    // No code checks this capability any more; it stays defined because roles and
    // overrides refer to it and the capabilities carved out of it copy its permissions.
    'local/coursegen:managesysteminstructions' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // No code checks this capability any more; it stays defined because roles and
    // overrides refer to it and the capabilities carved out of it copy its permissions.
    'local/coursegen:createcoursewithai' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:create',
    ],

    'local/coursegen:createactivitywithai' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],

    // No code checks this capability any more; it stays defined because roles and
    // overrides refer to it and the capabilities carved out of it copy its permissions.
    'local/coursegen:manageimagegeneration' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // No code checks this capability any more; it stays defined because roles and
    // overrides refer to it and the capabilities carved out of it copy its permissions.
    'local/coursegen:managetemplates' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Course templates, one capability per operation. Carved out of managetemplates, which is now only
    // the source their permissions are copied from when the plugin is upgraded.
    'local/coursegen:viewtemplates' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:managetemplates',
    ],
    'local/coursegen:createtemplates' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:managetemplates',
    ],
    'local/coursegen:edittemplates' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:managetemplates',
    ],
    'local/coursegen:deletetemplates' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:managetemplates',
    ],

    // System instructions. Carved out of managesysteminstructions, which is now only
    // the source their permissions are copied from when the plugin is upgraded.
    'local/coursegen:viewsysteminstructions' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:managesysteminstructions',
    ],
    'local/coursegen:createsysteminstructions' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:managesysteminstructions',
    ],
    'local/coursegen:editsysteminstructions' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:managesysteminstructions',
    ],
    'local/coursegen:deletesysteminstructions' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:managesysteminstructions',
    ],

    // Image generation settings. Carved out of manageimagegeneration, which is now only
    // the source their permissions are copied from when the plugin is upgraded.
    'local/coursegen:viewimagegenerationsettings' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:manageimagegeneration',
    ],
    'local/coursegen:editimagegenerationsettings' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:manageimagegeneration',
    ],

    // Creating a course with AI: one capability per mode, plus the syllabus and the images. Carved out of
    // createcoursewithai, which is now only the source their permissions are copied from when the plugin is upgraded.
    'local/coursegen:createfreecoursewithai' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:createcoursewithai',
    ],
    'local/coursegen:createtemplatecoursewithai' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:createcoursewithai',
    ],
    'local/coursegen:uploadcoursesyllabus' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:createcoursewithai',
    ],
    'local/coursegen:generatecourseimages' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:createcoursewithai',
    ],

    // Images of an activity generated with AI. Carved out of createactivitywithai, which is still the
    // capability for generating an activity.
    'local/coursegen:generateactivityimages' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'local/coursegen:createactivitywithai',
    ],
];
