# GOMEZ GARAGE STUDIO — ROADMAP
Last updated: August 2026

## STATUS: LIVE ✅
Site live at gomezgarage.eu/studio
Revolut Merchant API integrated (embedded checkout, all payment methods)
Admin panel operational
MySQL database with orders and products

## PHASE 2A — Pre-launch (before first real customer)
Priority: HIGH — must be done before promoting the site

- [ ] Upload real logo files (Gomez Garage + GGS3D) and favicon
- [ ] Replace seed products with real products + real photos
- [ ] Make phone number required (not optional) at checkout
- [ ] GDPR cookie consent banner
- [ ] Terms & Conditions page (/terms.html)
- [ ] Privacy Policy page (/privacy.html)
- [ ] Rewrite About page with real brand story + workshop photos
- [ ] Fix admin panel password change

## PHASE 2B — First orders
Priority: MEDIUM — implement when first orders start coming in

- [ ] User accounts (register, login, profile)
- [ ] Order history in user account
- [ ] Order on behalf of company (PJ) — CUI, company name, address
- [ ] CUI validation — Romanian format (8-10 digits, optional RO prefix for VAT payers)
- [ ] PDF invoice generation per order (PF and PJ)
  - Lancer Land SRL is NOT VAT registered — no TVA on invoices
  - Auto-increment invoice series (GGS-2026-0001)
  - Required legal mentions for Romanian invoices (verify with accountant)
- [ ] Invoice available in order history and sent via email
- [ ] Order tracking — manual AWB entry in admin panel
- [ ] Automatic email to customer when AWB is added

## PHASE 3 — Scale
Priority: LOW — after consistent revenue

- [ ] Courier API integration (Fan Courier / Cargus / Sameday)
- [ ] TikTok Shop integration
- [ ] Analytics — Plausible (privacy-first, GDPR compliant)
- [ ] Email marketing — Brevo newsletter
- [ ] Product reviews system
- [ ] Wishlist
- [ ] Blog / build log

## KNOWN BUGS & IMPROVEMENTS
- [ ] Currency rate in settings should pull from live ECB feed (currently manual)
- [ ] Sitemap.xml should auto-update when products are added
- [ ] robots.txt — verify all sensitive paths are excluded

## NOTES

- vendor/ is gitignored — run composer install on server after deploy
- Admin default credentials were changed on first login
