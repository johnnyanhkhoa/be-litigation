<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateLitCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phoneExtension' => 'required|string|max:10',
            'phoneNo' => 'required|string|max:20',
            'caseId' => 'required|string|max:50',
            'username' => 'required|string|max:100',
            'userId' => 'required|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'phoneExtension.required' => 'Phone extension is required',
            'phoneNo.required' => 'Phone number is required',
            'caseId.required' => 'Case ID is required',
            'username.required' => 'Username is required',
            'userId.required' => 'User ID is required',
        ];
    }
}
