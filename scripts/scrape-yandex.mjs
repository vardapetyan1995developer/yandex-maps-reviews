#!/usr/bin/env node
/**
 * Fallback strategy for collecting reviews through a real browser.
 *
 * Invoked from HeadlessBrowserStrategy and used only when the primary, fast
 * path (parsing the internal JSON API) has stopped working because Yandex
 * changed the contract.
 *
 * The essential point: this script does not parse markup and never reads the
 * review DOM. It opens the page, scrolls the feed and intercepts the very same
 * fetchReviews responses that Yandex's own frontend requests. That is why it
 * survives both CSS class renames and changes to the request-signing algorithm —
 * the browser signs them, not us.
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

  // Organization data comes from the same initial state the fast path uses
  organization = await page.evaluate((id) => {
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
      externalId: String(item.id ?? id),
      name: item.title ?? item.shortTitle ?? '',
      address: item.fullAddress ?? item.address ?? null,
      rating: item.ratingData?.ratingValue ?? null,
      ratingsCount: item.ratingData?.ratingCount ?? 0,
      reviewsCount: item.ratingData?.reviewCount ?? 0,
      categories: (item.categories ?? []).map((category) => category?.name).filter(Boolean),
    };
  }, businessId);

  if (!organization) {
    fail(EXIT_UNEXPECTED_PAGE, 'No initial state with an organization card found on the page');
  }

  // Scroll the feed: more items load as we approach the end of the list.
  // Stop once the limit is reached, or when several consecutive attempts bring
  // back no new reviews.
  let idleRounds = 0;
  let previousCount = 0;

  while (reviewsById.size < maxReviews && idleRounds < 4) {
    await page.evaluate(() => {
      const scrollable = document.querySelector('.scroll__container') ?? document.scrollingElement;
      scrollable?.scrollBy(0, 4000);
      window.scrollBy(0, 4000);
    });

    // Jittered pause: perfectly even scrolling is a noticeable bot signal
    await page.waitForTimeout(700 + Math.floor(Math.random() * 900));

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
  const truncated = declaredCount !== null && reviews.length < declaredCount;

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
