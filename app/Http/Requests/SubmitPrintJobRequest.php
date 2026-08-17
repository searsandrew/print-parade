<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitPrintJobRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label_template_id' => ['required', 'integer', 'exists:label_templates,id'],
            'printer_id' => ['required', 'integer', 'exists:printers,id'],
            'user_id' => [Rule::requiredIf(fn (): bool => (bool) $this->user()?->requires_print_operator_pin), 'nullable', 'integer', 'exists:users,id'],
            'pin' => [Rule::requiredIf(fn (): bool => (bool) $this->user()?->requires_print_operator_pin), 'nullable', 'string', 'regex:/\A\d{4,8}\z/'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'values' => ['present', 'array'],
        ];
    }
}
