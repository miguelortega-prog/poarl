import './bootstrap';
import '../css/app.css';

import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(collapse);
    window.Alpine.plugin(focus);
});
