# GOMEZGARAGE.EU/STUDIO — ARCHITECTURE DOCUMENT
### Version 1.0 | Gomez Group / Lancer Land SRL
### Data: August 2026

---

## 1. VIZIUNE & POSITONING

**Ce este site-ul:**
Brand premium de produse manufacturate — nu un magazin care are si brand. Povestea si procesul sunt in prim plan. Produsele sunt rezultatul.

**Referinte vizuale:**
Nomad, Peak Design, Grovemade — premium industrial, nu luxury, nu corporate.

**Relatia cu gomezgarage.eu:**
Studio are identitate vizuala proprie dar traieste sub umbrela gomezgarage.eu.
- gomezgarage.eu = hub brand (toate proiectele: Studio, SpoilerX, The Build, CoreX)
- gomezgarage.eu/studio = shopul / brandul Studio

---

## 2. IDENTITATE VIZUALA

**Paleta de culori:**
- Background principal: `#0a0a0a` (negru aproape pur)
- Accent primar: `#E63B2E` (rosu industrial)
- Text principal: `#FFFFFF`
- Text secundar: `#888888`
- Suprafete/carduri: `#111111` sau `#1a1a1a`
- Border subtil: `#222222`

**Tipografie:**
- Headings: `Rajdhani` sau `Bebas Neue` (Google Fonts) — bold, industrial
- Body: `Inter` — curat, lizibil
- Accent/label: `Space Mono` — pentru detalii tehnice, materiale, specs

**Stil general:**
- Dark industrial premium
- Carbon fiber texture accents (CSS subtil, nu over-the-top)
- Spatiu alb (negru) generos — nu aglomerat
- Fotografii de produs pe fundal negru/dark
- Micro-animatii subtile (hover, scroll reveal) — nu flashy

**Logo:**
- Logo Gomez Garage existent (masina sport, negru/alb/rosu)
- Logo GGS3D (patrat gri inchis cu text alb) — folosit in hero Studio

---

## 3. ARHITECTURA SITE — PAGINI & STRUCTURA

```
gomezgarage.eu/studio/
├── index.html              → Homepage
├── products.html           → Catalog produse
├── product.html            → Pagina individuala produs (template dinamic)
├── custom.html             → Custom Order
├── about.html              → About brand (link din homepage)
├── cart.html               → Cos de cumparaturi
├── checkout.html           → Checkout + adresa + nota
├── order-success.html      → Confirmare comanda
├── order-failed.html       → Plata esuata
│
├── admin/
│   ├── login.php           → Autentificare admin
│   ├── index.php           → Dashboard (comenzi, revenue, stoc alert)
│   ├── products.php        → CRUD produse
│   ├── product-edit.php    → Adauga/editeaza produs
│   ├── orders.php          → Lista comenzi cu statusuri
│   ├── order-detail.php    → Detaliu comanda individuala
│   └── settings.php        → Setari (prag stoc, email notificari, moneda)
│
├── api/
│   ├── create-order.php    → Creeaza order Revolut + salveaza in DB
│   ├── webhook.php         → Revolut webhook — confirma plata
│   ├── get-products.php    → Returneaza produse din DB (JSON)
│   └── get-rate.php        → Exchange rate RON/EUR (fallback hardcodat)
│
└── assets/
    ├── css/
    │   ├── main.css        → Stiluri globale, variabile CSS, reset
    │   ├── shop.css        → Stiluri specifice shop (grid, cards, checkout)
    │   └── admin.css       → Stiluri admin panel
    ├── js/
    │   ├── main.js         → Nav, lang toggle, currency toggle, utils
    │   ├── shop.js         → Cart logic, add/remove, localStorage
    │   ├── checkout.js     → Checkout flow, Revolut Widget init
    │   └── currency.js     → RON/EUR switch, geo-detect, formatare
    └── images/
        ├── products/       → Imagini produse (upload via admin)
        ├── logo-gomez.jpg
        ├── logo-ggs3d.png
        └── favicon.png
```

---

## 4. PAGINI — DETALIU CONTINUT

### 4.1 Homepage (index.html)

**Sectiuni in ordine:**

1. **NAV**
   - Logo stanga
   - Links: Products / Custom Order / About
   - Dreapta: toggle EN/RO | toggle RON/EUR | icon cos cu counter

2. **HERO**
   - Full-width, dark
   - Tagline mare: *"Precision made. Built to last."*
   - Sub-tagline: *"Premium 3D-printed objects with original designs, made in-house."*
   - CTA primar: "Shop Now" → /products
   - CTA secundar: "See how it's made" → scroll la sectiunea process
   - Background: fotografie produs flagship sau carbon texture

