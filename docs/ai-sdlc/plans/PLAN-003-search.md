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
| `public/index.php` | Thin redirect to `search.php` (no logic, keeps `/Diwan` usable) |
| `public/search.php` | GET form (city, date, shift_id) + result cards |
| `includes/functions.php` *(modify)* | Add `searchHubs()`, `getAvailability()`, `listCities()` |

## Functions to add

```
searchHubs(city, date, shiftId)
  date defaults to today(); date < CURDATE() => validation error
  SELECT h.id, h.name, b.city, b.electricity_status, b.internet_strength,
         b.generator_solar, b.price_per_hour
  FROM hubs h
  JOIN hub_subscriptions s ON s.hub_id = h.id          -- INV-03 gate
  JOIN branches b         ON b.hub_id = h.id AND b.active = 1
  WHERE h.status = 'active'
    AND s.end_date >= CURDATE()
    AND (:city IS NULL OR b.city = :city)
    AND NOT EXISTS (SELECT 1 FROM emergency_closures ec
                    WHERE ec.branch_id = b.id AND ec.close_date = :date)  -- SPEC-006
  ORDER BY b.city, h.name
```
Uses `subscriptionOk()`'s predicate inlined in the JOIN — same condition, one query, no N+1.
`shift_id` is optional here; the shift select is populated by `listCities()`/`listShifts()`.

```
getAvailability(hubId, branchId, date, shiftId): array{total, booked, available}
  total   = SELECT COUNT(*) FROM seats WHERE branch_id=? AND active=1
  booked  = SELECT COUNT(*) FROM bookings
           WHERE branch_id=? AND booking_date=? AND shift_id=?
             AND status IN ('pending','confirmed')
  available = total - booked
```
`pending` counts as taken — a pending seat is still held for the client. This is the availability twin of INV-05 and deliberately uses a different numerator (INV-05's occupancy counts `confirmed,attended`); both are spec-locked.

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
| hubA active/valid + hubB expired → hubA only | Seed both in one city, `GET /public/search.php?city=<city>` → exactly 1 card, hubB absent | ☐ |
| 10 seats, 4 booked confirmed/attended → `6 متاح` | Seed, load card → text `6 متاح` | ☐ |
| `date < TODAY` → validation error | `GET /public/search.php?date=2020-01-01` → validation message, 0 results | ☐ |
| Emergency-closed date → `مغلق طارئ` | Insert an `emergency_closures` row for the date → card carries the `مغلق طارئ` badge | ☐ |
| Pending hub / rejected / suspended hidden | Set each status in turn → 0 cards | ☐ |
| 0 available → `مكتمل` but still listed | Book out all seats → card present, text `مكتمل` | ☐ |
| Shift select only shows that hub's shifts | Load city, assert `shift_id` options match `SELECT ... FROM shifts WHERE branch_id IN (...)` | ☐ |
| No SQL in presentation | `grep -i "SELECT\|INSERT\|UPDATE\|DELETE" public/search.php` → no matches | ☐ |
| All output escaped | Register a hub named `<script>alert(1)</script>`, confirm it renders as text | ☐ |
| RTL, mobile-first, <1.5MB | Inspect `lang="ar" dir="rtl"`, no custom CSS outside `assets/css/style.css`, measure transfer size | ☐ |
| No AJAX required | Disable JS → filters still work | ☐ |
| `php -l` | `php -l public/search.php` | ☐ |
