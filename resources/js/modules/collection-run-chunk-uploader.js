const THIRTY_MB = 30 * 1024 * 1024;
const DEFAULT_CHUNK_SIZE = THIRTY_MB;
const MAX_CHUNK_SIZE = THIRTY_MB;

if (typeof document !== 'undefined') {
    document.addEventListener('livewire:init', () => {
        const { Livewire } = window;

        if (Livewire && typeof Livewire.setUploadChunkSize === 'function') {
            Livewire.setUploadChunkSize(DEFAULT_CHUNK_SIZE);
        }
    });
}

/**
 * @typedef {Object} UploadedFilePayload
 * @property {string} path
 * @property {string} original_name
 * @property {number} size
 * @property {string | null} [mime]
 * @property {string | null} [extension]
 */

/**
 * @typedef {Object} CollectionRunUploaderOptions
 * @property {number} dataSourceId
 * @property {string} uploadUrl
 * @property {UploadedFilePayload | null | undefined} [initialFile]
 * @property {number} [chunkSize]
 */

/**
 * @param {CollectionRunUploaderOptions} options
 */
export function collectionRunUploader(options) {
    const sanitizedOptions = normalizeOptions(options);
    const stateRegistry = ensureUploaderStateRegistry();

    return {
        dataSourceId: sanitizedOptions.dataSourceId,
        uploadUrl: sanitizedOptions.uploadUrl,
        chunkSize: sanitizedOptions.chunkSize,
        fileData: sanitizedOptions.initialFile,
        fileName: sanitizedOptions.initialFile?.original_name ?? '',
        fileSize: sanitizedOptions.initialFile?.size ?? 0,
        progress: sanitizedOptions.initialFile ? 100 : 0,
        isUploading: false,
        status: sanitizedOptions.initialFile ? 'completed' : 'idle',
        errorMessage: '',
        uploadId: null,
        wireWatcherStop: null,

        init() {
            this.restoreState(stateRegistry);

            if (sanitizedOptions.initialFile) {
                this.applyUploadedFile(sanitizedOptions.initialFile);
            } else {
                this.persistState(stateRegistry);
            }

            this.registerWireWatcher();
        },

        async handleFileSelected(event) {
            const input = event?.target;
            const file = input?.files?.[0];

            if (!input || !file) {
                return;
            }

            this.resetInput(input);

            this.errorMessage = '';
            this.fileData = null;
            this.fileName = file.name;
            this.fileSize = file.size;
            this.updateProgress(0);
            this.isUploading = true;
            this.status = 'uploading';
            this.uploadId = generateUploadId();

            this.persistState(stateRegistry);

            this.notifyLivewire('collection-run::chunkUploading', { dataSourceId: this.dataSourceId });
            this.notifyLivewire('chunk-uploading', { dataSourceId: this.dataSourceId });

            if (!this.uploadUrl) {
                this.isUploading = false;
                this.status = 'error';
                this.errorMessage = 'No se encontró el endpoint de carga. Comunícate con soporte.';
                this.notifyLivewire('toast', { type: 'error', message: this.errorMessage });
                this.persistState(stateRegistry);

                return;
            }

            try {
                const response = await uploadFileInChunks({
                    file,
                    uploadId: this.uploadId,
                    url: this.uploadUrl,
                    chunkSize: this.chunkSize,
                    onProgress: (value) => {
                        this.updateProgress(value);
                    },
                });

                this.isUploading = false;
                this.status = 'completed';
                this.updateProgress(100);

                const uploadedFile = normalizeUploadedFile(response.file ?? null);

                if (!uploadedFile) {
                    throw new Error('La respuesta del servidor no contiene la información del archivo.');
                }

                this.applyUploadedFile(uploadedFile);
                this.persistState(stateRegistry);

                this.notifyLivewire('collection-run::chunkUploaded', {
                    dataSourceId: this.dataSourceId,
                    file: uploadedFile,
                });

                this.notifyLivewire('chunk-uploaded', {
                    dataSourceId: this.dataSourceId,
                    file: uploadedFile,
                });
            } catch (error) {
                this.isUploading = false;
                this.status = 'error';
                this.updateProgress(0);

                const message = extractErrorMessage(error);
                this.errorMessage = message;

                this.notifyLivewire('toast', { type: 'error', message });
                this.persistState(stateRegistry);
            }
        },

        progressLabel() {
            if (this.isUploading || this.status === 'completed') {
                return `${Math.round(this.progress)}%`;
            }

            if (this.fileSize) {
                return humanFileSize(this.fileSize);
            }

            return '';
        },

        applyUploadedFile(file) {
            this.fileData = file;
            this.fileName = file.original_name;
            this.fileSize = file.size;
            this.status = 'completed';
            this.updateProgress(100);
            this.isUploading = false;
            this.errorMessage = '';
            this.persistState(stateRegistry);
        },

        clearUploadedFile() {
            this.fileData = null;
            this.fileName = '';
            this.fileSize = 0;
            this.updateProgress(0);
            this.isUploading = false;
            this.status = 'idle';
            this.errorMessage = '';
            this.uploadId = null;
            this.clearState(stateRegistry);
        },

        registerWireWatcher() {
            if (this.wireWatcherStop) {
                return;
            }

            if (typeof this.$wire?.get !== 'function') {
                window.requestAnimationFrame(() => {
                    this.registerWireWatcher();
                });

                return;
            }

            this.wireWatcherStop = this.$watch(
                () => {
                    try {
                        return this.$wire?.get?.(`files.${this.dataSourceId}`) ?? null;
                    } catch (error) {
                        console.error('No fue posible consultar el archivo cargado desde Livewire.', error);

                        return null;
                    }
                },
                (value) => {
                    const normalized = normalizeUploadedFile(value);

                    if (!normalized) {
                        if (!this.isUploading && this.status !== 'error') {
                            this.clearUploadedFile();
                        }

                        return;
                    }

                    if (this.isUploading) {
                        return;
                    }

                    this.applyUploadedFile(normalized);
                },
            );
        },

        destroy() {
            if (typeof this.wireWatcherStop === 'function') {
                this.wireWatcherStop();
                this.wireWatcherStop = null;
            }

            this.persistState(stateRegistry);
        },

        resetInput(input) {
            if (input instanceof HTMLInputElement) {
                input.value = '';
            }
        },

        notifyLivewire(eventName, payload) {
            const { Livewire } = window;

            if (Livewire && typeof Livewire.dispatch === 'function') {
                Livewire.dispatch(eventName, payload);
            }
        },

        restoreState(registry) {
            const saved = readUploaderState(registry, this.dataSourceId);

            if (!saved) {
                return;
            }

            this.fileData = saved.fileData;
            this.fileName = saved.fileName;
            this.fileSize = saved.fileSize;
            this.progress = clamp(saved.progress, 0, 100);
            this.isUploading = saved.isUploading;
            this.status = saved.status;
            this.errorMessage = saved.errorMessage;
            this.uploadId = saved.uploadId;
        },

        persistState(registry) {
            if (!this.isUploading && !this.fileData && this.fileName === '') {
                clearUploaderState(registry, this.dataSourceId);

                return;
            }

            writeUploaderState(registry, this.dataSourceId, {
                fileData: this.fileData,
                fileName: this.fileName,
                fileSize: this.fileSize,
                progress: this.progress,
                isUploading: this.isUploading,
                status: this.status,
                errorMessage: this.errorMessage,
                uploadId: this.uploadId,
            });
        },

        progressStyle() {
            const width = Number.isFinite(this.progress) ? clamp(this.progress, 0, 100) : 0;

            return `width: ${width.toFixed(2)}%`;
        },

        clearState(registry) {
            clearUploaderState(registry, this.dataSourceId);
        },

        updateProgress(value) {
            if (!Number.isFinite(value)) {
                return;
            }

            const normalized = clamp(value, 0, 100);

            if (normalized === this.progress) {
                this.persistState(stateRegistry);

                return;
            }

            this.progress = normalized > this.progress ? normalized : this.progress;
            this.persistState(stateRegistry);
        },
    };
}

