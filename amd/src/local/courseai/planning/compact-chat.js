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
 * Compact-chat visibility and control-state helper.
 *
 * @module     local_coursegen/local/courseai/planning/compact-chat
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Set compact chat visibility and control state.
 *
 * @param {Object} deps - Dependencies including state, elements, texts
 * @param {string} mode - 'hidden' | 'disabled' | 'enabled' | 'reset'
 */
export const setCompactChatState = (deps, mode) => {
    const {
        state,
        elements,
        texts,
    } = deps;

    const {
        compactChatCard,
        compactPromptInput,
        compactChipsRow,
        compactToolbarLeft,
        btnCompactRegenerate,
        compactLangSelect,
        btnCompactWithImages,
        btnCompactSyllabus,
        btnCompactDirectrices,
    } = elements;

    if (!compactChatCard) {
        return;
    }

    const sparkleIcon = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" ' +
        'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
        'stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 ' +
        '9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 ' +
        '15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 ' +
        '6.135a.5.5 0 0 1-.962 0z"/></svg>';

    switch (mode) {
        case 'hidden':
            compactChatCard.style.display = 'none';
            compactChatCard.classList.remove('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.remove('compact-controls--disabled');
                compactPromptInput.disabled = false;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.remove('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.remove('compact-controls--disabled');
            }
            if (compactLangSelect) {
                compactLangSelect.disabled = false;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = false;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = false;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = false;
            }
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = false;
            }
            break;

        case 'disabled':
            compactChatCard.style.display = 'block';
            compactChatCard.classList.add('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.add('compact-controls--disabled');
                compactPromptInput.disabled = true;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.add('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.add('compact-controls--disabled');
            }
            // Disable form controls and toolbar buttons — keyboard + mouse
            if (compactLangSelect) {
                compactLangSelect.disabled = true;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = true;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = true;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = true;
            }
            // Disable Regenerar — actions.js re-enables it and switches label to Pausar
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = true;
            }
            if (state) {
                state.isStreaming = true;
            }
            break;

        case 'enabled':
            compactChatCard.style.display = 'block';
            compactChatCard.classList.remove('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.remove('compact-controls--disabled');
                compactPromptInput.disabled = false;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.remove('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.remove('compact-controls--disabled');
            }
            if (compactLangSelect) {
                compactLangSelect.disabled = false;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = false;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = false;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = false;
            }
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = false;
                if (texts?.courseai_btn_regenerate) {
                    btnCompactRegenerate.innerHTML = `${sparkleIcon} ${texts.courseai_btn_regenerate}`;
                    btnCompactRegenerate.setAttribute('aria-label', texts.courseai_btn_regenerate);
                    btnCompactRegenerate.setAttribute('title', texts.courseai_btn_regenerate);
                }
            }
            if (state) {
                state.isStreaming = false;
            }
            break;

        case 'reset':
        default:
            compactChatCard.style.display = 'none';
            compactChatCard.classList.remove('compact-chat-card--disabled');
            if (compactPromptInput) {
                compactPromptInput.classList.remove('compact-controls--disabled');
                compactPromptInput.disabled = false;
            }
            if (compactChipsRow) {
                compactChipsRow.classList.remove('compact-controls--disabled');
            }
            if (compactToolbarLeft) {
                compactToolbarLeft.classList.remove('compact-controls--disabled');
            }
            if (compactLangSelect) {
                compactLangSelect.disabled = false;
            }
            if (btnCompactWithImages) {
                btnCompactWithImages.disabled = false;
            }
            if (btnCompactSyllabus) {
                btnCompactSyllabus.disabled = false;
            }
            if (btnCompactDirectrices) {
                btnCompactDirectrices.disabled = false;
            }
            if (btnCompactRegenerate) {
                btnCompactRegenerate.disabled = false;
                if (texts?.courseai_btn_regenerate) {
                    btnCompactRegenerate.innerHTML = `${sparkleIcon} ${texts.courseai_btn_regenerate}`;
                    btnCompactRegenerate.setAttribute('aria-label', texts.courseai_btn_regenerate);
                    btnCompactRegenerate.setAttribute('title', texts.courseai_btn_regenerate);
                }
            }
            if (state) {
                state.isStreaming = false;
            }
            break;
    }
};
