// A RelaySource that a test drives by hand: it records its listeners, and the test says what the stream does.
export default class RelaySource {
    static instances = [];

    constructor(url) {
        this.url = url;
        this.listeners = {};
        this.onerror = null;
        this.closed = false;
        RelaySource.instances.push(this);
    }

    addEventListener(name, listener) {
        (this.listeners[name] = this.listeners[name] || []).push(listener);
    }

    close() {
        this.closed = true;
    }

    emit(name, payload) {
        for (const listener of this.listeners[name] || []) {
            listener({data: payload === undefined ? '' : JSON.stringify(payload)});
        }
    }

    fail() {
        if (this.onerror) {
            this.onerror(new Error('lost'));
        }
    }
}
