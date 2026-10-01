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

namespace local_coursegen;

/**
 * Creates users that hold exactly the capabilities a test names.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait capability_user_trait {
    /**
     * Log in a user who has the given capabilities in the system context and no others.
     *
     * @param string[] $capabilities The capabilities to allow.
     * @return \stdClass The user.
     */
    protected function login_user_with(array $capabilities): \stdClass {
        $generator = $this->getDataGenerator();
        $systemcontext = \context_system::instance();
        $roleid = $generator->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
        }
        $user = $generator->create_user();
        $generator->role_assign($roleid, $user->id, $systemcontext->id);
        $this->setUser($user);
        return $user;
    }

    /**
     * Every capability the plugin defines, except the given ones.
     *
     * @param string[] $except The capabilities to leave out.
     * @return string[]
     */
    protected function all_capabilities_except(array $except): array {
        global $CFG;

        $capabilities = [];
        include($CFG->dirroot . '/local/coursegen/db/access.php');
        $names = array_keys($capabilities);
        $remaining = array_diff($names, $except);
        return array_values($remaining);
    }

    /**
     * Log in a user who has every capability of the plugin except the given one.
     *
     * @param string $capability The capability to leave out.
     * @param string[] $extra Other capabilities to allow, such as core ones.
     */
    protected function login_without(string $capability, array $extra = []): void {
        $others = $this->all_capabilities_except([$capability]);
        $granted = array_merge($extra, $others);
        $this->login_user_with($granted);
    }
}
