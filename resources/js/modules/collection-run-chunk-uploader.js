const FIVE_MEGABYTES = 5 * 1024 * 1024;
const DEFAULT_CHUNK_SIZE = FIVE_MEGABYTES;

if (typeof document !== 'undefined') {
    document.addEventListener('livewire:init', () => {
        configureLivewireChunkSize();
    });
}

let livewireChunkSizeConfigured = false;

function configureLivewireChunkSize() {
    if (livewireChunkSizeConfigured || typeof window === 'undefined') {
        return;
    }

    const { Livewire } = window;

    if (Livewire && typeof Livewire.setUploadChunkSize === 'function') {
        Livewire.setUploadChunkSize(DEFAULT_CHUNK_SIZE);
        livewireChunkSizeConfigured = true;
    }
}

class ChunkedUploadSession {
    constructor({ file, uploadUrl, uploadId, chunkSize, csrfToken, onProgress }) {
        if (!(file instanceof File)) {
            throw new TypeError('El archivo a cargar no es válido.');
        }

        if (typeof uploadUrl !== 'string' || uploadUrl.trim() === '') {
            throw new TypeError('El endpoint de carga es inválido.');
        }

        this.file = file;
        this.uploadUrl = uploadUrl;
        this.uploadId = typeof uploadId === 'string' && uploadId !== '' ? uploadId : generateUploadId();
        this.chunkSize = resolveChunkSize(chunkSize);
        this.csrfToken = typeof csrfToken === 'string' && csrfToken !== '' ? csrfToken : null;
        this.onProgress = typeof onProgress === 'function' ? onProgress : null;
        this.totalChunks = Math.max(1, Math.ceil(this.file.size / this.chunkSize));
        this.currentRequest = null;
        this.aborted = false;
    }

    async start() {
        let lastResponse = null;

        for (let index = 0; index < this.totalChunks; index += 1) {
            if (this.aborted) {
                throw new Error('La carga fue cancelada por el usuario.');
            }

            const start = index * this.chunkSize;
            const end = Math.min(this.file.size, start + this.chunkSize);
            const chunk = this.file.slice(start, end);

            const formData = this.buildChunkFormData({
                chunk,
                index,
                totalChunks: this.totalChunks,
            });

            lastResponse = await this.sendChunk({
                formData,
                chunkIndex: index,
                chunkSizeBytes: chunk.size ?? chunk.byteLength ?? end - start,
            });
        }

        return lastResponse;
    }

    abort() {
        this.aborted = true;

        if (this.currentRequest) {
            this.currentRequest.abort();
            this.currentRequest = null;
        }
    }

    buildChunkFormData({ chunk, index, totalChunks }) {
        const formData = new FormData();
        formData.append('upload_id', this.uploadId);
        formData.append('chunk_index', index.toString());
        formData.append('total_chunks', totalChunks.toString());
        formData.append('chunk', chunk, this.file.name);

        if (index === 0) {
            formData.append('original_name', this.file.name);
            formData.append('size', this.file.size.toString());
            formData.append('mime', this.file.type ?? '');

            const extension = getFileExtension(this.file.name);
            if (extension) {
                formData.append('extension', extension);
            }
        }

        return formData;
    }

    sendChunk({ formData, chunkIndex, chunkSizeBytes }) {
        const safeChunkSize = Number.isFinite(chunkSizeBytes) && chunkSizeBytes > 0
            ? chunkSizeBytes
            : this.chunkSize;

        return new Promise((resolve, reject) => {
            const request = new XMLHttpRequest();
            this.currentRequest = request;

            request.open('POST', this.uploadUrl, true);
            request.withCredentials = true;

            if (this.csrfToken) {
                request.setRequestHeader('X-CSRF-TOKEN', this.csrfToken);
            }

            request.upload.onprogress = (event) => {
                if (!this.onProgress) {
                    return;
                }

                const loaded = Number.isFinite(event.loaded) ? event.loaded : 0;
                const total = Number.isFinite(event.total) && event.total > 0 ? event.total : safeChunkSize;
                const previousBytes = chunkIndex * this.chunkSize;
                const computedTotal = this.file.size > 0
                    ? this.file.size
                    : Math.max(this.totalChunks * this.chunkSize, 1);
                const currentBytes = Math.min(previousBytes + loaded, computedTotal);
                const progress = Math.min((currentBytes / computedTotal) * 100, 100);

                this.onProgress(progress);
            };

            request.onerror = () => {
                reject(new Error('No fue posible comunicarse con el servidor de cargas.'));
            };

            request.onreadystatechange = () => {
                if (request.readyState !== XMLHttpRequest.DONE) {
                    return;
                }

                this.currentRequest = null;

                if (request.status >= 200 && request.status < 300) {
                    try {
                        const responseData = parseJsonSafely(request.responseText);

                        if (this.onProgress) {
                            const completedBytes = safeChunkSize;
                            const totalBytes = this.file.size > 0
                                ? this.file.size
                                : Math.max(this.totalChunks * this.chunkSize, 1);
                            const exactBytes = Math.min((chunkIndex * this.chunkSize) + completedBytes, totalBytes);
                            const finalProgress = Math.min((exactBytes / totalBytes) * 100, 100);
                            this.onProgress(finalProgress);
                        }

                        resolve(responseData);
                    } catch (error) {
                        reject(error);
                    }
                } else {
                    reject(new Error('El servidor rechazó la carga del fragmento.'));
                }
            };

            request.send(formData);
        });
    }
}

