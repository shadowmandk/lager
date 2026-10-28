import board from './board.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('board', board);
});
