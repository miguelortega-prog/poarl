import axios from 'axios';

const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024;

export function collectionRunUploader(options) {
    return {
        fileName: options.initialFile?.original_name ?? '',
        fileSize: options.initialFile?.size ?? 0,
        progress: 0,
        isUploading: false,
        status: options.initialFile ? 'completed' : 'idle',
        errorMessage: '',
        uploadId: null,
        handleFileSelected(event) {
            const file = event.target.files?.[0];

            if (!file) {
                return;
            }

            event.target.value = '';

            this.errorMessage = '';
            this.fileName = file.name;
            this.fileSize = file.size;
            this.progress = 0;
            this.isUploading = true;
            this.status = 'uploading';
            this.uploadId = generateUploadId();

            if (window.Livewire) {
                window.Livewire.dispatch('chunk-uploading', { dataSourceId: options.dataSourceId });
            }

            uploadFileInChunks({
                file,
                uploadId: this.uploadId,
                url: options.uploadUrl,
                chunkSize: options.chunkSize ?? DEFAULT_CHUNK_SIZE,
                onProgress: (value) => {
                    this.progress = value;
                },
            })
                .then((response) => {
                    this.isUploading = false;
                    this.status = 'completed';
                    this.progress = 100;

                    const uploadedFile = response.file ?? {};
                    this.fileName = uploadedFile.original_name ?? file.name;
                    this.fileSize = uploadedFile.size ?? file.size;

                    if (window.Livewire) {
                        window.Livewire.dispatch('chunk-uploaded', {
                            dataSourceId: options.dataSourceId,
                            file: uploadedFile,
                        });
                    }
                })
                .catch((error) => {
                    this.isUploading = false;
                    this.status = 'error';
                    this.progress = 0;

                    const message = extractErrorMessage(error);
                    this.errorMessage = message;

                    if (window.Livewire) {
                        window.Livewire.dispatch('toast', { type: 'error', message });
                    }
                });
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
    };
}

async function uploadFileInChunks({ file, uploadId, url, chunkSize, onProgress }) {
    const size = Math.max(chunkSize, 512 * 1024);
    const totalChunks = Math.max(1, Math.ceil(file.size / size));

    for (let index = 0; index < totalChunks; index += 1) {
        const start = index * size;
        const end = Math.min(file.size, start + size);
        const chunk = file.slice(start, end);

        const formData = new FormData();
        formData.append('upload_id', uploadId);
        formData.append('chunk_index', index.toString());
        formData.append('total_chunks', totalChunks.toString());
        formData.append('chunk', chunk, file.name);

        if (index === 0) {
            formData.append('original_name', file.name);
            formData.append('size', file.size.toString());
            formData.append('mime', file.type);

            const extension = file.name.includes('.') ? file.name.split('.').pop() : '';
            if (extension) {
                formData.append('extension', extension.toLowerCase());
            }
        }

        const response = await axios.post(url, formData, {
            onUploadProgress: (event) => {
                if (event.total) {
                    const chunkProgress = event.loaded / event.total;
                    const globalProgress = ((index + chunkProgress) / totalChunks) * 100;
                    onProgress?.(Math.min(globalProgress, 100));
                }
            },
        });

        if (typeof onProgress === 'function') {
            onProgress(((index + 1) / totalChunks) * 100);
        }

        if (response?.data?.completed) {
            return response.data;
        }
    }

    throw new Error('La respuesta del servidor no es válida.');
}

function extractErrorMessage(error) {
    if (error.response?.data?.message) {
        return error.response.data.message;
    }

    if (typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message;
    }

    return 'No fue posible cargar el archivo. Intenta de nuevo.';
}

function humanFileSize(bytes) {
    if (!bytes || Number.isNaN(bytes)) {
        return '';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    return `${value.toFixed(value >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

function generateUploadId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return 'upload-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}
