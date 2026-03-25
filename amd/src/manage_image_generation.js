import notification from 'core/notification';
import {get_string as getString} from 'core/str';
import {prefetchStrings} from 'core/prefetch';
import {watchForm, resetFormDirtyState, markFormAsDirty} from 'core_form/changechecker';

import {imageGenerationRegions} from 'local_coursegen/selectors';
import {saveImageGenerationSettings} from 'local_coursegen/repository/image_generation';

export const init = () => {
    const form = document.querySelector(imageGenerationRegions.form);
    if (!form) {
        return;
    }

    // Clean up the temporary 'saved' flag from the URL so the settings page URL stays clean.
    const currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.has('saved')) {
        currentUrl.searchParams.delete('saved');
        window.history.replaceState(null, '', currentUrl.toString());
    }

    watchForm(form);

    prefetchStrings('local_coursegen', [
        'settings_error',
    ]);

    const modeCards = form.querySelectorAll(imageGenerationRegions.modeCard);
    const manualSettings = form.querySelector(imageGenerationRegions.manualSettings);
    const inputMode = form.querySelector(imageGenerationRegions.inputMode);
    const toggleSwitches = form.querySelectorAll(imageGenerationRegions.toggleActivity);
    const submitBtn = form.querySelector(imageGenerationRegions.saveButton);
    if (!submitBtn) {
        return;
    }

    // Handle mode card clicks.
    modeCards.forEach((card) => {
        card.addEventListener('click', (e) => {
            const selectedMode = e.currentTarget.dataset.mode;

            // Reset card styles and radio state.
            modeCards.forEach((currentCard) => {
                currentCard.classList.remove('border-primary', 'border-danger', 'bg-light');
                const radio = currentCard.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = false;
                }
            });


            // Activate selected card.
            if (selectedMode === 'disabled') {
                e.currentTarget.classList.add('border-danger', 'bg-light');
            } else {
                e.currentTarget.classList.add('border-primary', 'bg-light');
            }

            const activeRadio = e.currentTarget.querySelector('input[type="radio"]');
            if (activeRadio) {
                activeRadio.checked = true;
            }

            // Keep hidden input in sync.
            if (inputMode && inputMode.value !== selectedMode) {
                inputMode.value = selectedMode;
                markFormAsDirty(form);
            }

            // Toggle manual settings.
            if (manualSettings) {
                if (selectedMode === 'manual') {
                    manualSettings.classList.remove('d-none');
                } else {
                    manualSettings.classList.add('d-none');
                }
            }
        });
    });

    // Handle activity switches.
    toggleSwitches.forEach((toggle) => {
        toggle.addEventListener('change', (e) => {
            const targetId = e.target.dataset.target;
            const contentDiv = form.querySelector(
                `${imageGenerationRegions.activityContent}[data-content="${targetId}"]`
            );

            if (contentDiv) {
                if (e.target.checked) {
                    contentDiv.classList.add('show');
                } else {
                    contentDiv.classList.remove('show');
                }
            }
        });
    });

    // Handle per-part toggles (enable/disable max images input).
    const partToggles = form.querySelectorAll(imageGenerationRegions.activityPartToggle);
    partToggles.forEach((partToggle) => {
        const partId = partToggle.dataset.partId;
        if (!partId) {
            return;
        }

        // The part checkbox lives inside the collapsed content row, not in the header row.
        // We locate the nearest collapse container and search for the matching maximages input there.
        const container = partToggle.closest(imageGenerationRegions.activityContent) || partToggle.closest('.collapse');
        if (!container) {
            return;
        }

        const maxInput = container.querySelector(
            `${imageGenerationRegions.activityPartMaxImages}[data-part-id="${partId}"]`
        );
        if (!maxInput) {
            return;
        }

        // Initialise disabled state on load.
        maxInput.disabled = !partToggle.checked;

        partToggle.addEventListener('change', () => {
            const isChecked = partToggle.checked;
            maxInput.disabled = !isChecked;

            if (isChecked) {
                const current = Number(maxInput.value || 0);
                if (Number.isNaN(current) || current <= 0) {
                    maxInput.value = '1';
                }
            }

            markFormAsDirty(form);
        });
    });

    // Submit handler.
    form.addEventListener('submit', async(e) => {
        e.preventDefault();

        submitBtn.disabled = true;

        try {
            const formData = new FormData(form);

            const activities = [];
            const activityRows = form.querySelectorAll(imageGenerationRegions.activityRow);
            activityRows.forEach((row) => {
                const activityId = row.dataset.activityId;
                if (!activityId) {
                    return;
                }

                const toggle = row.querySelector(imageGenerationRegions.toggleActivity);
                const enabled = toggle && toggle.checked ? 1 : 0;

                // Per-part controls live in the collapse content row for this activity.
                const contentContainer = form.querySelector(
                    `${imageGenerationRegions.activityContent}[data-content="content-${activityId}"]`
                );
                if (!contentContainer) {
                    activities.push({id: activityId, enabled, parts: []});
                    return;
                }

                const parts = [];
                const partToggles = contentContainer.querySelectorAll(imageGenerationRegions.activityPartToggle);

                partToggles.forEach((partToggle) => {
                    const partId = partToggle.dataset.partId;
                    if (!partId) {
                        return;
                    }

                    const maxInput = contentContainer.querySelector(
                        `${imageGenerationRegions.activityPartMaxImages}[data-part-id="${partId}"]`
                    );

                    const maximages = parseInt(maxInput.value, 10) || 0;

                    parts.push({
                        id: partId,
                        enabled: partToggle.checked ? 1 : 0,
                        maximages,
                    });
                });

                activities.push({id: activityId, enabled, parts});
            });

            const payload = {
                overridecourse: formData.get('overridecourse') ? 1 : 0,
                overrideactivity: formData.get('overrideactivity') ? 1 : 0,
                generationmode: String(formData.get('generationmode') || ''),
                activities,
            };

            const response = await saveImageGenerationSettings(payload);
            if (response.success) {
                resetFormDirtyState(form);

                // Redirect back to the page with a flag so PHP can show a standard admin notification.
                const url = new URL(window.location.href);
                url.searchParams.set('saved', '1');
                window.location.href = url.toString();
            }
        } catch (error) {
            notification.exception(error);

            const errorStr = await getString('settings_error', 'local_coursegen');
            notification.addNotification({message: errorStr, type: 'error'});

        } finally {
            submitBtn.disabled = false;
        }
    });
};
