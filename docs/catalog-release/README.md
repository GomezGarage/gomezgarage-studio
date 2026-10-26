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
SQL-ul de import afiseaza numele si descrierile direct ca text lizibil; apostrofurile
sunt escapate prin dublare, conform sintaxei SQL.

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

## Publicare prin GitHub si pull din cPanel

Fluxul existent al proiectului este **GitHub main -> pull din cPanel**, facut de
proprietar. Fisierele se publica prin Git; nu este nevoie de upload manual din ZIP.

1. Modificarile verificate se integreaza in `main` pe GitHub.
2. Proprietarul face pull/update din cPanel pe checkout-ul existent. Configuratia
   privata si imaginile incarcate din backend raman in afara versiunilor Git.
   Pull-ul include regulile Apache pentru randarea catalogului si protectia
   directoarelor de dezvoltare; nu executa migrari SQL.
3. Separat, inainte de folosirea categoriei noi sau de importul catalogului, se
   exporta baza existenta si se verifica `SHOW COLUMNS FROM products LIKE 'category';`.
   Migrarea este pentru enum-ul `desk, display, automotive, other` din schema
   proiectului, coloana nullable cu default NULL. Daca live are alta definitie,
   se adapteaza migrarea la acea definitie inainte de import.
4. In phpMyAdmin, pe baza magazinului, se importa in ordine:
   - [20261026_home_category.sql](../../database/migrations/20261026_home_category.sql)
   - [20261026_initial_catalog.sql](../../database/migrations/20261026_initial_catalog.sql)

   Al doilea import se face **cand esti pregatit sa retragi catalogul vechi**.
   Prima rulare trece vechile produse in draft si le scoate din homepage, apoi
   adauga cele sase produse reale ca draft. Magazinul afiseaza temporar mesajul
   "Produse in curand" pana la publicarea noilor produse. Comenzile, facturile,
   setarile de plata, ID-urile si stocurile istorice sunt pastrate. Repetarea
   importului nu reseteaza editarile ulterioare. Nu se ruleaza schema completa
   `database/schema.sql` peste instalarea existenta.
5. In `admin/products.php`, deschizi cele sase produse reale, incarci fotografiile,
   alegi poza principala, completezi preturile, stocurile si specificatiile. Salvezi
   fiecare produs ca Published cand este gata. MagSafe, Media Dock si Zippo sunt
   propuse pentru homepage; optiunea Featured poate fi schimbata.
6. Verifici un produs publicat, filtrele, galeria si sitemap-ul. Adaugarea in cos
   poate fi verificata fara plasarea unei comenzi.

### Continutul repository-ului pe server

Lista [runtime-files.txt](runtime-files.txt) identifica fisierele de runtime modificate,
pentru audit. Nu este o lista de upload manual. `.htaccess` blocheaza accesul HTTP
la `.git/`, `includes/`, `database/`, `scripts/`, `invoices/`, `tests/` si `docs/`.
Fotografiile din `docs/catalog-release/photos/` sunt surse pentru incarcarea din
administrare; galeria publica foloseste fisierele din `assets/images/products/`.
Configuratia privata, credentialele si fisierele existente de produs nu se publica
prin Git. Arhiva generata anterior ramane doar o copie optionala; fluxul standard
este pull-ul din cPanel.

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
