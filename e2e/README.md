# Browser suite

The half of `QA-CHECKLIST.md` that only a browser can answer: what a visitor sees
and clicks. Everything else belongs in the PHP suites, which are faster and do not
need a browser.

```bash
npx wp-env start      # boot WordPress
npm run e2e:seed      # sync the fixture catalog and create the pages
npm run e2e           # run the suite
npm run e2e:ui        # or drive it interactively
```

CI runs exactly this, in `.github/workflows/e2e.yml`.

## Why it points at wp-env and not at a staging site

The plugin here is pinned to the offline mock API (`TG_MOCK` in `.wp-env.json`), so
the catalog is the same fixture set the PHP suites use: two known items, two
categories, one sub-category. That is what lets a spec assert "one result" instead
of "something rendered". A browser suite pointed at a live storefront fails for
reasons unrelated to the change, and then people stop believing it.

`seed.php` is idempotent, so re-run it whenever you want a clean catalog.

## Two things that will surprise you

**Permalinks are plain.** The wp-env web container is nginx with no `try_files`
rule, so every pretty permalink returns a bare 404 before WordPress sees the
request. `/sample-page/` 404s too, so it is the environment and not the plugin.
Specs therefore address pages as `/?page_id=N`, resolved by slug through
`?rest_route=` in `tests/helpers.js`, and the pretty REST route is unavailable for
the same reason. Anything that genuinely needs pretty URLs
(`tapgrein_parse_request()` routing) stays in the PHP integration suite, which runs
inside WordPress and does not care about the web server.

**Two projects, one engine.** `desktop` and `mobile` both run Chromium; the mobile
project is a Chromium phone profile. The only viewport-dependent contract we have
is the categories accordion (WPB-179), which is about width, not about a rendering
engine, so pulling WebKit for it would buy nothing. Specs that are not
viewport-dependent skip themselves outside `desktop`, which is why a normal run
reports as many skips as passes.

## Where the accordion contract is actually enforced

Split deliberately, because the bug had two halves:

- **This suite** checks the visitor-facing result: closed on a phone, open on a
  desktop, and still tappable open on a phone.
- **`CategoryFilterMarkupTest`** (PHP) checks the property that made the first fix
  fail in production: the markup is byte-identical for both, so a page cache with
  no device variance cannot serve the wrong one. A browser cannot see that, because
  locally there is no cache in front of it.
