# Catalog si videoclipuri 7 noiembrie 2026

## Publicare

Fluxul magazinului ramane GitHub main apoi pull din cPanel, facut de proprietar.
Acest pachet completeaza catalogul direct in baza; nu necesita copierea manuala a
descrierilor din Etsy sau dintr-un document Word.

1. Fa pull din cPanel dupa integrarea modificarilor in main.
2. Exporta baza magazinului inainte de resetarea catalogului.
3. In phpMyAdmin selecteaza baza magazinului si importa **doar**
   `database/migrations/20261107_catalog_reset.sql`.
   Acesta include si crearea tabelului pentru videoclipuri si metadate.
   Nu importa schema completa si nu reimporta migrarile vechi.
4. La final trebuie sa apara `Installed - seven draft products ready for photos and videos`.
5. Deschide administrarea produselor. Incarca pozele si videoclipurile, alege poza
   principala, verifica stocul si salveaza ca Published cand produsul este pregatit.

SQL-ul sterge produsele existente si metadatele lor, conform cererii proprietarului,
apoi adauga cele sapte produse ca draft. Nu modifica comenzile, facturile, setarile
de plata sau istoricul de vizualizari si nu sterge fisierele media de pe disc.
ID-urile produselor nu sunt reutilizate prin resetarea AUTO_INCREMENT.

Importul refuza resetarea daca exista vreun produs publicat. In acest caz afiseaza
`NOT reset - published products exist` si nu schimba catalogul. Dupa prima rulare
reusita, markerul `catalog_reset_20261107` face reimportul inofensiv pentru editarile
si fisierele adaugate ulterior. Nu sterge markerul pentru a reimporta.

## Produse pregatite

| Produs | Categorie | Material | Pret EUR |
| --- | --- | --- | ---: |
| MagSafe si Apple Watch | Birou si tech | ASA-CF gri inchis | 49 |
| Media Dock | Birou si tech | PLA-CF negru | 39 |
| Cheia Sol | Birou si tech | PLA-CF negru | 39 |
| Stand Zippo negru | Expunere si colectii | PETG negru mat | 45 |
| Stand Zippo Walnut | Expunere si colectii | Walnut Wood PLA | 55 |
| Set tealight negru | Casa si decor | PLA-CF negru | 29 |
| Set tealight Rainbow | Casa si decor | PLA rainbow | 34 |

Walnut are o piesa gata, intr-o serie de maximum patru. Rainbow are un singur set
complet de trei suporturi. Stocurile celorlalte produse sunt neconfirmate si pornesc
de la 0; se completeaza in admin inainte de publicare.

Fiecare produs primeste nume EN/RO, descrieri scurte si lungi, proces de fabricatie,
mentiune vizibila a designului original Gomez Garage Studio, specificatii EN/RO,
SKU si metadate SEO EN/RO. Textele RO sunt fara diacritice. Sunt explicitate
accesoriile excluse, dimensiunile confirmate si limitele de compatibilitate.
Nu se inventeaza dimensiuni pentru MagSafe, certificari, ratinguri sau rezultate
de rezistenta termica. Preturile tealight sunt recomandarile discutate cu proprietarul.

## Administrarea fotografiilor si videoclipurilor

- Fotografiile raman in `assets/images/products/`; prima fotografie este coperta.
- La fotografiile deja salvate se poate introduce alt text EN/RO.
- Se pot incarca pana la patru videoclipuri MP4 per produs, maximum 100 MB fiecare.
  Se recomanda H.264, yuv420p si faststart, asa cum sunt clipurile pregatite in chat.
- Dupa prima salvare apar titlul EN/RO, ordinea si fotografia de coperta pentru
  fiecare clip. Videoclipurile sunt afisate in aceeasi galerie cu fotografiile.
- Redarea necesita apasarea Play; clipurile nu pornesc automat. Trecerea la alta
  fotografie sau alt videoclip opreste redarea precedenta. Cadrul video este integral.
- Eliminarea unui clip il scoate din galerie, pastrand fisierul pe server.
- Directorul video este exclus din Git; pull-urile ulterioare pastreaza uploadurile.

`admin/.user.ini` solicita 100M per fisier si 512M pentru intregul formular pe PHP
CGI/FPM. Gazda poate impune limite proprii; valorile efective sunt afisate in editor.
Unele configuratii PHP aplica un fisier .user.ini nou dupa expirarea cache-ului.
Un upload prea mare produce un mesaj explicit. MP4-urile sunt verificate dupa
extensie, MIME real si antet; numele sunt generate aleatoriu. MOV se converteste
in MP4 inainte de incarcare. Configuratia PHP nu este accesibila prin HTTP.

## Compatibilitate

Datele suplimentare sunt in tabelul aditiv `product_content`, separat de coloanele
folosite de checkout. Fara migrare, publicul si administrarea folosesc datele existente;
campurile suplimentare sunt inactive pana la import. API-ul public primeste campuri
aditive, iar contractul API-ului de servicii ramane neschimbat.

SEO-ul initial randat pe server foloseste EN. Comutarea RO schimba textele si
metadatele pentru vizitator; nu sunt introduse URL-uri RO sau hreflang fictive.
Titlurile SEO goale folosesc numele produsului, iar descrierile SEO goale folosesc
descrierea scurta. SKU-ul este utilizat si in datele structurate Product.

## Surse si regenerare

Sursa catalogului curent: `database/catalog-products-20261107.json`.
Regenerare: `python3 scripts/build-current-catalog-sql.py`.
Verificare fara modificari: `python3 scripts/build-current-catalog-sql.py --check`.
Catalogul si migrarile din octombrie raman arhivate ca istoric al primei versiuni.

## Verificari locale

- PHP lint pentru fisierele schimbate si sintaxa JavaScript.
- 124 verificari ale API-ului existent si 50 verificari izolate de prezentare.
- HTTP loopback pentru API si upload multipart MP4 real, cu respingerea fisierelor
  executabile, MP4 fals si upload gol.
- OpenAPI 3.1: 80 raspunsuri sintetice, cinci exemple si doua cereri de draft valide.
- MariaDB 10.6.28 izolat, fara TCP: 61 verificari API si 72 verificari de catalog,
  inclusiv resetul curent, protectia produselor publicate, pastrarea comenzilor,
  round-trip EN/RO, SKU, preturi si reimport dupa editari. Verificarile includ salvarea
  reala din admin prin HTTP multipart, CSRF, persistenta video, scoaterea clipului
  din galerie cu pastrarea fisierului si blocarea publicarii fara fotografie.
- Apache local cu HTTPS: rescrieri, HTML initial, metadata, URL-uri existente,
  raspunsuri 404/503, sitemap si protectia directoarelor interne.
- Galerie verificata in browser cu video 1920 x 1758, durata 14,4 secunde,
  redare efectiva fara eroare, comutare RO si specificatii traduse.

Nu au fost incarcate configuratia privata sau baza magazinului si nu au fost apelate
servicii reale de plata, email sau curier. Codul Revolut, checkout, webhook,
facturare si expediere nu este modificat.

Referinte tehnice: [HTML video](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/video)
si [uploaduri PHP](https://www.php.net/manual/en/features.file-upload.php).
