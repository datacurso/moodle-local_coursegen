// The language strings come from Moodle in the browser; here a string is its key and its arguments.
export const getString = async(key, component, args) => `${component}:${key}:${args}`;
export const getStrings = async(requests) => requests.map((request) => `${request.component}:${request.key}`);
