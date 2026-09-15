<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\SupportedSourceUrl;
use App\Services\Scraping\SourceRegistry;
use Illuminate\Foundation\Http\FormRequest;

final class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                'max:2048',
                app(SupportedSourceUrl::class),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Укажите ссылку на карточку организации.',
            'url.max' => 'Ссылка слишком длинная.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $url = $this->input('url');

        if (is_string($url)) {
            // Links pasted from messengers and email frequently arrive with
            // stray whitespace and invisible characters around them
            $this->merge(['url' => trim($url, " \t\n\r\0\x0B\u{200B}\u{00A0}")]);
        }
    }

    public function sourceRegistry(): SourceRegistry
    {
        return app(SourceRegistry::class);
    }
}