function ensureUploaderStateRegistry() {
    if (typeof window === 'undefined') {
        return new Map();
    }

    const key = '__collectionRunUploaderState__';
    const existing = window[key];

    if (existing instanceof Map) {
        return existing;
    }

    const registry = new Map();
    window[key] = registry;

    return registry;
}

function readUploaderState(registry, dataSourceId) {
    const key = String(dataSourceId);

    if (!registry.has(key)) {
        return null;
    }

    const state = registry.get(key);

    if (!state || typeof state !== 'object') {
        return null;
    }

    return {
        fileData: normalizeUploadedFile(state.fileData ?? null),
        fileName: typeof state.fileName === 'string' ? state.fileName : '',
        fileSize: Number.isFinite(state.fileSize) ? Number(state.fileSize) : 0,
        progress: Number.isFinite(state.progress) ? Number(state.progress) : 0,
        isUploading: Boolean(state.isUploading),
        status: typeof state.status === 'string' ? state.status : 'idle',
        errorMessage: typeof state.errorMessage === 'string' ? state.errorMessage : '',
        uploadId: typeof state.uploadId === 'string' ? state.uploadId : null,
    };
}

function writeUploaderState(registry, dataSourceId, state) {
    const key = String(dataSourceId);

    registry.set(key, {
        fileData: normalizeUploadedFile(state.fileData ?? null),
        fileName: typeof state.fileName === 'string' ? state.fileName : '',
        fileSize: Number.isFinite(state.fileSize) ? Number(state.fileSize) : 0,
        progress: Number.isFinite(state.progress) ? Number(state.progress) : 0,
        isUploading: Boolean(state.isUploading),
        status: typeof state.status === 'string' ? state.status : 'idle',
        errorMessage: typeof state.errorMessage === 'string' ? state.errorMessage : '',
        uploadId: typeof state.uploadId === 'string' ? state.uploadId : null,
    });
}

