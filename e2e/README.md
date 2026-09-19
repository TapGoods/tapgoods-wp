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
categories, one sub-category (which becomes the `tag-round-tables` tag). That is
what lets a spec assert "one result" instead of "something rendered". A browser
suite pointed at a live storefront fails for reasons unrelated to the change, and
then people stop believing it.

`seed.php` is idempotent, so re-run it whenever you want a clean catalog.

## Two things that will surprise you

**Permalinks are pretty, and `.htaccess` is mounted, not generated.** This file
used to say the opposite: that the web container was nginx with no `try_files`
rule, so every pretty permalink 404'd and specs had to address pages as
`/?page_id=N`. wp-env serves WordPress through Apache, so that is no longer true —
but it does need a `.htaccess`. `e2e/htaccess` is mapped onto the WordPress root
by `.wp-env.json`, and `seed.php` sets `/%postname%/` and then fails loudly if that
file has no `RewriteRule` in it, because otherwise every spec fails on a 404 far
from the cause.

Mounted rather than written by the seed, and that distinction cost a CI run:
`save_mod_rewrite_rules()` succeeds on macOS, where the bind mount ignores
ownership, and fails on Linux CI, where `/var/www/html` is root-owned and the
wp-cli container runs as the host user. A mounted file needs no write permission
anywhere. **If you have an environment from before this mapping existed, run
`npx wp-env destroy && npx wp-env start`** — a running container will not pick up
a new mapping.

This is not cosmetic. WPB-166 could not be reproduced on plain permalinks at all:
the old code parsed the tag slug out of the URL path, found nothing there, and the
grid's own query var quietly filtered the page correctly. A suite pinned to plain
permalinks passed the entire time the bug was open. `tests/helpers.js` therefore
resolves each page's real `link` through `?rest_route=`, and specs address tags as
`/tags/<slug>/`, the shape a customer site serves.

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
