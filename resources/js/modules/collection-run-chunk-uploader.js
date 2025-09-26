const CHUNK_SIZE_BYTES = 5 * 1024 * 1024;

document.addEventListener('livewire:init', () => {
    const { Livewire } = window;

    if (Livewire && typeof Livewire.setUploadChunkSize === 'function') {
        Livewire.setUploadChunkSize(CHUNK_SIZE_BYTES);
    }
});