3. **FEATURED PRODUCTS** (3 carduri)
   - Primele 3 produse marcate ca "featured" in admin
   - Card: imagine, nume, material, pret, buton "View" + buton "Add to Cart"

4. **BRAND STORY** (sectiunea scurta)
   - 2-3 fraze despre cine esti si cum faci produsele
   - 3 iconite cu valori: "Designed from scratch" / "Printed in-house" / "Ships EU & UK"
   - Link: "More about the process" → about.html

5. **PROCESS TEASER** (3 pasi vizuali)
   - Design → Print → Deliver
   - Scurt, vizual, fara text mult

6. **CUSTOM ORDER CTA**
   - Banner dark cu accent rosu
   - "Need something specific? Let's build it."
   - Buton → /custom

7. **FOOTER**
   - Logo + tagline scurt
   - Links: Products / Custom / About / Contact
   - Social: Instagram / TikTok / YouTube
   - Legal: © 2026 Lancer Land SRL | gomezgarage.eu
   - Nu mentioneaza SpoilerX sau alte proiecte

---

### 4.2 Products Page (products.html)

- Grid responsive: 3 coloane desktop, 2 tablet, 1 mobile
- Fiecare card: imagine principala, hover → imagine secundara, nume, material badge, pret (RON sau EUR), buton "View" + "Add to Cart" direct
- Filter bar: All / Desk & Tech / Display & Collectibles / Home & Decor.
- Valorile legacy Automotive / Other raman valide in DB, admin si service API.
- Sort: Newest / Price low-high / Price high-low
- Produsele vin din API (get-products.php → MySQL)

---

### 4.3 Product Page (product.html?id=X)

- Imagine mare stanga (gallery cu thumbnail-uri daca sunt mai multe)
- Dreapta: nume, material badge, pret, descriere, specs tehnice (dimensiuni, greutate, compatibilitate)
- Sectiune "How it's made" — scurt paragraph + material highlight
- Buton "Add to Cart" + "Buy Now" (direct la checkout cu produsul asta)
- Stoc indicator: "In stock" / "Low stock (X left)" / "Out of stock"
- Related products (3 carduri) la final

---

### 4.4 Custom Order (custom.html)

- Hero scurt: "Built to your spec."
- Portofoliu de exemple (imagini produse existente + orice custom facut anterior)
- Sectiune "What I can make": materiale disponibile, tipuri de produse, limitari (dimensiuni max printer etc)
- Formular contact:
  - Nume
  - Email
  - Ce ai nevoie (textarea)
  - Buget aproximativ (optional, dropdown: <50 EUR / 50-150 EUR / 150+ EUR)
  - Submit → trimite email la contact@gomezgarage.eu
- Timeline asteptat: "Custom orders typically ship in 7-14 days"

---

### 4.5 Cart (cart.html)

- Lista produse adaugate: imagine mini, nume, pret, cantitate (+/-), remove
- Subtotal calculat live
- Nota: shipping calculat la checkout
- CTA: "Proceed to Checkout"
- Link: "Continue Shopping" → /products

---

### 4.6 Checkout (checkout.html)

**Pasul 1 — Date client:**
- Prenume + Nume
- Email
- Telefon (optional)
- Adresa livrare: strada, oras, cod postal, tara (dropdown EU+UK)
- Nota pentru comanda (textarea optional)

**Pasul 2 — Plata:**
- Revolut Checkout Widget embedded
- Afiseaza: sumar comanda + total cu shipping estimat
- Shipping: calculat simplu — Romania X RON, EU Y EUR, UK Z EUR (hardcodat initial)

---

### 4.7 Order Success / Failed

- Success: numar comanda, sumar, email confirmare trimis, "Back to shop"
- Failed: mesaj clar, buton "Try again" → inapoi la checkout

---

### 4.8 About (about.html)

- Povestea: cine esti, de ce faci asta, unde (Near Bucharest, Romania)
- Procesul de productie: CAD → Print → Post-process → Ship
- Echipamentul (fara brand neaparat — "industrial FDM printers with heated chambers")
- Materialele: ASA-CF, PETG, PLA-CF — ce inseamna fiecare pentru produsul final
- Fotografii: setup-ul, printere, carbon fiber desk (asta e hero content)

---

## 5. ADMIN PANEL — DETALIU

### 5.1 Login (admin/login.php)
- Username + parola (hash bcrypt in DB)
- Session-based auth
- Redirect la dashboard dupa login

### 5.2 Dashboard (admin/index.php)
- Revenue total luna curenta (RON)
- Numar comenzi: toate / platite / in procesare / expediate
- **Alerte stoc** (rosu, vizibile imediat) — produse sub pragul setat
- Ultimele 5 comenzi (tabel scurt cu link spre detaliu)

