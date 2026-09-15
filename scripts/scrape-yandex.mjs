#!/usr/bin/env node
/**
 * Fallback strategy for collecting reviews through a real browser.
 *
 * Invoked from HeadlessBrowserStrategy and used only when the primary, fast
 * path (parsing the internal JSON API) has stopped working because Yandex
 * changed the contract.
 *
 * The essential point: this script never parses markup. It reads reviews from
 * the server-rendered state the page already carries, and intercepts the same
 * fetchReviews responses the frontend requests as it scrolls. Both sources carry
 * the source's own reviewId, so records line up with the fast path and a repeat
 * parse stays idempotent no matter which strategy produced it.
 *
 * Reading the state matters more than it looks: a card whose reviews all fit in
 * the first render — anything under roughly fifty — issues no XHR at all, so a
 * scroll-and-intercept approach alone comes back empty. That was found by
 * running this against a real card rather than by reasoning about it.
 *
 * Exchange protocol with PHP:
 *   stdout — a single JSON result;
 *   stderr — line-delimited progress events, also JSON;
 *   exit code — 0 success, 2 captcha, 3 unexpected page, 1 anything else.
 */

import { chromium } from 'playwright';

const EXIT_OK = 0;
const EXIT_GENERIC = 1;
const EXIT_CAPTCHA = 2;
const EXIT_UNEXPECTED_PAGE = 3;

const args = Object.fromEntries(
  process.argv.slice(2).map((arg) => {
    const [key, ...rest] = arg.replace(/^--/, '').split('=');
    return [key, rest.join('=')];
  }),
);

const targetUrl = args.url;
const businessId = args['business-id'];
const maxReviews = Number.parseInt(args['max-reviews'] ?? '600', 10);

if (!targetUrl || !businessId) {
  process.stderr.write('--url and --business-id are required\n');
  process.exit(EXIT_GENERIC);
}

const progress = (payload) => {
  process.stderr.write(JSON.stringify({ event: 'progress', ...payload }) + '\n');
};

const fail = (code, message) => {
  process.stderr.write(JSON.stringify({ event: 'error', message }) + '\n');
  process.exit(code);
};

const reviewsById = new Map();
let organization = null;
let pagesFetched = 0;
let declaredCount = null;

/**
 * Map raw review objects into the shape PHP expects, keyed by the source's own
 * reviewId. Both the server-rendered state and the intercepted XHR responses
 * use the identical field names, so one routine serves both and duplicates
 * between them collapse on their own.
 */
const collect = (list) => {
  if (!Array.isArray(list)) {
    return;
  }

  for (const review of list) {
    if (!review?.reviewId || reviewsById.has(review.reviewId)) {
      continue;
    }

    reviewsById.set(review.reviewId, {
      externalId: review.reviewId,
      author: review.author?.name ?? 'Аноним',
      avatar: review.author?.avatarUrl?.replace('{size}', 'islands-68') ?? null,
      rating: review.rating ?? null,
      text: review.text ?? null,
      publishedAt: review.updatedTime ?? null,
    });
  }
};

const browser = await chromium.launch({
  headless: true,
  args: ['--disable-blink-features=AutomationControlled', '--no-sandbox'],
});

try {
  const context = await browser.newContext({
    locale: 'ru-RU',
    viewport: { width: 1440, height: 900 },
    userAgent:
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
      '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
  });

  // Remove the most obvious automation tell before any page loads
  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
  });

  const page = await context.newPage();

  // Intercept responses instead of reading the DOM: the data arrives in its
  // original form rather than scraped out of markup, which changes most often
  page.on('response', async (response) => {
    if (!response.url().includes('/api/business/fetchReviews')) {
      return;
    }

    let payload;
    try {
      payload = await response.json();
    } catch {
      return;
    }

    const list = payload?.data?.reviews;
    if (!Array.isArray(list)) {
      return;
    }

    pagesFetched += 1;
    declaredCount = payload?.data?.params?.count ?? declaredCount;

    collect(list);

    progress({
      pages: pagesFetched,
      reviews: reviewsById.size,
      total: declaredCount ? Math.min(declaredCount, maxReviews) : null,
    });
  });

  await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });

  if (page.url().includes('showcaptcha') || page.url().includes('captcha.yandex')) {
    fail(EXIT_CAPTCHA, 'Yandex served a captcha');
  }

  // The card and the first batch of reviews both come from the state the server
  // rendered into the page — the same structure the fast path reads
  const initial = await page.evaluate((id) => {
    const node = document.querySelector('script.state-view');
    if (!node) {
      return null;
    }

    let state;
    try {
      state = JSON.parse(node.textContent);
    } catch {
      return null;
    }

    const items = state?.stack?.[0]?.results?.items ?? [];
    const item = items.find((candidate) => String(candidate?.id) === String(id)) ?? items[0];
    if (!item) {
      return null;
    }

    return {
      organization: {
        externalId: String(item.id ?? id),
        name: item.title ?? item.shortTitle ?? '',
        address: item.fullAddress ?? item.address ?? null,
        rating: item.ratingData?.ratingValue ?? null,
        ratingsCount: item.ratingData?.ratingCount ?? 0,
        reviewsCount: item.ratingData?.reviewCount ?? 0,
        categories: (item.categories ?? []).map((category) => category?.name).filter(Boolean),
      },
      reviews: item.reviewResults?.reviews ?? [],
    };
  }, businessId);

  if (!initial?.organization) {
    fail(EXIT_UNEXPECTED_PAGE, 'No initial state with an organization card found on the page');
  }

  organization = initial.organization;
  collect(initial.reviews);
  progress({ pages: pagesFetched, reviews: reviewsById.size, total: declaredCount });

  // Scroll the feed so the remaining pages load.
  //
  // The wheel has to be driven through the input layer rather than by setting
  // scrollTop or calling scrollBy. Those move the container — the scroll offset
  // really does reach the bottom — but they produce untrusted events, and the
  // lazy-load never fires: the list sits at its first batch forever. Verified
  // both ways against a card with thousands of reviews; only the wheel loads
  // more.
  await page.mouse.move(400, 500);

  let idleRounds = 0;
  let previousCount = 0;

  while (reviewsById.size < maxReviews && idleRounds < 3) {
    for (let tick = 0; tick < 12; tick += 1) {
      await page.mouse.wheel(0, 1200);
      // A pause between ticks; a burst with no gaps is not how a person scrolls
      await page.waitForTimeout(100 + Math.floor(Math.random() * 120));
    }

    // Give the request it triggered time to land
    await page.waitForTimeout(1500 + Math.floor(Math.random() * 900));

    if (reviewsById.size === previousCount) {
      idleRounds += 1;
    } else {
      idleRounds = 0;
      previousCount = reviewsById.size;
    }

    if (page.url().includes('showcaptcha')) {
      fail(EXIT_CAPTCHA, 'A captcha appeared while scrolling');
    }
  }

  const reviews = [...reviewsById.values()].slice(0, maxReviews);

  // Fall back to the card's own figure: declaredCount is only populated by an
  // intercepted response, and a card small enough to render in one go never
  // issues one. Without this a partial result reports itself as complete.
  const expected = declaredCount ?? organization.reviewsCount ?? null;
  const truncated = expected !== null && reviews.length < expected;

  process.stdout.write(
    JSON.stringify({
      organization,
      reviews,
      pagesFetched,
      truncated,
      truncationReason: truncated ? 'source_depth_limit' : null,
    }),
  );

  process.exitCode = EXIT_OK;
} catch (error) {
  fail(EXIT_GENERIC, error?.message ?? String(error));
} finally {
  await browser.close().catch(() => {});
}
