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
 * Image suggestion card builder for the detailed plan UI.
 *
 * @module     local_coursegen/local/courseai/detailed/images
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createActionControl} from './controls';
import {iaSparklesSvg, getCoreIconUrl} from './icons';

/**
 * Build the images-detail container for a planned activity.
 *
 * @param {Object}   ctx
 * @param {Object}   options
 * @param {Array}    options.imageSuggestions
 * @returns {HTMLElement}
 */
export const createImagesDetail = (ctx, {imageSuggestions}) => {
    const {texts, createTextPanel, runPlanAction, log, focusChange, markRemoving} = ctx;

    // Images are curated by discard/replan only — there is no per-image
    // selection checkbox. Discarded suggestions stay in the server tree
    // marked deleted; never render them as active cards.
    const activeImages = (imageSuggestions || []).filter((item) => !item.deleted);

    const container = document.createElement('div');
    container.className = 'dp-images-container';

    const header = document.createElement('div');
    header.className = 'dp-images-header';

    const headerLabel = document.createElement('div');
    headerLabel.className = 'dp-images-header-label';

    const headerIcon = document.createElement('span');
    headerIcon.className = 'dp-images-header-icon';
    headerIcon.innerHTML = [
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"',
        'stroke="currentColor" stroke-width="2" stroke-linecap="round"',
        'stroke-linejoin="round" aria-hidden="true">',
        '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>',
        '<circle cx="8.5" cy="8.5" r="1.5"/>',
        '<polyline points="21 15 16 10 5 21"/></svg>'
    ].join(' ');

    const headerTitle = document.createElement('span');
    headerTitle.className = 'dp-images-header-title';
    headerTitle.textContent = texts.courseai_images_suggested_label.toUpperCase();

    const headerCount = document.createElement('span');
    headerCount.className = 'dp-images-header-count';
    headerCount.textContent = `${activeImages.length} ${
        activeImages.length === 1
            ? texts.courseai_image_count_one.replace('{count}', '').trim()
            : texts.courseai_image_count_many.replace('{count}', '').trim()
    }`;

    headerLabel.appendChild(headerIcon);
    headerLabel.appendChild(headerTitle);
    header.appendChild(headerLabel);
    header.appendChild(headerCount);

    const list = document.createElement('div');
    list.className = 'dp-image-list';

    activeImages.forEach((item) => {
        const imageWrap = document.createElement('div');
        imageWrap.className = 'dp-image-wrap';

        const imageCard = document.createElement('div');
        imageCard.className = 'dp-image-card';

        const placement = document.createElement('p');
        placement.className = 'dp-image-placement';
        placement.textContent = item.placement || texts.courseai_activity_default;

        const description = document.createElement('p');
        description.className = 'dp-image-description';
        description.textContent = item.description || '';

        const body = document.createElement('div');
        body.className = 'dp-image-body';

        const imageActions = document.createElement('div');
        imageActions.className = 'dp-item-actions dp-item-actions--image';

        let iaControl = null;
        let discardControl = null;

        const imagePanelApi = createTextPanel({
            texts,
            onSubmit: async(value) => {
                if (!item.id) {
                    return;
                }
                focusChange(imageWrap, 'info');
                imageWrap.classList.add('dp-item-regenerating');
                iaControl.classList.add('dp-action-btn--disabled');
                log({
                    actor: 'user',
                    kind: 'info',
                    message: texts.courseai_log_image_regenerated || 'You regenerated an image suggestion',
                });
                try {
                    const pendingAction = {
                        action: 'replan_image',
                        target_ids: [item.id],
                        instruction: value,
                    };
                    await runPlanAction(pendingAction);
                } catch (e) {
                    imageWrap.classList.remove('dp-item-regenerating');
                    iaControl.classList.remove('dp-action-btn--disabled');
                }
            },
        });

        iaControl = createActionControl({
            variant: 'ia',
            iconSvg: iaSparklesSvg,
            label: texts.courseai_btn_adjust,
            onActivate: () => imagePanelApi.open(),
            disabled: true,
        });

        discardControl = createActionControl({
            variant: 'delete',
            iconUrl: getCoreIconUrl('t/delete'),
            label: texts.courseai_btn_discard,
            onActivate: async() => {
                if (!item.id) {
                    return;
                }
                log({
                    actor: 'user',
                    kind: 'danger',
                    message: texts.courseai_log_image_discarded || 'You discarded an image suggestion',
                });
                imageWrap.classList.add('dp-item-regenerating');
                discardControl.classList.add('dp-action-btn--disabled');
                await markRemoving(imageWrap);
                try {
                    const pendingAction = {
                        action: 'discard_image',
                        target_ids: [item.id],
                    };
                    await runPlanAction(pendingAction);
                } catch (e) {
                    imageWrap.classList.remove('dp-item-regenerating');
                    imageWrap.classList.remove('cg-removing');
                    discardControl.classList.remove('dp-action-btn--disabled');
                }
            },
            disabled: true,
        });

        imageActions.appendChild(iaControl);
        imageActions.appendChild(discardControl);

        body.appendChild(placement);
        body.appendChild(imageActions);
        body.appendChild(description);

        imageCard.appendChild(body);
        imageWrap.appendChild(imageCard);
        imageWrap.appendChild(imagePanelApi.panel);
        list.appendChild(imageWrap);
    });

    container.appendChild(header);
    container.appendChild(list);

    return container;
};
