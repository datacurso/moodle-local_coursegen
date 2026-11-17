// Web service calls of the page: a test says what each method answers, and reads what was asked.
export const calls = [];
export const handlers = {};

export const call = (requests) => requests.map((request) => {
    calls.push({methodname: request.methodname, args: request.args});
    const handler = handlers[request.methodname];
    if (!handler) {
        return Promise.resolve({});
    }
    try {
        return Promise.resolve(handler(request.args));
    } catch (error) {
        return Promise.reject(error);
    }
});

export const reset = () => {
    calls.length = 0;
    Object.keys(handlers).forEach((key) => delete handlers[key]);
};
