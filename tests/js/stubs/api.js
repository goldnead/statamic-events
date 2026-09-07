/**
 * The Control Panel's extension registry. `cp.js` registers its two Inertia
 * pages through it; the stub records so the registration itself can be asserted
 * — a page that is never registered renders as a blank Control Panel screen with
 * no error anywhere.
 */
/**
 * Die Dirty-Registry des Control Panels. Der Datums-Stack fragt sie, bevor er
 * schliesst — ohne sie ginge eine angefangene Eingabe still verloren.
 */
export const dirty = {
    names: new Set(),
    add(name) {
        this.names.add(name);
    },
    remove(name) {
        this.names.delete(name);
    },
    has(name) {
        return this.names.has(name);
    },
    reset() {
        this.names.clear();
    },
};

export const inertia = {
    pages: {},
    register(name, component) {
        this.pages[name] = component;
    },
    get(name) {
        return this.pages[name];
    },
    reset() {
        this.pages = {};
    },
};