### 5.3 Products CRUD (admin/products.php + product-edit.php)
**Campuri per produs:**
- Nume (EN + RO)
- Descriere scurta (EN + RO)
- Descriere lunga / "How it's made" (EN + RO)
- Material (text liber + badge color)
- Categorie (dropdown: Desk & Tech / Display & Collectibles / Home & Decor; legacy Automotive / Other)
- Pret EUR (conversia RON se face automat); poate lipsi intr-un draft, dar trebuie sa fie pozitiv pentru publicare.
- Stoc curent (numar)
- Prag stoc minim (sub care apare alerta)
- Imagini (upload multiple, prima = principala)
- Status: Published / Draft
- Featured: Da / Nu (apare pe homepage)
- Specs tehnice (camp JSON sau tabel key-value: Dimensions, Weight, Compatibility)

### 5.4 Orders (admin/orders.php + order-detail.php)
**Status comenzi:**
- `pending` — order creat, plata in asteptare
- `paid` — confirmat de Revolut webhook
- `processing` — tu l-ai vazut, il pregatesti
- `shipped` — expediat (introduci tracking manual)
- `delivered` — livrat
- `cancelled` — anulat

**Detaliu comanda:**
- Date client complet
- Produse + cantitati + pret
- Nota clientului
- Status cu butoane de schimbare
- Camp tracking number (manual)
- Buton: trimite email update catre client

### 5.5 Settings (admin/settings.php)
- Email notificari comenzi noi (adresa ta)
- Email notificari stoc scazut
- Prag stoc global default
- Shipping rates: Romania / EU / UK (RON/EUR)
- Revolut API keys (public + secret) — stocate in config.php, nu in DB
- Moneda default

---

## 6. BACKEND — LOGICA TEHNICA

### 6.1 Stack
- **PHP 8.x** (disponibil pe cPanel)
- **MySQL** — o singura baza de date cu tabele clare
- **Revolut Merchant API** — REST, autentificare Bearer token
- **PHPMailer** — pentru emailuri tranzactionale
- **Vanilla JS** pe frontend — zero framework, zero build step

### 6.2 Schema MySQL

```sql
-- Produse
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) UNIQUE,
  name_en VARCHAR(200),
  name_ro VARCHAR(200),
  desc_short_en TEXT,
  desc_short_ro TEXT,
  desc_long_en TEXT,
  desc_long_ro TEXT,
  how_made_en TEXT,
  how_made_ro TEXT,
  material VARCHAR(100),
  category ENUM('desk','display','automotive','other','home'),
  price_eur DECIMAL(10,2),
  stock INT DEFAULT 0,
  stock_threshold INT DEFAULT 3,
  images JSON,                    -- array de paths
  specs JSON,                     -- array de {key, value}
  status ENUM('published','draft') DEFAULT 'draft',
  featured TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Comenzi
CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(20) UNIQUE,  -- GGS-2026-0001
  revolut_order_id VARCHAR(100),
  status ENUM('pending','paid','processing','shipped','delivered','cancelled') DEFAULT 'pending',
  customer_name VARCHAR(200),
  customer_email VARCHAR(200),
  customer_phone VARCHAR(50),
  shipping_address JSON,            -- {street, city, postal, country}
  customer_note TEXT,
  items JSON,                       -- [{product_id, name, price_eur, qty}]
  subtotal_eur DECIMAL(10,2),
  shipping_eur DECIMAL(10,2),
  total_eur DECIMAL(10,2),
  tracking_number VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Admin users
CREATE TABLE admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) UNIQUE,
  password_hash VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Settings
CREATE TABLE settings (
  key_name VARCHAR(100) PRIMARY KEY,
  value TEXT
);
```

### 6.3 Flux plata Revolut

```
1. Client apasa "Place Order"
   → checkout.js trimite POST la api/create-order.php
   
2. create-order.php:
   → Valideaza datele clientului
   → Salveaza order in MySQL cu status 'pending'
   → Creeaza order via Revolut Merchant API
   → Returneaza revolut_order_id + public_id catre JS
   
3. checkout.js:
   → Initializeaza Revolut Checkout Widget cu public_id
   → Clientul introduce datele cardului in widget
   → Revolut proceseaza plata
   
4. La succes/fail:
   → Revolut trimite webhook la api/webhook.php
   → webhook.php verifica semnatura request-ului
   → Daca paid: update order status → 'paid', scade stoc, trimite email confirmare
   → Redirect client la order-success.html sau order-failed.html

5. Tu primesti email cu detaliile comenzii
   → Intri in admin, schimbi status in 'processing', apoi 'shipped'
   → Optional: trimiti email update catre client cu tracking
```

