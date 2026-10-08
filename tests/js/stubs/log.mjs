// A recorder in place of the log of the feed: it keeps the turns it was asked to draw and puts each one at the end of
// the fake feed of the page, as the real log does, so a repeat of the last step can be recognised.
import {makeStep} from '../support/fake-feed.mjs';

export const drawn = [];

export const reset = () => {
    drawn.length = 0;
};

export const createLog = ({container}) => ({
    add: ({actor, kind, message, markdown}) => {
        drawn.push({actor, kind, message, markdown});
        const turn = kind === 'user' ? 'cg-log-entry cg-log-entry--user cg-log-entry--turn-user'
            : 'cg-log-entry cg-log-entry--ai cg-log-entry--turn-ai';
        container.appendChild(makeStep(message, turn));
    },
    clear: () => undefined,
});
