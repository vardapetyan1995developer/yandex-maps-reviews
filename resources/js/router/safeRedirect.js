/**
 * Validates the `redirect` query parameter before it is used for navigation.
 *
 * The value reaches the router straight from the address bar, which makes it
 * attacker-controlled: a crafted link like `/login?redirect=//evil.example`
 * would send the user somewhere else the moment they sign in, on a page that
 * legitimately asks for their password.
 *
 * Vue Router happens to reject absolute and protocol-relative values today, so
 * this is not currently exploitable — but relying on that is relying on a
 * behaviour nobody promised. The check is explicit instead.
 */

export const DEFAULT_ROUTE = '/';

export function safeRedirect(value) {
  if (typeof value !== 'string' || value === '') {
    return DEFAULT_ROUTE;
  }

  // Must be a root-relative path. `//evil.example` is protocol-relative and
  // resolves to another host, so a leading slash alone is not enough.
  if (!value.startsWith('/') || value.startsWith('//')) {
    return DEFAULT_ROUTE;
  }

  // `/\evil.example` is treated as a protocol-relative URL by some browsers
  if (value.startsWith('/\\')) {
    return DEFAULT_ROUTE;
  }

  return value;
}

/**
 * Whether a path is worth preserving as a redirect target.
 *
 * Sending someone to the default route is what happens anyway, so recording it
 * only puts `?redirect=/` in the address bar for no benefit.
 */
export function isWorthRemembering(fullPath) {
  return typeof fullPath === 'string' && fullPath !== DEFAULT_ROUTE;
}
