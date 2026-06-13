<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        // type is fixed once the question is created — changing it would
        // invalidate stored options/correct_answer. To switch type, delete +
        // recreate. Keep update narrow.
        return [
            'question_text'        => ['sometimes', 'required', 'string', 'max:2000'],
            'sort_order'           => ['sometimes', 'nullable', 'integer', 'min:0'],
            'points'               => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],

            'options'              => ['sometimes', 'array', 'min:2', 'max:8'],
            'options.*.id'         => ['required_with:options', 'string', 'max:32'],
            'options.*.text'       => ['required_with:options', 'string', 'max:500'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],

            'correct_answer'       => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (! $this->has('options')) {
                return;
            }

            $opts = $this->input('options', []);
            if (! is_array($opts)) {
                return;
            }

            $correct = collect($opts)->filter(fn ($o) => ! empty($o['is_correct']));
            if ($correct->isEmpty()) {
                $v->errors()->add('options', 'At least one option must be marked correct.');
            }

            $ids = collect($opts)->pluck('id')->filter()->all();
            if (count($ids) !== count(array_unique($ids))) {
                $v->errors()->add('options', 'Option ids must be unique within a question.');
            }
        });
    }
}
