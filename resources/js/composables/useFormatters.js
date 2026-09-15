/**
 * Date and number formatting for the Russian locale.
 * Extracted so the same rules do not get scattered across components.
 */
const dateFormatter = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

const numberFormatter = new Intl.NumberFormat('ru-RU');

export function useFormatters() {
    function formatDate(value) {
        if (!value) {
            return 'дата неизвестна';
        }

        return dateFormatter.format(new Date(value));
    }

    function formatNumber(value) {
        return numberFormatter.format(value ?? 0);
    }

    /**
     * Declines a noun after a numeral.
     * "1 отзыв", "2 отзыва", "5 отзывов" — without this the interface reads as
     * machine-generated to a Russian speaker.
     */
    function pluralize(count, [one, few, many]) {
        const mod10 = count % 10;
        const mod100 = count % 100;

        if (mod10 === 1 && mod100 !== 11) {
            return one;
        }

        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
            return few;
        }

        return many;
    }

    return { formatDate, formatNumber, pluralize };
}
