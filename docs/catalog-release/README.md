# Catalog release - 26 October 2026

## Continut

Sase produse pregatite ca **draft**, cu texte EN/RO fara diacritice:

| Categorie | Produs | Material |
| --- | --- | --- |
| Birou & tech | MagSafe + Apple Watch | ASA-CF dark grey |
| Birou & tech | Media Dock USB / SD / microSD | PETG negru mat |
| Birou & tech | Suport casti Cheia Sol | PLA-CF negru |
| Expunere & colectii | Stand pentru 12 brichete Zippo | PETG negru mat |
| Casa & decor | Set 3 suporturi tealight negre | PLA-CF negru |
| Casa & decor | Set 3 suporturi tealight Rainbow, unicat | PLA rainbow |

Textele complete sunt prezentate in [product-copy.md](product-copy.md). Sursa, inclusiv
descrierile lungi si procesul de fabricatie, este
[catalog-products.json](../../database/catalog-products.json). SQL-ul este generat
din acest fisier cu `python3 scripts/build-catalog-sql.py`; `--check` verifica sincronizarea.

Preturile nu au fost confirmate: valoarea interna 0 inseamna pret necompletat pentru
aceste drafturi. Editorul il afiseaza gol si refuza publicarea fara pret pozitiv si
fotografie. Stocurile neconfirmate sunt 0; numai rainbow are stocul confirmat 1.
Fotografiile se incarca din administrare; importul nu le leaga automat de produse.
Dimensiunile, greutatile si compatibilitatea exacta a incarcatoarelor raman de completat.
Pentru seturile tealight se completeaza marimea compatibila a lumanarilor si instructiunile
de utilizare inainte de publicare. Nu sunt introduse preturi Etsy ca preturi finale.

## Ce se schimba tehnic

- Catalogul, administrarea si ambele API-uri accepta `home`; valorile istorice
  `automotive` si `other` raman valide. Filtrele publice prezinta cele trei categorii alese.
- Descrierile lungi din backend apar pe pagina de produs, in ambele limbi.
- Paginile de produs primesc titlu, descriere, canonical, Open Graph si Product/Offer
  JSON-LD din produsul publicat. Nu sunt inventate recenzii, ratinguri sau identificatori.
- Continutul de produs si legaturile din catalog sunt prezente in HTML-ul initial,
  inainte de JavaScript. Stilurile, cosul si interactiunile existente sunt pastrate.
- Adresele descriptive folosesc `product.html?slug=...`; adresele vechi cu `id` functioneaza
  si indica acelasi canonical. Produsele draft/inexistente raspund 404; erorile DB raspund 503.
- `sitemap.xml` este randat din produsele publicate, fara ID-uri demonstrative fixe.
- Indexarea ramane in engleza, limba implicita. Toggle-ul RO continua sa functioneze;
  nu sunt declarate versiuni hreflang inexistente.

Implementarea urmeaza indrumarile Google pentru
[URL-uri ecommerce](https://developers.google.com/search/docs/specialty/ecommerce/designing-a-url-structure-for-ecommerce-sites),
[legaturi accesibile crawlerelor](https://developers.google.com/search/docs/specialty/ecommerce/help-google-understand-your-ecommerce-site-structure)
si [date Product in HTML-ul initial](https://developers.google.com/search/docs/appearance/structured-data/product-snippet).

## Ordinea pentru upload

1. Exporta baza existenta si salveaza copiile fisierelor pe care le inlocuiesti,
   inclusiv `.htaccess`. Nu rula `database/schema.sql` peste instalarea existenta.
2. In phpMyAdmin, pe baza magazinului, importa **doar**
   [20261026_home_category.sql](../../database/migrations/20261026_home_category.sql).
   Aceasta extinde enum-ul existent, fara a modifica produsele sau comenzile.
   Verifica mai intai `SHOW COLUMNS FROM products LIKE 'category';`: migratia este
   pentru enum-ul `desk, display, automotive, other` din schema acestui proiect,
   coloana nullable cu default NULL. Daca live are alta definitie, adapteaza migrarea
   la acea definitie inainte de import.
3. Incarca fisierele din pachetul `studio-upload.zip`, in folderul `/studio/`,
   pastrand structura directoarelor. Lista este in `runtime-files.txt` din pachet.
   Daca `.htaccess` live contine reguli adaugate pe server, pastreaza-le si adauga
   doar cele trei reguli de catalog de mai jos dupa regulile de protectie existente.
4. Importa [20261026_initial_catalog.sql](../../database/migrations/20261026_initial_catalog.sql)
   **cand esti pregatit sa retragi catalogul vechi**. Prima rulare trece toate
   produsele vechi in draft si le scoate din homepage; adauga cele sase produse noi ca draft.
   Magazinul va afisa temporar mesajul "Produse in curand" pana publici produsele noi.
   Comenzile, facturile, setarile de plata, ID-urile si stocurile istorice sunt pastrate.
   Importul poate fi repetat fara sa reseteze editarile ulterioare.
5. In `admin/products.php`, deschide cele sase produse reale, incarca fotografiile,
   alege poza principala, completeaza preturile, stocurile si specificatiile. Salveaza
   fiecare produs ca Published cand este gata. MagSafe, Media Dock si Zippo sunt propuse
   pentru homepage, dar optiunea Featured poate fi schimbata.
6. Verifica un produs publicat, filtrele, galeria si sitemap-ul. Poti verifica adaugarea
   in cos fara sa plasezi o comanda. Publicarea in GitHub nu publica automat pe server.

Regulile noi Apache:

```apache
RewriteRule ^product\.html$ product.php [L]
RewriteRule ^products\.html$ products.php [L]
RewriteRule ^sitemap\.xml$ sitemap.php [L]
```

### Fisiere pentru server

Lista exacta se afla in [runtime-files.txt](runtime-files.txt). Nu se incarca
`config.php`, `.env`, `vendor/`, `tests/`, `scripts/`, `database/`, `docs/` sau fotografiile
RAW din calculator. SQL-urile se importa din phpMyAdmin; fotografiile WebP se incarca
din administrare. Pachetul de runtime nu contine configuratii sau imagini existente de produs.

### Revenire

Inainte de importul de produse poti reveni la fisierele vechi si regulile `.htaccess`
vechi; categoria suplimentara poate ramane in DB. Dupa import, vechile produse exista
inca in administrare ca draft. Pentru revenirea catalogului, foloseste exportul salvat
pentru statusurile/featured anterioare sau reactiveaza manual listarile dorite si retrage
cele noi. Nu elimina valoarea `home` din enum cat timp exista produse cu aceasta categorie.
Nu restaura intreaga baza peste comenzi noi primite intre timp.

## Fotografii

Folderul `photos/` contine cate doua variante ambientale pentru fiecare produs,
convertite in WebP la rezolutia disponibila, fara alta generare sau modificare a formei.
Pentru Zippo sunt incluse numai cele doua cadre de pe raftul tematic aprobate.
Fotografiile de studio originale se adauga separat de proprietar. Incarcarea in backend
permite alegerea imaginii principale; numele fisierelor sunt descriptive inainte de upload.
Galeria actuala ramane pentru imagini; suportul video nu face parte din acest pachet.

## Verificari

Comenzile si rezultatele locale sunt documentate in [verification.md](verification.md).
Codul pentru Revolut, webhook, checkout, facturare, email si expediere nu este modificat.
Nu au fost folosite serviciile comerciale sau baza live pentru verificari.
