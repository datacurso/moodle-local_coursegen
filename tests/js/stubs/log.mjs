// The log of the left column, reduced to what a test reads: the turns added to it.
export const turns = [];
export const createLog = () => ({
    add: (turn) => turns.push(turn),
    clear: () => {
        turns.length = 0;
    },
});
