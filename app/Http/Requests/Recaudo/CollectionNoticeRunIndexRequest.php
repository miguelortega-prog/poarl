<?php

namespace App\Http\Requests\Recaudo;

use Illuminate\Foundation\Http\FormRequest;

class CollectionNoticeRunIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'requested_by_id' => ['nullable', 'integer', 'exists:users,id'],
            'collection_notice_type_id' => ['nullable', 'integer', 'exists:collection_notice_types,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'requested_by_id' => $this->nullIfEmpty('requested_by_id'),
            'collection_notice_type_id' => $this->nullIfEmpty('collection_notice_type_id'),
            'date_from' => $this->nullIfEmpty('date_from'),
            'date_to' => $this->nullIfEmpty('date_to'),
        ]);
    }

    private function nullIfEmpty(string $key): mixed
    {
        $value = $this->input($key);

        return $value === '' ? null : $value;
    }
}