export function collectionRunUploader(options) {
    const sanitized = normalizeOptions(options);

    return {
        dataSourceId: sanitized.dataSourceId,
        uploadUrl: sanitized.uploadUrl,
        chunkSize: sanitized.chunkSize,
        fileData: sanitized.initialFile,
        fileName: sanitized.initialFile?.original_name ?? '',
        fileSize: sanitized.initialFile?.size ?? 0,
        progress: sanitized.initialFile ? 100 : 0,
        status: sanitized.initialFile ? 'completed' : 'idle',
        errorMessage: '',
        isUploading: false,
        uploadId: null,
        wireWatcherStop: null,
        currentSession: null,

        init() {
            configureLivewireChunkSize();

            if (this.fileData) {
                this.applyUploadedFile(this.fileData);
            }

            this.registerWireWatcher();
        },

        destroy() {
            this.stopWireWatcher();
            this.cancelOngoingUpload();
        },

        async handleFileSelected(event) {
            const input = event?.target;
            const file = input?.files?.[0] ?? null;

            if (!input || !file) {
                return;
            }

            this.resetInputValue(input);

            this.cancelOngoingUpload();
            this.errorMessage = '';
            this.fileData = null;
            this.fileName = file.name;
            this.fileSize = file.size;
            this.progress = 0;
            this.status = 'uploading';
            this.isUploading = true;
            this.uploadId = generateUploadId();

            this.dispatchLivewireEvent('collection-run::chunkUploading', { dataSourceId: this.dataSourceId });
            this.dispatchLivewireEvent('chunk-uploading', { dataSourceId: this.dataSourceId });

            try {
                const session = new ChunkedUploadSession({
                    file,
                    uploadUrl: this.uploadUrl,
                    uploadId: this.uploadId,
                    chunkSize: this.chunkSize,
                    csrfToken: readCsrfToken(),
                    onProgress: (value) => {
                        this.updateProgress(value);
                    },
                });

                this.currentSession = session;

                const response = await session.start();
                const uploadedFile = normalizeUploadedFile(response?.file ?? null);

                if (!uploadedFile) {
                    throw new Error('El servidor no devolvió la información del archivo cargado.');
                }

                this.applyUploadedFile(uploadedFile);

                this.dispatchLivewireEvent('collection-run::chunkUploaded', {
                    dataSourceId: this.dataSourceId,
                    file: uploadedFile,
                });

                this.dispatchLivewireEvent('chunk-uploaded', {
                    dataSourceId: this.dataSourceId,
                    file: uploadedFile,
                });
            } catch (error) {
                this.status = 'error';
                this.progress = 0;
                this.errorMessage = extractErrorMessage(error);
                this.fileData = null;
            } finally {
                this.isUploading = false;
                this.currentSession = null;
            }
        },

        progressLabel() {
            if (this.isUploading || this.status === 'completed') {
                return `${Math.round(this.progress)}%`;
            }

            if (this.fileSize > 0) {
                return humanFileSize(this.fileSize);
            }

            return '';
        },

        applyUploadedFile(file) {
            const normalized = normalizeUploadedFile(file);

            if (!normalized) {
                return;
            }

            this.fileData = normalized;
            this.fileName = normalized.original_name;
            this.fileSize = normalized.size;
            this.status = 'completed';
            this.errorMessage = '';
            this.progress = 100;
        },

        clearUploadedFile() {
            this.cancelOngoingUpload();
            this.fileData = null;
            this.fileName = '';
            this.fileSize = 0;
            this.progress = 0;
            this.status = 'idle';
            this.errorMessage = '';
            this.uploadId = null;
        },

        updateProgress(value) {
            if (!Number.isFinite(value)) {
                return;
            }

            this.progress = clamp(value, 0, 100);
        },

        cancelOngoingUpload() {
            if (this.currentSession) {
                this.currentSession.abort();
                this.currentSession = null;
            }

            this.isUploading = false;
        },

        resetInputValue(input) {
            if (input instanceof HTMLInputElement) {
                input.value = '';
            }
        },

        registerWireWatcher() {
            if (this.wireWatcherStop || typeof this.$watch !== 'function') {
                return;
            }

            if (!this.$wire || typeof this.$wire.get !== 'function') {
                window.requestAnimationFrame(() => {
                    this.registerWireWatcher();
                });

                return;
            }

            this.wireWatcherStop = this.$watch(
                () => {
                    try {
                        return this.$wire.get(`files.${this.dataSourceId}`);
                    } catch (error) {
                        console.error('No fue posible leer el archivo desde Livewire.', error);
                        return null;
                    }
                },
                (value) => {
                    if (this.isUploading) {
                        return;
                    }

                    const normalized = normalizeUploadedFile(value);

                    if (normalized) {
                        this.applyUploadedFile(normalized);
                    } else if (this.status === 'completed') {
                        this.clearUploadedFile();
                    }
                },
            );
        },

        stopWireWatcher() {
            if (typeof this.wireWatcherStop === 'function') {
                this.wireWatcherStop();
                this.wireWatcherStop = null;
            }
        },

        dispatchLivewireEvent(eventName, payload) {
            const { Livewire } = window;

            if (Livewire && typeof Livewire.dispatch === 'function') {
                Livewire.dispatch(eventName, payload);
            }
        },
    };
}

