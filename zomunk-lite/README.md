zomunk-lite
===========

A working, basic version of what [Zomunk](https://zomunk.com) does: watch flight
fares out of Indian airports, keep only the ones far below what a route normally
costs, and email them to members — free members on a delay, premium members
straight away.

Real fares come from the Amadeus Self-Service API. Notifications go out through
[Sender](https://www.sender.net). Storage is a PDO database (SQLite by default,
MySQL supported). PHP 8.1+, no Composer dependencies.

---

## How Zomunk works

From Zomunk's own site and help centre, the model is:

1. They continuously monitor fares from major Indian airports, watching for price
   drops, mistake fares and rare discounts.
2. A deal is only posted when it is **at least 40% below the route's usual fare**;
   many run to 70–90% off.
3. They **hand-pick** what gets posted: non-stop or one stop with a short layover,
   no self-transfers, no itineraries needing a transit visa, and most deals include
   checked luggage.
4. A found deal is pushed to the member's **dashboard** and sent by **email**.
5. **Free members** see a limited selection of economy deals. **Premium members**
   see everything — mistake fares, peak-season drops, premium cabins.
6. They are **not a booking platform**. You book through Google Flights, the
   airline, or an OTA — and fares can vanish mid-booking.

Sources: [zomunk.com](https://zomunk.com/), [FAQ](https://web.zomunk.com/faq),
[What makes Zomunk unique](https://help.zomunk.com/en/article/what-makes-zomunk-unique-107slxw/),
[How it works](https://web.zomunk.com/start/get-notified).

## What this build reproduces

| Zomunk | zomunk-lite |
| --- | --- |
| Continuous fare monitoring from Indian airports | `bin/scan.php` on a cron, over a configurable watchlist (`config/routes.php`) |
| "At least 40% below the usual fare" | `DealEngine`, threshold in `ZOMUNK_MIN_DISCOUNT`, measured against a **median of observed history** per route and departure month |
| Hand-picked itineraries | `DealEngine::inspectItinerary()` — stop count, layover window, total duration, checked bag, transit visa |
| Mistake fares / premium-only deals | discount ≥ `ZOMUNK_PREMIUM_DISCOUNT`, or any non-economy cabin |
| Free tier sees a delayed subset | `publish_free_at` = found + `ZOMUNK_FREE_DELAY_HOURS` |
| Deal dashboard | `public/index.php`, gated by the viewer's tier |
| Email alerts | `bin/notify.php` → a Sender campaign per tier |
| Not a booking platform | every deal links out to Google Flights |

Not built (deliberately out of scope for a basic version): payments and real
premium billing, a proper login (tier is decided by a signup cookie), mobile push,
and per-member origin-airport targeting — the watchlist is global and each tier
gets one digest.

## How it works here

```
config/routes.php        the watchlist: routes, seed fares, how far ahead to look
        |
bin/scan.php  ─────────► provider (Amadeus | sample)
        |                    |
        |                    └─ offers for each route × date pair
        |
        ├─ inspectItinerary()  drop anything a person would not want to fly
        ├─ fare_history        record the cheapest bookable fare  ← this is the baseline
        ├─ typicalFareFor()    median of that history (this month → this route → seed)
        ├─ evaluate()          discount ≥ 40%?  mistake fare?  which tier?
        └─ deals table         best deal per route+month, deduped against past alerts
                |
bin/notify.php ─┴──────► Sender campaign per tier ──► members' inboxes
                |
public/index.php ──────► dashboard, gated free vs premium
```

### The baseline is the whole trick

"40% off" is meaningless without knowing what a route normally costs. Every scan
writes the cheapest *bookable* fare it saw for a route into `fare_history`, and the
typical fare is the **median** of that history — median, not mean, so one mistake
fare in the history cannot drag the baseline down and hide the next one. It prefers
same-month history (a July fare judged against July), falls back to the whole route,
and only then to the `typical_fare_inr` seed in `config/routes.php`.

Until a route has `ZOMUNK_MIN_HISTORY_POINTS` observations it runs on the seed, and
deals found that way are labelled *seed estimate* in the UI. Give it a week of scans
before trusting the percentages.

## Setup

```bash
cd zomunk-lite
cp .env.example .env          # then edit it
php bin/init-db.php           # creates the schema, loads the watchlist
php bin/scan.php --months=2   # ZOMUNK_PROVIDER=sample by default: no keys needed
php bin/notify.php --dry-run  # writes var/preview-<tier>.html instead of sending
php -S localhost:8000 -t public
```

`ZOMUNK_PROVIDER=sample` runs the entire pipeline offline against synthetic fares,
including itineraries the filters are supposed to reject. Use it to see the shape
of the thing before signing up for anything.

### Real fares (Amadeus)

1. Register at [developers.amadeus.com](https://developers.amadeus.com/register) and
   create an app — you get a client id and secret.
2. Put them in `.env` and set `ZOMUNK_PROVIDER=amadeus`.
3. `AMADEUS_ENV=test` (the default) is the free tier: real API, cached fares, a
   monthly call quota. `AMADEUS_ENV=production` bills per call and returns live
   pricing.

The scan spends one API call per route × departure date × trip length. The default
watchlist (17 routes, 6 months, 2 dates/month, 2 trip lengths) is ~400 calls per
pass — far too many for a free quota on a 4-hourly cron. Trim it with
`--months=2 --max-requests=60`, or shrink `dates_per_month` in `config/routes.php`.

### Notifications (Sender)

1. In Sender: **Settings → API access tokens**, create a token, put it in
   `SENDER_API_TOKEN`.
2. `php bin/sender-check.php --create` — verifies the token, creates the
   *Zomunk Lite - Free* and *Zomunk Lite - Premium* groups if missing, and prints the
   `SENDER_GROUP_FREE` / `SENDER_GROUP_PREMIUM` lines to paste into `.env`.
3. `php bin/notify.php` then creates one campaign per tier and sends it to that group.

Signups go through `public/subscribe.php`, which writes the member locally **first**
and then mirrors them into the Sender group, so a Sender outage cannot lose a signup;
`bin/resync-subscribers.php` retries the ones that failed.

> The endpoint paths are constants at the top of `src/Notifier/SenderClient.php`
> (`/v2/subscribers`, `/v2/subscribers/groups/{id}`, `/v2/campaigns`,
> `/v2/campaigns/{id}/send`). `bin/sender-check.php` is the fastest way to confirm
> they match your account before you rely on a cron — it fails loudly with Sender's
> own response body if a path or the token is wrong.

Sender appends the unsubscribe footer to every campaign, so the template does not
add one.

### Cron

```cron
 0 0,4,8,12,16,20 * * *  php /path/to/zomunk-lite/bin/scan.php --months=3 --max-requests=80
30 0,4,8,12,16,20 * * *  php /path/to/zomunk-lite/bin/notify.php
15 3             * * *   php /path/to/zomunk-lite/bin/resync-subscribers.php
```

## The deal rules

All of it is `.env`, all of it is tested in `tests/run-tests.php`.

| Rule | Setting | Default |
| --- | --- | --- |
| Minimum discount below typical | `ZOMUNK_MIN_DISCOUNT` | 0.40 |
| Mistake fare / premium-only above | `ZOMUNK_PREMIUM_DISCOUNT` | 0.70 |
| Free tier head start for premium | `ZOMUNK_FREE_DELAY_HOURS` | 24 |
| Maximum stops | `ZOMUNK_MAX_STOPS` | 1 |
| Layover window | `ZOMUNK_MIN/MAX_LAYOVER_MINUTES` | 45–300 min |
| Checked bag required | `ZOMUNK_REQUIRE_CHECKED_BAG` | on |
| Maximum trip duration | per route in `config/routes.php` | 26 h |
| Transit visa filter | `ZOMUNK_PASSPORT` + `config/transit_visa.php` | Indian passport |
| Re-alert only on a further drop | `ZOMUNK_REALERT_DROP` / `_DAYS` | 10% / 14 days |

Two deliberate choices worth knowing:

- **An unknown connecting airport is flagged, never dropped.** The airport→country
  table in `config/transit_visa.php` is small; an incomplete lookup must not silently
  eat real deals.
- **One deal per route, month and cabin per scan.** Without it a single scan writes
  five near-identical London deals and the digest becomes noise.

To watch a different set of routes, edit `config/routes.php` and re-run
`bin/init-db.php`. Routes you remove stop being scanned but keep their price history.

## Tests

```bash
php tests/run-tests.php     # 38 tests, no network, in-memory SQLite
```

Covers the discount maths, the median baseline, every itinerary filter, tier
assignment, alert suppression, the free-tier delay, tier gating on the dashboard
queries, and HTML escaping in the email.

## Layout

```
bin/       scan, notify, init-db, sender-check, resync-subscribers
config/    routes watchlist, transit visa table, env loading
public/    dashboard, deal page, signup handler
src/       DealEngine (the rules), Scanner, DealRepository, providers, Sender client
tests/     dependency-free test runner
```

## Caveats

- **Fares move.** A deal can be gone before the email lands. That is inherent to the
  model, and why the deal page says so.
- **The free Amadeus tier serves cached fares.** Good enough to build and test
  against; switch to production before promising anyone a live price.
- **Tier is a cookie, not a login.** Put real authentication in front of
  `public/` before charging for premium.
- **Check the terms you are working under.** Amadeus and Sender both have usage
  terms and rate limits, and scraping an airline or OTA site directly instead of
  using a licensed fare API is a different thing legally — this project only talks
  to APIs that offer a documented free tier.
- **`.env` and `var/` are gitignored.** Keep API tokens out of the repository.
