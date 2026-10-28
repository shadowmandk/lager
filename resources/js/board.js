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
        const drop = dragging ? this.dropKeyAt(e.clientX, e.clientY) : null;
        const overSlot = dragging ? this.elementAt(e.clientX, e.clientY)?.closest('[data-slot]') : null;

        this.cancel();

        if (!dragging) {
            // Et kort tryk på et greb fungerer som "vælg".
            if (press && press.el.classList.contains('chip-grip')) this.$wire.pick(press.id);
            return;
        }

        this.justDragged = true;
        setTimeout(() => (this.justDragged = false), 400);

        if (drop) {
            this.$wire.prepare(dragging.id, dragging.from, drop);
        } else if (overSlot) {
            this.$wire.illegalDrop();
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

        this.dragging = { id: press.id, kind, from, targets, ghost, ctr };
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

    elementAt(x, y) {
        return document.elementFromPoint(x, y);
    },

    dropKeyAt(x, y) {
        const hit = this.elementAt(x, y);
        if (!hit || !this.dragging) return null;

        const slot = hit.closest('[data-slot]');
        if (slot && this.dragging.targets.has(slot.dataset.slot)) return slot.dataset.slot;

        if (hit.closest('[data-drop="deliver"]') && this.dragging.targets.has('deliver')) return 'deliver';

        return null;
    },

    hover(x, y) {
        const key = this.dropKeyAt(x, y);
        this.$root.querySelectorAll('.is-hover').forEach((n) => n.classList.remove('is-hover'));
        if (!key) return;

        if (key === 'deliver') {
            this.elementAt(x, y)?.closest('[data-drop="deliver"]')?.classList.add('is-hover');
        } else {
            this.$root.querySelector(`[data-slot="${key}"]`)?.classList.add('is-hover');
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
            this.$root.querySelectorAll('.is-hover').forEach((n) => n.classList.remove('is-hover'));
        }

        this.press = null;
        this.dragging = null;

        window.removeEventListener('pointermove', this.onMove);
        window.removeEventListener('pointerup', this.onUp);
        window.removeEventListener('pointercancel', this.onCancel);
    },
});
