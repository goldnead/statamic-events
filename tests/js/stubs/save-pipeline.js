/**
 * Core's Save-Pipeline, aufgezeichnet statt ausgefuehrt.
 *
 * Die echte schickt `container.visibleValues` per axios an die URL des
 * `Request`-Schritts und uebernimmt Fehler-Toast, 422-Feldfehler und den
 * Dirty-Zustand. Was diese Seiten davon halten muessen, ist die Verdrahtung:
 * welche URL, welche Methode, und dass ueberhaupt die Pipeline benutzt wird
 * statt eines eigenen Aufrufs, der an all dem vorbeilaeuft.
 */
export const requests = {
    calls: [],
    reset() {
        this.calls = [];
    },
};

export class Request {
    constructor(url, method) {
        this.url = url;
        this.method = method;
    }
}

export class Pipeline {
    provide(provided) {
        this.provided = provided;

        return this;
    }

    through(steps) {
        steps.forEach((step) => requests.calls.push({ url: step.url, method: step.method }));

        return Promise.resolve({ data: { saved: true } });
    }
}

export class BeforeSaveHooks {}
export class AfterSaveHooks {}
export class PipelineStopped extends Error {}
