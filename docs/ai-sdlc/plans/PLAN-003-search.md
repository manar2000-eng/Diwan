# PLAN-003 Search by City / Date / Shift

Spec: `docs/ai-sdlc/specs/SPEC-003-search.md` (approved)
Planner: `.opencode/agents/architect.md`
Depends on: PLAN-000 (layout, `subscriptionOk()`), PLAN-001 (active hubs), PLAN-002 (branches/seats/shifts to count)

## Starting state
Hubs approved and subscribed, each with 1 branch, seats and shifts. There is **no public entry point yet** — `/public/index.php` does not exist. `public/register-hub.php` and `public/login.php` are the only public pages.

## Smallest change
Two pages (`index.php` redirect + `search.php`) and two functions. **No JavaScript at all** — the filters are a plain GET `<form>`, so filtering works on any phone with JS disabled and costs nothing to build. No image upload is added: hub cards render a CSS placeholder block instead, which is what keeps the page under 1.5MB on Gaza bandwidth (INV-06). Photo upload is deferred, not planned.

## Files to create

| File | Purpose |
|------|---------|
| `public/index.php` | Thin redirect to `search.php` (no logic, keeps `/Diwan/public/` usable) |
| `public/search.php` | GET form (city, date, shift_id) + result cards |
| `includes/functions.php` *(modify)* | Add `searchHubs()`, `getAvailability()`, `listCities()`, `listSearchShifts()`, `branchOwnsShift()`, `searchOptionLabels()`, `formatTime()` |
| `public/login.php` *(modify)* | Query moved to `findUserForLogin()`; it was the only presentation file still holding SQL |
| `assets/css/style.css` *(modify)* | `.hub-photo-placeholder` + `.hub-card-meta` |

## Functions to add

```
searchHubs(city, date, shiftId)
  date defaults to today(); date < CURDATE() => validation error
  SELECT h.id, h.name, b.city, b.electricity_status, b.internet_strength,
         b.generator_solar, b.price_per_hour
  FROM hubs h
  LEFT JOIN branches b     ON b.hub_id = h.id AND b.active = 1
  LEFT JOIN emergency_closures ec ON ec.branch_id = b.id AND ec.close_date = :date
  WHERE h.status = 'active'
    AND EXISTS (SELECT 1 FROM hub_subscriptions s
                 WHERE s.hub_id = h.id AND s.end_date >= CURDATE())      -- INV-03 gate
    AND (:city IS NULL OR b.city = :city)
  ORDER BY b.city, h.name
```
`EXISTS` instead of `JOIN hub_subscriptions`: an `EXISTS` keeps the gate to one row
per hub when a hub has several subscription rows, which a JOIN would duplicate.
Same predicate `subscriptionOk()` reads.

Emergency closure does **not** remove the row. The plan's `NOT EXISTS` would make
the `مغلق طارئ` badge unreachable, and SPEC-003 + SPEC-006 acceptance both require
that badge on the card. So a closed branch is returned in a `closed` list and
rendered as a badge-only card — visible, not bookable. Availability and the
booking count are computed only for the `open` list.

`shift_id` is optional; a hub whose branch does not own the shift is filtered out
(`branchOwnsShift()`), which is the cross-hub guard at the search layer.

```
getAvailability(hubId, branchId, date, shiftId): array{total, booked, available}
  total   = SELECT COUNT(*) FROM seats WHERE branch_id=? AND active=1
  booked  = SELECT COUNT(*) FROM bookings
           WHERE branch_id=? AND booking_date=? AND shift_id=?
             AND status IN ('pending','confirmed','attended')
  available = total - booked
```
Every status that still holds a seat counts as taken: a `pending` seat is held for the client, and an `attended` seat was already used that day. The plan originally listed `pending,confirmed` only, which contradicts its own acceptance row ("10 seats, 4 booked confirmed/attended → `6 متاح`"); the acceptance wins. INV-05 occupancy stays a separate ratio in PLAN-006 (`confirmed,attended` only) and must not be substituted for this numerator.

```
listCities()  -> SELECT DISTINCT city FROM branches WHERE active=1 ORDER BY city
```