### 6.4 Currency detection
- La load pagina: fetch ip-api.com (gratuit, fara key) → detecteaza tara
- Romania → afiseaza RON (conversie din EUR cu rata fixata in settings)
- Altceva → afiseaza EUR
- Toggle manual in nav suprascrie detectia si salveaza preferinta in localStorage

### 6.5 Email notifications (PHPMailer)
**Trigger 1 — Comanda noua (catre tine):**
- Subject: `[GGS] New order #GGS-2026-XXXX — X EUR`
- Body: toate detaliile comenzii + link direct la admin/order-detail.php

**Trigger 2 — Confirmare comanda (catre client):**
- Subject: `Your Gomez Garage Studio order #GGS-2026-XXXX`
- Body: multumire, sumar comanda, adresa livrare, timeline estimat

**Trigger 3 — Stoc scazut (catre tine):**
- Verificat la fiecare plata confirmata
- Daca stoc produs < threshold → email alert

---

## 7. LIMBAJ & CONTINUT

- **Default: Engleza** — toate textele scrise primar in EN
- **Toggle RO** — data-ro attribute pe fiecare element de text, JS schimba la toggle
- **Texte RO fara diacritice** — regula de brand; formularea despre originalitate pune accent pe designul original.
- **Moneda default:** detectata geo (RON pentru Romania, EUR pentru restul)
- **Toggle manual** moneda in nav — persistat in localStorage

---

## 8. SEO & TEHNIC

- Meta title + description per pagina
- Open Graph tags (og:title, og:description, og:image, og:url)
- Canonical URLs
- sitemap.xml este randat de sitemap.php din produsele publicate, prin rewrite Apache.
- product.html si products.html sunt randate initial prin product.php / products.php;
  JavaScript continua sa ofere galeria, filtrele, moneda si cosul existente.
- Canonical, titlu, descriere, Open Graph si Product/Offer JSON-LD provin din produsul publicat.
- Linkurile canonice folosesc product.html?slug=...; linkurile istorice cu id raman valide.
- robots.txt — permite indexare, exclude /admin/ si /api/
- Schema.org markup pe paginile de produs (Product schema)
- Imagini: WebP unde posibil, lazy loading
- Mobile-first, fully responsive

---

## 9. SECURITATE

- Admin: session PHP cu timeout 2 ore
- Parola admin: bcrypt hash
- Revolut webhook: verificare semnatura header (Revolut-Signature)
- Input sanitizare: toate datele de la client trec prin prepared statements
- /admin/ si /api/ excluse din robots.txt
- config.php cu credentials in afara public_html sau cu .htaccess restrict
- CORS restrictionat pe api/ — doar domeniul propriu

---

## 10. FAZE DE IMPLEMENTARE

### Faza 1 — Fundatia (prioritate maxima, incepe acum)
- [ ] Setup repo GitHub + structura foldere
- [ ] main.css — variabile, reset, tipografie, componente de baza
- [ ] Nav + Footer component
- [ ] Homepage complet (static, fara date din DB)
- [ ] Products page (static, cu produse hardcodate)
- [ ] Product page template

### Faza 2 — Backend (dupa aprobare Merchant Revolut, est. 3 Aug)
- [ ] Setup MySQL — creare tabele
- [ ] Admin panel: login, dashboard, CRUD produse
- [ ] API endpoints: get-products, create-order
- [ ] Revolut Checkout Widget integration
- [ ] Webhook handler
- [ ] PHPMailer email notifications

### Faza 3 — Polish & Launch
- [ ] Cart + checkout complet testat
- [ ] Order success/failed pages
- [ ] Admin orders management
- [ ] Currency detection + toggle
- [ ] SEO meta tags + sitemap
- [ ] Test end-to-end pe sandbox Revolut
- [ ] Deploy pe cPanel + DNS check

### Faza 4 — Post-launch (luna 2-3)
- [ ] Custom Order page
- [ ] About page
- [ ] Stock alerts email
- [ ] Schema.org markup
- [ ] Analytics (Plausible sau Fathom — privacy-first, GDPR compliant)

---

## 11. WHAT WE DON'T BUILD (YET)

- Nu facem wishlist / user accounts
- Nu facem reviews sistem
- Nu facem blog
- Nu facem newsletter integrat (poate mai tarziu Brevo)
- Nu facem recomandari AI sau upsell logic
- Nu facem app mobile

---

*Document viu — se actualizeaza pe masura ce apar decizii noi.*
*Autor: Gomez + Vex | Gomez Group / Lancer Land SRL*
