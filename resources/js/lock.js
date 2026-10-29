/**
 * Låser enheden igen, når skærmen slukkes eller låses, og efter et stykke tid uden aktivitet.
 *
 * Browseren kan ikke se forskel på en slukket skærm og et skift til en anden app eller fane;
 * begge dele gør siden skjult. Mens siden er skjult, gemmes indholdet, og når den vises igen, låses der.
 * Serveren låser også selv efter samme tid uden aktivitet (DeviceLock), så det her er kun det synlige lag.
 */
export default function startLock({ lockUrl, unlockUrl, idleMinutes }) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let locked = false;
    let hidden = false;
    let timer;

    const lock = () => {
        if (locked) return;
        locked = true;
        document.documentElement.classList.add('is-locking');

        fetch(lockUrl, {
            method: 'POST',
            keepalive: true,
            headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
        }).catch(() => {}).finally(() => location.replace(unlockUrl));
    };

    const reset = () => {
        clearTimeout(timer);
        timer = setTimeout(lock, idleMinutes * 60 * 1000);
    };

    ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach((type) =>
        window.addEventListener(type, reset, { passive: true, capture: true }));
    reset();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            hidden = true;
            document.documentElement.classList.add('is-locking');
            return;
        }

        if (hidden) lock();
    });

    // Vises siden igen fra browserens tilbage-cache, var den skjult af en navigation, ikke af en låst skærm.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) location.reload();
    });
}
