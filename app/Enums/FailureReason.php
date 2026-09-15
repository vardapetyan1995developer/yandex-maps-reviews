<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Machine-readable causes of a failed parse.
 *
 * The distinction is not cosmetic: the cause determines how the system reacts.
 * `SchemaChanged` requires a code change and must be escalated to a developer,
 * `Blocked` is a signal for the queue to slow down, and `Unavailable` is an
 * ordinary retry.
 */
enum FailureReason: string
{
    case InvalidUrl = 'invalid_url';
    case NotFound = 'not_found';
    case Blocked = 'blocked';
    case SchemaChanged = 'schema_changed';
    case EmptyResponse = 'empty_response';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';

    /** Whether retrying automatically could plausibly succeed. */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Blocked, self::Unavailable, self::EmptyResponse => true,
            default => false,
        };
    }

    /** Whether this failure needs a code change; such errors are escalated, not retried. */
    public function requiresDeveloperAttention(): bool
    {
        return $this === self::SchemaChanged;
    }

    /** Human-readable label rendered in the UI (Russian: the product's audience). */
    public function label(): string
    {
        return match ($this) {
            self::InvalidUrl => 'Некорректная ссылка',
            self::NotFound => 'Организация не найдена',
            self::Blocked => 'Источник заблокировал запросы',
            self::SchemaChanged => 'Изменилась структура ответа источника',
            self::EmptyResponse => 'Источник вернул пустой ответ',
            self::Unavailable => 'Источник временно недоступен',
            self::Unknown => 'Неизвестная ошибка',
        };
    }
}
