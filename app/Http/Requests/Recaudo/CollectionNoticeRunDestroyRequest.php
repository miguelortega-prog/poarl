<?php

namespace App\Http\Requests\Recaudo;

use Illuminate\Foundation\Http\FormRequest;

class CollectionNoticeRunDestroyRequest extends FormRequest
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
        return [];
    }
}
