import axios from 'axios';

const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024;

document.addEventListener('livewire:init', () => {
    const { Livewire } = window;

    if (Livewire && typeof Livewire.setUploadChunkSize === 'function') {
        Livewire.setUploadChunkSize(DEFAULT_CHUNK_SIZE);
    }
});

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
            if (this.fileData) {
                this.applyUploadedFile(this.fileData);
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
            this.fileName = file.name;
            this.fileSize = file.size;
            this.progress = 0;
            this.isUploading = true;
            this.status = 'uploading';
            this.uploadId = generateUploadId();

            this.notifyLivewire('collection-run::chunkUploading', { dataSourceId: this.dataSourceId });
            this.notifyLivewire('chunk-uploading', { dataSourceId: this.dataSourceId });

            if (!this.uploadUrl) {
                this.isUploading = false;
                this.status = 'error';
                this.errorMessage = 'No se encontró el endpoint de carga. Comunícate con soporte.';
                this.notifyLivewire('toast', { type: 'error', message: this.errorMessage });

                return;
            }

            try {
                const response = await uploadFileInChunks({
                    file,
                    uploadId: this.uploadId,
                    url: this.uploadUrl,
                    chunkSize: this.chunkSize,
                    onProgress: (value) => {
                        this.progress = value;
                    },
                });

                this.isUploading = false;
                this.status = 'completed';
                this.progress = 100;

                const uploadedFile = normalizeUploadedFile(response.file ?? null);

                if (!uploadedFile) {
                    throw new Error('La respuesta del servidor no contiene la información del archivo.');
                }

                this.applyUploadedFile(uploadedFile);

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
                this.progress = 0;

                const message = extractErrorMessage(error);
                this.errorMessage = message;

                this.notifyLivewire('toast', { type: 'error', message });
            }
        },

        progressLabel() {
            if (this.isUploading) {
                return `${Math.round(this.progress)}%`;
            }

            if (this.status === 'completed' && this.fileSize) {
                return humanFileSize(this.fileSize);
            }

            return '';
        },

        applyUploadedFile(file) {
            this.fileData = file;
            this.fileName = file.original_name;
            this.fileSize = file.size;
            this.status = 'completed';
            this.progress = 100;
            this.isUploading = false;
        },

        clearUploadedFile() {
            this.fileData = null;
            this.fileName = '';
            this.fileSize = 0;
            this.progress = 0;
            this.isUploading = false;
            this.status = 'idle';
            this.errorMessage = '';
            this.uploadId = null;
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
    };
}

/**
 * @param {CollectionRunUploaderOptions} options
 */
function normalizeOptions(options) {
    const dataSourceId = Number.isFinite(options?.dataSourceId) ? Number(options.dataSourceId) : 0;
    const chunkSize = Number.isFinite(options?.chunkSize) && options.chunkSize > 0
        ? Math.min(options.chunkSize, DEFAULT_CHUNK_SIZE)
        : DEFAULT_CHUNK_SIZE;

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
    const size = Math.max(params.chunkSize, 512 * 1024);
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

        const response = await axios.post(params.url, formData, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            onUploadProgress: (event) => {
                if (!event.total) {
                    return;
                }

                const chunkProgress = event.loaded / event.total;
                const globalProgress = ((index + chunkProgress) / totalChunks) * 100;

                if (typeof params.onProgress === 'function') {
                    params.onProgress(Math.min(globalProgress, 100));
                }
            },
        });

        if (typeof params.onProgress === 'function') {
            params.onProgress(((index + 1) / totalChunks) * 100);
        }

        if (response?.data?.completed) {
            return response.data;
        }
    }

    throw new Error('La respuesta del servidor no es válida.');
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
