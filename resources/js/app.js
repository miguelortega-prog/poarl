import './bootstrap';
import '../css/app.css';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import './plugins/trap';

Alpine.plugin(collapse);

window.Alpine = Alpine;

window.deferLoadingAlpine = (callback) => {
    document.addEventListener('livewire:init', callback, { once: true });
};

Alpine.start();
