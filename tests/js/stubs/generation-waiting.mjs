// A recorder in place of the waiting line and the paused state: it keeps what the event handlers ask the page to show.
export const shown = [];

export const reset = () => {
    shown.length = 0;
};

export const showWaiting = (seconds) => shown.push(['wait', seconds]);
export const clearWaiting = () => shown.push(['clear']);
export const setPaused = (paused) => shown.push(['paused', paused]);