## Page structure (`public/search.php`)
1. GET form: `city` (select), `date` (`type="date"`, min = today), `shift_id` (select of that hub's shifts).
2. Result cards, one per hub: name, city, **available seats** (`6 متاح` / `مكتمل` when 0), `electricity_status`, `internet_strength`, `generator_solar`, `price_per_hour`, and a `مغلق طارئ` badge when the branch is closed that date.
3. No login required. Every value through `e()`.

If `available = 0` the card **still renders** with `مكتمل` — the client should see the hub exists and is busy. Only emergency closure removes it. This is spec-locked behaviour.

## Steps
1. Add the 3 functions to `includes/functions.php`.
2. `public/index.php` redirect.
3. `public/search.php` — form, then cards calling `getAvailability()` per row.
4. Seed hubA active/valid and hubB expired in the same city; confirm only hubA renders.
5. Seed 10 seats with 4 booked; confirm `6 متاح`.
6. Lint + run tests.

## Reuse
- `subscriptionOk()` predicate from PLAN-000, `pageStart/pageEnd` for RTL (INV-06 satisfied without writing `dir="rtl"` here).
- `branches.electricity_status / internet_strength / generator_solar / price_per_hour` already collected by PLAN-002 — no new fields.
- `isValidDate()` and `e()` from PLAN-000.
- Bootstrap 5 RTL CDN already referenced by `layout.php` — no second CDN include.

## Out of scope
Map, distance/sorting, waiting list, reviews display, photo upload/gallery, AJAX live availability, pagination (a city list of hubs is small), autocomplete, filters beyond city/date/shift.

## Rollback note
```
rm public/search.php public/index.php
git checkout includes/functions.php     # drops the 3 added functions
```
No data changes, nothing else depends on search yet.

## Tests → acceptance mapping

| Spec acceptance | Verification | Result |
|---|---|---|
| hubA active/valid + hubB expired → hubA only | Seed both in one city, `GET /public/search.php?city=<city>` → exactly 1 card, hubB absent | ✅ hubA rendered, hubB (expired) absent, hubC (other city) absent; also checked with no filter |
| 10 seats, 4 booked confirmed/attended → `6 متاح` | Seed, load card → text `6 متاح` | ✅ `total=10, booked=4, available=6`, card shows `6 متاح`; INV-05 numerator still counts only `confirmed,attended` (3), so the two formulas stay distinct |
| `date < TODAY` → validation error | `GET /public/search.php?date=2020-01-01` → validation message, 0 results | ✅ `لا يمكن الحجز في تاريخ سابق`; also `2026-02-31` and `not-a-date` → `تاريخ غير صالح` |
| Emergency-closed date → `مغلق طارئ` | Insert an `emergency_closures` row for the date → card carries the `مغلق طارئ` badge | ✅ badge present, closed card shows no availability badge and no booking button, other hubs and other dates unaffected |
| Pending hub / rejected / suspended hidden | Set each status in turn → 0 cards | ✅ all three hidden; `active` alone does not revive an expired hub, and renewing the subscription does |
| 0 available → `مكتمل` but still listed | Book out all seats → card present, text `مكتمل` | ✅ all 10 booked → card present with `مكتمل`, never `0 متاح` |
| Shift select only shows that hub's shifts | Load city, assert `shift_id` options match `SELECT ... FROM shifts WHERE branch_id IN (...)` | ✅ city's option ids match exactly; another city shows only its own; a shift filter keeps only the hub owning it; unknown `shift_id` → empty, no error |
| No SQL in presentation | `grep -i "SELECT\|INSERT\|UPDATE\|DELETE" public/search.php` → no matches | ✅ none of the 11 PHP files hold SQL or a PDO call; `login.php` was the last holdout and moved to `findUserForLogin()` |
| All output escaped | Register a hub named `<script>alert(1)</script>`, confirm it renders as text | ✅ renders `&lt;script&gt;`; `"quotes"`, `&`, `<b>` all escaped |
| RTL, mobile-first, <1.5MB | Inspect `lang="ar" dir="rtl"`, no custom CSS outside `assets/css/style.css`, measure transfer size | ✅ search page 4286 B with the seeded hub, RTL shell, single CDN link, photo is a CSS block |
| No AJAX required | Disable JS → filters still work | ✅ `method="get"`, no `fetch(`/`XMLHttpRequest`, no `<script>` in the page |
| `php -l` | `php -l public/search.php` | ✅ 15 PHP files, 0 errors |

Also verified: `public/index.php` 302 → `search.php`; `min=today` on the date input; empty-state text; unknown city yields "لا توجد مساحات مطابقة" with no error; card renders electricity / internet / generator-solar / price; `listCities()` drops the city of a pending hub.

Total: 87 checks for PLAN-003, and PLAN-000 (34), PLAN-001 (65), PLAN-002 (95) plus the rollback probe (11) still pass.

## Deviations and bug
1. **`getAvailability()` numerator.** The plan listed `pending,confirmed`, but its own acceptance row requires `6 متاح` from "4 booked confirmed/attended", which is only reachable with `attended` included. Availability now counts `pending,confirmed,attended` — any status that still holds a seat. INV-05 occupancy stays separate in PLAN-006.
2. **Emergency closure no longer filters the row out.** The plan's `NOT EXISTS` contradicts the `مغلق طارق` acceptance row in both SPEC-003 and SPEC-006. Closed branches are returned separately and rendered as badge-only cards.
3. **`EXISTS` instead of `JOIN hub_subscriptions`**, so a hub with more than one subscription row cannot produce duplicate cards.
4. **`login.php` had SQL** — it was the only presentation file violating the three-layer contract. Moved to `findUserForLogin()`.
5. **Book button is disabled.** The booking target is PLAN-004's `hub.php`; rendering a link to a file that does not exist yet would be a broken link.
6. `/Diwan` still lists the directory locally, because the WAMP docroot is the repo root and `index.php` lives in `public/`. The public entry point is `http://localhost/Diwan/public/`.
