<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportLitCallAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'cycleId' => 'required|integer|exists:tbl_LitCycle,cycleId',
            'emails' => 'required|array|min:1',
            'emails.*' => 'required|email',
        ];
    }

    public function messages(): array
    {
        return [
            'from.required' => 'Start date is required',
            'to.required' => 'End date is required',
            'to.after_or_equal' => 'End date must be after or equal to start date',
            'cycleId.required' => 'Cycle ID is required',
            'cycleId.exists' => 'Invalid cycle ID',
            'emails.required' => 'At least one email is required',
            'emails.*.email' => 'Invalid email format',
        ];
    }
}
