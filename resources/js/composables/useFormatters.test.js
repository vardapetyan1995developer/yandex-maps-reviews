import { describe, expect, it } from 'vitest';
import { useFormatters } from './useFormatters';

const { pluralize, formatNumber, formatDate } = useFormatters();

const REVIEWS = ['отзыв', 'отзыва', 'отзывов'];
const RATINGS = ['оценка', 'оценки', 'оценок'];

/** ru-RU groups thousands with U+00A0; normalise it for readable expectations. */
const plain = (value) => value.replace(/ /g, ' ');

/**
 * Russian plural agreement is not "one versus many": the form follows the last
 * digit, except in the teens, where it does not. The rule is easy to write
 * almost-correctly — 11 and 111 are what catch a naive `n % 10 === 1` check,
 * and they are why this is tested rather than eyeballed.
 */
describe('pluralize', () => {
    it.each([1, 21, 101, 1001])('%i takes the singular', (n) => {
        expect(pluralize(n, REVIEWS)).toBe('отзыв');
    });

    it.each([2, 3, 4, 22, 104])('%i takes the few form', (n) => {
        expect(pluralize(n, REVIEWS)).toBe('отзыва');
    });

    it.each([0, 5, 10, 25, 100, 600])('%i takes the many form', (n) => {
        expect(pluralize(n, REVIEWS)).toBe('отзывов');
    });

    it.each([11, 12, 13, 14, 111, 112, 1011])(
        '%i is a teen and takes the many form despite its last digit',
        (n) => {
            expect(pluralize(n, REVIEWS)).toBe('отзывов');
        },
    );

    it('agrees with the counters the interface actually shows', () => {
        const shown = (n, forms) => plain(`${formatNumber(n)} ${pluralize(n, forms)}`);

        expect(shown(5864, REVIEWS)).toBe('5 864 отзыва');
        expect(shown(21232, RATINGS)).toBe('21 232 оценки');
        expect(shown(137, REVIEWS)).toBe('137 отзывов');
        expect(shown(600, REVIEWS)).toBe('600 отзывов');
    });
});

describe('formatNumber', () => {
    it('groups thousands with a non-breaking space so a counter never wraps', () => {
        expect(formatNumber(21232)).toBe('21 232');
    });

    it('leaves short numbers ungrouped', () => {
        expect(formatNumber(137)).toBe('137');
    });

    it('treats a missing value as zero rather than printing NaN', () => {
        expect(formatNumber(null)).toBe('0');
        expect(formatNumber(undefined)).toBe('0');
    });
});

describe('formatDate', () => {
    it('renders an ISO timestamp in long Russian form', () => {
        const rendered = formatDate('2026-09-14T18:42:52.279Z');

        expect(rendered).toContain('2026');
        expect(rendered).toMatch(/сентября/);
    });

    it('says so plainly when a review carries no date', () => {
        // Reviews without a parseable date are a real case, not an error
        expect(formatDate(null)).toBe('дата неизвестна');
        expect(formatDate('')).toBe('дата неизвестна');
        expect(formatDate(undefined)).toBe('дата неизвестна');
    });
});
