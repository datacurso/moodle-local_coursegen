// A recorder in place of the checklist: it keeps what the event handlers ask it to draw.
export const calls = [];

export const reset = () => {
    calls.length = 0;
};

export const addActivity = (data) => calls.push(['add', data.uid]);
export const closeActivity = (uid, progress, unchanged = false) => calls.push(['close', uid, unchanged, progress.done]);
export const openChecklist = (progress) => calls.push(['open', progress.total]);
export const settleChecklist = (progress) => calls.push(['settle', progress.total]);
export const resetChecklist = () => calls.push(['reset']);
