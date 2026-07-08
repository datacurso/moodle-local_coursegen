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
 * Repository helpers for image generation settings.
 *
 * @module     local_coursegen/repository/image_generation
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ajax from 'core/ajax';

/**
 * Save image generation settings.
 *
 * @param {{
 *     overridecourse: number,
 *     overrideactivity: number,
 *     generationmode: string,
 *     activities: Array<{id: string, enabled: number, prompt: string}>,
 * }} payload
 * @return {Promise<{success: boolean}>} response
 */
export async function saveImageGenerationSettings(payload) {
    const args = {
        overridecourse: payload.overridecourse,
        overrideactivity: payload.overrideactivity,
        generationmode: payload.generationmode,
        activities: payload.activities || [],
    };

    return ajax.call([
        {
            methodname: 'local_coursegen_manage_image_generation',
            args,
        },
    ])[0];
}
