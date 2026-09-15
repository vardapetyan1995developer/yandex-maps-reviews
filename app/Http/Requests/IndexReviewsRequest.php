<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\ReviewQuery;
use App\Enums\ReviewSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query parameters for the review listing.
 *
 * Ownership is deliberately not checked here. This request only knows about
 * the shape of the input; deciding whether the card belongs to the caller needs
 * the repository, and the answer has to be a 404 rather than the 403 a failed
 * authorize() would produce — a 403 would confirm that someone else's record
 * exists.
 */
final class IndexReviewsRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ReviewQuery::MAX_PER_PAGE],
            // Built from the enum rather than a hand-written list, so adding a
            // sort option cannot leave the validation rule behind
            'sort' => ['sometimes', 'string', 'in:'.implode(',', ReviewSort::values())],
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.max' => 'За один раз можно запросить не больше :max отзывов.',
            'sort.in' => 'Неизвестный порядок сортировки.',
            'rating.min' => 'Оценка может быть только от 1 до 5.',
            'rating.max' => 'Оценка может быть только от 1 до 5.',
        ];
    }

    /** Hand the validated input to the repository as a typed object. */
    public function toQuery(): ReviewQuery
    {
        return ReviewQuery::fromArray($this->validated());
    }
}
