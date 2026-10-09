# Intent: Diwan - Guaranteed Workspace Booking in Gaza

Author's own words:

A single trusted place to book a guaranteed workspace with electricity and internet in Gaza, instead of random searching on WhatsApp.

For customer (student, freelancer, remote employee):
- Knows before heading out: available seats today? electricity and internet working? price?
- Books in under a minute from mobile and pays cash upon arrival.
- Real value: saving trip and transportation to a place that is full or power outage.

For space owner:
- Replaces paper notebook and WhatsApp with single dashboard: bookings, occupancy, attendance, daily income.
- Visibility to new customers in exchange for fixed monthly subscription.

For platform owner:
- Recurring income from subscriptions.

Primary: Freelancers (programming, design, translation) + University/Tawjihi students, especially displaced.
Secondary: Small teams 2-5 needing hourly room, orgs for one-day training, owners as B2B subscribers.

MVP is mobile-responsive web only, no native apps. PHP 8 native + MySQL + Bootstrap 5 RTL on shared cPanel. Pages <1.5MB. Cash only. Zero paid integrations.

MVP = 6 things only: hub approval, branch+seats+2 shifts, search by city/date/shift, book/cancel by mobile, check-in + cash record, daily report + emergency close.

Golden rule: If feature does not directly serve daily booking of a seat in Gaza, it is not MVP.

### Corrections locked pre-implementation (2026-10-08)
1. Subscription expiry enforcement: Logic Layer must check `hub_subscriptions.end_date >= TODAY AND hubs.status='active'` before (a) listing hub in search and (b) accepting new booking. Expired = hidden + reject with "اشتراك المساحة منتهي".
2. Double-booking prevention: No partial UNIQUE in MySQL. Must use PDO Transaction + `SELECT ... FOR UPDATE` check for same seat_id+booking_date+shift in (pending,confirmed) before INSERT. Commit only if free. Race in same second blocked.
3. Occupancy definition fixed: `occupancy = confirmed_booked_seats / total_active_seats` for same branch_id + date + shift. Numerator: COUNT bookings status in (confirmed,attended) ; Denominator: COUNT seats WHERE active=1. No other formula allowed.

## Open questions
- Subscription price tiers for hub owners?
- Who approves reviews for abuse?
- Waiting list: automatic promote or manual?
- Exact shift times fixed or per-hub configurable?

Status: approved
