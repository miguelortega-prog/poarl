import './bootstrap';
import '../css/app.css';

import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';
import { collectionRunUploader } from './modules/collection-run-chunk-uploader';

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(collapse);
    window.Alpine.plugin(focus);
});

window.collectionRunUploader = collectionRunUploader;
