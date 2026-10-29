import board from './board.js';
import startLock from './lock.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('board', board);
});

// Udløbet session (fx en tablet, der har ligget længe): hent siden igen i stedet for Livewires engelske boks.
// Er enheden låst imens, sender serveren den videre til låseskærmen.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419) {
                preventDefault();
                window.location.reload();
            }
        });
    });
});

const lock = document.body?.dataset.lock;
if (lock) startLock(JSON.parse(lock));
