import { describe, expect, it } from 'vitest';
import { DEFAULT_ROUTE, isWorthRemembering, safeRedirect } from './safeRedirect';

/**
 * The redirect target arrives from the address bar, which makes it
 * attacker-controlled on a page that legitimately asks for a password. Vue
 * Router rejects hostile values today, so none of this is currently
 * exploitable — but that is a behaviour nobody promised, and these cases pin
 * the guarantee to our own code.
 */
describe('safeRedirect', () => {
    it('keeps an internal path', () => {
        expect(safeRedirect('/organizations/3')).toBe('/organizations/3');
    });

    it('keeps the query string on an internal path', () => {
        expect(safeRedirect('/organizations/3?page=2&sort=rating_asc'))
            .toBe('/organizations/3?page=2&sort=rating_asc');
    });

    it.each([
        ['//example.com/phish', 'protocol-relative — resolves to another host'],
        ['/\\example.com', 'backslash form some browsers treat as protocol-relative'],
        ['https://example.com', 'absolute https'],
        ['http://example.com', 'absolute http'],
        ['javascript:alert(1)', 'script scheme'],
        ['organizations/3', 'no leading slash'],
        ['', 'empty string'],
    ])('rejects %s (%s)', (input) => {
        expect(safeRedirect(input)).toBe(DEFAULT_ROUTE);
    });

    it.each([
        [undefined, 'parameter absent'],
        [null, 'null'],
        [['/a', '/b'], 'array — what a duplicated query parameter produces'],
        [42, 'number'],
        [{}, 'object'],
    ])('falls back for %s (%s)', (input) => {
        expect(safeRedirect(input)).toBe(DEFAULT_ROUTE);
    });
});

describe('isWorthRemembering', () => {
    it('does not record the default route', () => {
        // Recording it only puts ?redirect=/ in the address bar for no benefit
        expect(isWorthRemembering('/')).toBe(false);
    });

    it('records a deeper destination', () => {
        expect(isWorthRemembering('/organizations/3')).toBe(true);
    });

    it('ignores anything that is not a string', () => {
        expect(isWorthRemembering(undefined)).toBe(false);
    });
});