function normalizeOptions(options) {
    const dataSourceId = Number.isFinite(options?.dataSourceId) ? Number(options.dataSourceId) : 0;
    const uploadUrl = typeof options?.uploadUrl === 'string' ? options.uploadUrl : '';
    const initialFile = normalizeUploadedFile(options?.initialFile ?? null);

    return {
        dataSourceId,
        uploadUrl,
        initialFile,
        chunkSize: resolveChunkSize(options?.chunkSize),
    };
}

function normalizeUploadedFile(file) {
    if (!file || typeof file !== 'object') {
        return null;
    }

    const path = typeof file.path === 'string' ? file.path : '';
    const name = typeof file.original_name === 'string' ? file.original_name : '';
    const size = Number.isFinite(file.size) ? Number(file.size) : 0;

    if (path === '' || name === '' || size <= 0) {
        return null;
    }

    return {
        path,
        original_name: name,
        size,
        mime: typeof file.mime === 'string' ? file.mime : null,
        extension: typeof file.extension === 'string' ? file.extension : null,
    };
}

function parseJsonSafely(payload) {
    if (payload === undefined || payload === null || payload === '') {
        return {};
    }

    try {
        return JSON.parse(payload);
    } catch (error) {
        throw new Error('La respuesta del servidor no es un JSON válido.');
    }
}

function extractErrorMessage(error) {
    if (error?.response?.data?.message) {
        return String(error.response.data.message);
    }

    if (typeof error?.message === 'string' && error.message.trim() !== '') {
        return error.message;
    }

    return 'No fue posible cargar el archivo. Intenta de nuevo.';
}

function humanFileSize(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    const formatted = value >= 10 || unitIndex === 0 ? value.toFixed(0) : value.toFixed(1);

    return `${formatted} ${units[unitIndex]}`;
}

function clamp(value, minimum, maximum) {
    if (!Number.isFinite(value)) {
        return minimum;
    }

    if (minimum > maximum) {
        return clamp(value, maximum, minimum);
    }

    return Math.min(Math.max(value, minimum), maximum);
}

function resolveChunkSize(value) {
    if (!Number.isFinite(value)) {
        return DEFAULT_CHUNK_SIZE;
    }

    const numericValue = Number(value);

    if (numericValue <= 0) {
        return DEFAULT_CHUNK_SIZE;
    }

    return Math.min(Math.max(numericValue, DEFAULT_CHUNK_SIZE), DEFAULT_CHUNK_SIZE);
}

function generateUploadId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return `upload-${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
}

function readCsrfToken() {
    if (typeof document === 'undefined') {
        return null;
    }

    const element = document.querySelector('meta[name="csrf-token"]');

    if (!element) {
        return null;
    }

    const content = element.getAttribute('content');

    return typeof content === 'string' && content.trim() !== '' ? content : null;
}

function getFileExtension(filename) {
    if (typeof filename !== 'string' || !filename.includes('.')) {
        return '';
    }

    return filename.split('.').pop().toLowerCase();
}
