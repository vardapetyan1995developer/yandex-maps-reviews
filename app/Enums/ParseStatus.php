<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an organization parse.
 *
 * `Partial` is deliberately its own state: a source can hand back some of the
 * data and then stop (depth limit reached, blocked mid-run). That is neither a
 * success nor an outright failure, and the interface has to render it
 * differently from both.
 */
enum ParseStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Success, self::Partial, self::Failed], true);
    }

    public function isRunning(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }

    /** Human-readable label rendered in the UI (Russian: the product's audience). */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает парсинга',
            self::Queued => 'В очереди',
            self::Running => 'Парсится',
            self::Success => 'Данные получены',
            self::Partial => 'Получены частично',
            self::Failed => 'Ошибка парсинга',
        };
    }
}
