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
 * The syllabus chip of the chat input, shown again on a reloaded page.
 *
 * @module     local_coursegen/courseai/bootstrap/resume-syllabus-chip
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Show one syllabus chip and the row that holds it.
 *
 * @param {Object} root Where the elements are looked up by id, normally the document.
 * @param {{chip: string, name: string, row: string}} ids Ids of the chip, of its name and of its row.
 * @param {string} filename Name of the uploaded syllabus.
 * @returns {void}
 */
const showChip = (root, ids, filename) => {
    const chip = root.getElementById(ids.chip);
    const chipName = root.getElementById(ids.name);
    const chipsRow = root.getElementById(ids.row);
    if (chipName) {
        chipName.textContent = filename;
    }
    if (chip) {
        chip.classList.remove('hidden');
    }
    if (chipsRow) {
        chipsRow.style.display = 'flex';
    }
};

/**
 * Show the chips of the uploaded syllabus, as the file picker shows them after an upload.
 *
 * The chips of the start form are the ones the chat input copies its own from, so both pairs are shown.
 *
 * @param {Object} root Where the chip elements are looked up by id, normally the document.
 * @param {string} filename Name of the uploaded syllabus; empty when the session has none.
 * @returns {void}
 */
export const showSyllabusChip = (root, filename) => {
    const name = String(filename || '');
    if (name === '') {
        return;
    }

    showChip(root, {chip: 'chipSyllabus', name: 'chipSyllabusName', row: 'chipsRow'}, name);
    showChip(root, {chip: 'compactChipSyllabus', name: 'compactChipSyllabusName', row: 'compactChipsRow'}, name);
};
