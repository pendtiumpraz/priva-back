<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Spec §3.7 stores question_text + sort_order + explanation; DB columns are
 * `prompt`, `order`, no explanation column. Map at boundary in controller.
 *
 * Options shape (spec):  [{ id: 'a', text: '...', is_correct: bool }, ...]
 * DB shape:              `options` jsonb [{ key:'a', label:'...', is_correct:bool }, ...]
 *                        `correct_answer` jsonb ['a','b'] (mcq) or [true]/[false] (true_false)
 *
 * Translation happens in the controller; this FormRequest stays DB-free.
 */
class StoreQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'question_text'        => ['required', 'string', 'max:2000'],
            'type'                 => ['required', 'in:mcq,true_false'],
            'sort_order'           => ['nullable', 'integer', 'min:0'],
            'points'               => ['nullable', 'integer', 'min:1', 'max:100'],

            // MCQ-only
            'options'              => ['required_if:type,mcq', 'array', 'min:2', 'max:8'],
            'options.*.id'         => ['required_if:type,mcq', 'string', 'max:32'],
            'options.*.text'       => ['required_if:type,mcq', 'string', 'max:500'],
            'options.*.is_correct' => ['required_if:type,mcq', 'boolean'],

            // true_false-only
            'correct_answer'       => ['required_if:type,true_false', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('type') !== 'mcq') {
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

            // option ids must be unique
            $ids = collect($opts)->pluck('id')->filter()->all();
            if (count($ids) !== count(array_unique($ids))) {
                $v->errors()->add('options', 'Option ids must be unique within a question.');
            }
        });
    }
}
