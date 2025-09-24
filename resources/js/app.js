import './bootstrap';
import '../css/app.css';

import collapse from '@alpinejs/collapse';
import './plugins/trap';

window.deferLoadingAlpine = (callback) => {
    document.addEventListener('livewire:init', callback, { once: true });
};

document.addEventListener('alpine:init', () => {
    if (! window.Alpine) {
        return;
    }

    window.Alpine.plugin(collapse);
});
