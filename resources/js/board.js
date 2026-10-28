/**
 * Træk og slip på tavlen, polling efter ændringer og visning af tabt forbindelse.
 *
 * Træk: hold fingeren på en container med grøn kant (eller grebet på en liste-brik) et øjeblik,
 * så løfter den sig. De lovlige pladser lyser grønt. Slip på en grøn plads åbner bekræftelsesboksen.
 * Med mus starter trækket, så snart man bevæger den.
 *
 * Serveren afgør altid, om flytningen er lovlig. De grønne pladser her er kun en hjælp.
 */

const HOLD_MS = 220;
const MOVE_TOLERANCE = 10;
const POLL_MS = 3000;

export default ({ pingUrl }) => ({
    online: navigator.onLine,
    dragging: null,
    press: null,
    justDragged: false,

    init() {
        this.onMove = (e) => this.move(e);
        this.onUp = (e) => this.up(e);
        this.onCancel = () => this.cancel();

        window.addEventListener('online', () => this.ping());
        window.addEventListener('offline', () => (this.online = false));

        this.timer = setInterval(() => this.ping(), POLL_MS);
    },

    destroy() {
        clearInterval(this.timer);
        this.cancel();
    },

    async ping() {
        if (document.hidden) return;

        try {
            const res = await fetch(pingUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            const { version } = await res.json();
            this.online = true;

            if (version !== this.$root.dataset.version && !this.busy()) {
                this.$wire.$refresh();
            }
        } catch {
            this.online = false;
        }
    },

    /** Opdatér ikke tavlen midt i et træk, en bekræftelse eller mens man skriver. */
    busy() {
        if (this.dragging || this.press) return true;
        if (this.$root.querySelector('[data-confirm-open]')) return true;
        const active = document.activeElement;
        return !!active && this.$root.contains(active) && ['INPUT', 'SELECT', 'TEXTAREA'].includes(active.tagName) && active.type !== 'search';
    },

    down(e) {
        if (e.button !== 0 || this.dragging) return;
        const el = e.target.closest('[data-drag-id]');
        if (!el) return;

        this.press = { el, id: Number(el.dataset.dragId), x: e.clientX, y: e.clientY, pointerType: e.pointerType };

        if (e.pointerType !== 'mouse') {
            this.press.timer = setTimeout(() => this.lift(e.clientX, e.clientY), HOLD_MS);
        }

        window.addEventListener('pointermove', this.onMove, { passive: false });
        window.addEventListener('pointerup', this.onUp);
        window.addEventListener('pointercancel', this.onCancel);
    },

    move(e) {
        if (this.press && !this.dragging) {
            const dist = Math.hypot(e.clientX - this.press.x, e.clientY - this.press.y);

            if (this.press.pointerType === 'mouse' && dist > 6) {
                this.lift(e.clientX, e.clientY);
            } else if (dist > MOVE_TOLERANCE) {
                // Fingeren flyttede sig før den var løftet: det var ikke et træk.
                this.cancel();
            }
            return;
        }

        if (!this.dragging) return;
        e.preventDefault();

        this.placeGhost(e.clientX, e.clientY);
        this.hover(e.clientX, e.clientY);
        this.autoScroll(e.clientY);
    },

    up(e) {
        const dragging = this.dragging;
        const press = this.press;
        const drop = dragging ? this.resolveDrop(e.clientX, e.clientY) : null;

        this.cancel();

        if (!dragging) {
            // Et kort tryk på et greb fungerer som "vælg".
            if (press && press.el.classList.contains('chip-grip')) this.$wire.pick(press.id);
            return;
        }

        this.justDragged = true;
        setTimeout(() => (this.justDragged = false), 400);

        if (drop.key) {
            this.$wire.prepare(dragging.id, dragging.from, drop.key);
        } else if (drop.column && drop.column !== dragging.ownColumn) {
            // Sluppet på en stak uden grøn plads. Slip tilbage på egen stak er bare en fortrydelse.
            this.$wire.illegalDrop(dragging.id, drop.column);
        }
    },

    swallowClick(e) {
        if (this.justDragged) {
            e.stopPropagation();
            e.preventDefault();
            this.justDragged = false;
        }
    },

    lift(x, y) {
        const press = this.press;
        if (!press || this.dragging) return;
        clearTimeout(press.timer);

        const el = press.el;
        const kind = el.dataset.dragKind;
        const from = el.dataset.from || null;
        const targets = this.targetsFor(kind, from);

        this.$root.querySelectorAll('[data-slot]').forEach((s) => s.classList.toggle('is-target', targets.has(s.dataset.slot)));
        this.$root.querySelectorAll('[data-drop="deliver"]').forEach((d) => d.classList.toggle('is-target', targets.has('deliver')));

        const ctr = el.closest('.ctr');
        ctr?.classList.add('ctr-lifted');

        const ghost = document.createElement('div');
        ghost.className = 'drag-ghost';
        ghost.style.background = el.dataset.dragColor || '#475569';
        ghost.innerHTML = '<span class="num"></span><span class="lbl"></span>';
        ghost.querySelector('.num').textContent = el.dataset.dragNumber || '';
        ghost.querySelector('.lbl').textContent = el.dataset.dragLabel || '';
        document.body.appendChild(ghost);

        // Hver stak har højst én lovlig plads. Slip et vilkårligt sted på stakken lander der.
        const byColumn = new Map();
        targets.forEach((k) => {
            if (k !== 'deliver') byColumn.set(k.split(':').slice(0, 2).join(':'), k);
        });
        const ownColumn = from ? from.split(':').slice(0, 2).join(':') : null;

        this.dragging = { id: press.id, kind, from, targets, byColumn, ownColumn, ghost, ctr };
        this.placeGhost(x, y);
        navigator.vibrate?.(25);
    },

    targetsFor(kind, from) {
        let rows = {};
        try {
            rows = JSON.parse(this.$root.querySelector('[data-drag-rows]').dataset.dragRows || '{}');
        } catch {}

        const ownRow = from ? from.split(':')[0] : null;
        const targets = new Set();

        for (const [rowId, data] of Object.entries(rows)) {
            const list = rowId === ownRow ? data.lift : data.base;
            list.forEach((k) => targets.add(k));
        }

        if (from) targets.delete(from);
        if (kind === 'stored') targets.add('deliver');

        return targets;
    },

    placeGhost(x, y) {
        if (this.dragging) this.dragging.ghost.style.transform = `translate(${x - 56}px, ${y - 70}px) rotate(-3deg)`;
    },


    /**
     * Hvor lander et slip? Ser gennem alt under fingeren, så den svævende udleveringsboks
     * ikke skjuler en grøn plads bag sig.
     *
     * @returns {{ key: string|null, column: string|null, deliverEl: Element|null }}
     */
    resolveDrop(x, y) {
        const none = { key: null, column: null, deliverEl: null };
        const d = this.dragging;
        if (!d) return none;

        const stack = document.elementsFromPoint(x, y);
        if (!stack.length || stack[0].closest('header')) return none;

        let column = null;
        let deliverEl = null;

        for (const el of stack) {
            column ??= el.closest('[data-col]')?.dataset.col ?? null;
            deliverEl ??= el.closest('[data-drop="deliver"]');
        }

        if (column && d.byColumn.has(column)) return { key: d.byColumn.get(column), column, deliverEl };
        if (deliverEl && d.targets.has('deliver')) return { key: 'deliver', column, deliverEl };
        if (!column) {
            const near = this.nearestTarget(x, y, 30);
            if (near) return { key: near, column: null, deliverEl };
        }

        return { key: null, column, deliverEl };
    },

    /** Nærmeste grønne plads inden for en lille afstand, til slip i mellemrummet mellem stakke. */
    nearestTarget(x, y, radius) {
        let best = null;
        let bestDist = radius;

        this.$root.querySelectorAll('[data-slot]').forEach((s) => {
            if (!this.dragging.targets.has(s.dataset.slot)) return;
            const b = s.getBoundingClientRect();
            const dx = Math.max(b.left - x, 0, x - b.right);
            const dy = Math.max(b.top - y, 0, y - b.bottom);
            const dist = Math.hypot(dx, dy);
            if (dist < bestDist) {
                best = s.dataset.slot;
                bestDist = dist;
            }
        });

        return best;
    },

    hover(x, y) {
        const { key, deliverEl } = this.resolveDrop(x, y);
        this.$root.querySelectorAll('.is-hover, .is-faded').forEach((n) => n.classList.remove('is-hover', 'is-faded'));
        if (!key) return;

        if (key === 'deliver') {
            deliverEl?.classList.add('is-hover');
        } else {
            this.$root.querySelector(`[data-slot="${key}"]`)?.classList.add('is-hover');
            // Ligger udleveringsboksen oven på pladsen, gøres den gennemsigtig, så pladsen kan ses.
            if (deliverEl?.classList.contains('drop-deliver-floating')) deliverEl.classList.add('is-faded');
        }
    },

    autoScroll(y) {
        const edge = 80;
        if (y < edge) window.scrollBy(0, -14);
        else if (y > window.innerHeight - edge) window.scrollBy(0, 14);
    },

    cancel() {
        if (this.press) clearTimeout(this.press.timer);

        if (this.dragging) {
            this.dragging.ghost.remove();
            this.dragging.ctr?.classList.remove('ctr-lifted');
            this.$root.querySelectorAll('[data-slot].is-target, [data-drop="deliver"].is-target').forEach((n) => {
                // Pladser, som serveren har markeret i "vælg"-tilstand, beholder markeringen.
                if (!n.hasAttribute('wire:click')) n.classList.remove('is-target');
            });
            this.$root.querySelectorAll('.is-hover, .is-faded').forEach((n) => n.classList.remove('is-hover', 'is-faded'));
        }

        this.press = null;
        this.dragging = null;

        window.removeEventListener('pointermove', this.onMove);
        window.removeEventListener('pointerup', this.onUp);
        window.removeEventListener('pointercancel', this.onCancel);
    },
});