function clearUploaderState(registry, dataSourceId) {
    const key = String(dataSourceId);

    if (registry.has(key)) {
        registry.delete(key);
    }
}

/**
 * @param {CollectionRunUploaderOptions} options
 */
function normalizeOptions(options) {
    const dataSourceId = Number.isFinite(options?.dataSourceId) ? Number(options.dataSourceId) : 0;
    const chunkSize = resolveChunkSize(options?.chunkSize);

    return {
        dataSourceId,
        uploadUrl: typeof options?.uploadUrl === 'string' ? options.uploadUrl : '',
        initialFile: normalizeUploadedFile(options?.initialFile ?? null),
        chunkSize,
    };
}

/**
 * @param {UploadedFilePayload | null} file
 * @returns {UploadedFilePayload | null}
 */
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

/**
 * @param {{ file: File, uploadId: string, url: string, chunkSize: number, onProgress?: (progress: number) => void }} params
 */
async function uploadFileInChunks(params) {
    const size = resolveChunkSize(params.chunkSize);
    const totalChunks = Math.max(1, Math.ceil(params.file.size / size));

    for (let index = 0; index < totalChunks; index += 1) {
        const start = index * size;
        const end = Math.min(params.file.size, start + size);
        const chunk = params.file.slice(start, end);

        const formData = new FormData();
        formData.append('upload_id', params.uploadId);
        formData.append('chunk_index', index.toString());
        formData.append('total_chunks', totalChunks.toString());
        formData.append('chunk', chunk, params.file.name);

        if (index === 0) {
            formData.append('original_name', params.file.name);
            formData.append('size', params.file.size.toString());
            formData.append('mime', params.file.type);

            const extension = params.file.name.includes('.') ? params.file.name.split('.').pop() : '';
            if (extension) {
                formData.append('extension', extension.toLowerCase());
            }
        }

        const response = await sendChunk({
            url: params.url,
            formData,
            onProgress: (loaded, total) => {
                if (typeof params.onProgress !== 'function') {
                    return;
                }

                if (!total) {
                    params.onProgress(((index + 0.0001) / totalChunks) * 100);

                    return;
                }

                const chunkProgress = loaded / total;
                const globalProgress = ((index + chunkProgress) / totalChunks) * 100;
                params.onProgress(Math.min(globalProgress, 100));
            },
        });

        if (typeof params.onProgress === 'function') {
            params.onProgress(((index + 1) / totalChunks) * 100);
        }

        if (response?.completed) {
            return response;
        }
    }

    throw new Error('La respuesta del servidor no es válida.');
}

async function sendChunk({ url, formData, onProgress }) {
    const client = typeof window !== 'undefined' ? window.axios : null;

    if (!client) {
        throw new Error('No fue posible inicializar el cliente HTTP para la carga.');
    }

    const csrfToken = readCsrfToken();

    try {
        const response = await client.post(url, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
            onUploadProgress: (event) => {
                if (typeof onProgress !== 'function') {
                    return;
                }

                const total = Number.isFinite(event?.total) ? event.total : 0;
                const loaded = Number.isFinite(event?.loaded) ? event.loaded : 0;

                onProgress(loaded, total);
            },
            withCredentials: true,
        });

        return response?.data ?? null;
    } catch (error) {
        throw error;
    }
}

function extractErrorMessage(error) {
    if (error?.response?.data?.message) {
        return error.response.data.message;
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

function clamp(value, minimum, maximum) {
    if (!Number.isFinite(value)) {
        return minimum;
    }

    if (!Number.isFinite(minimum) || !Number.isFinite(maximum)) {
        return value;
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

    return Math.min(Math.max(numericValue, DEFAULT_CHUNK_SIZE), MAX_CHUNK_SIZE);
}
