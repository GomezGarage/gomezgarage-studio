-- RESET CATALOG: explicitly requested by the owner, 7 November 2026.
-- Deletes existing draft product records; creates seven completed drafts.
-- Existing images/videos on disk are retained. Orders and invoices are untouched.
-- Back up the shop database before import. Do NOT run database/schema.sql.
-- Refuses to reset if any product is published. Successful import runs only once.
SET NAMES utf8mb4;
-- Additive and repeatable. Run on the shop database after the GitHub/cPanel pull.
-- Does not change products, prices, stock, orders, invoices or payment settings.
CREATE TABLE IF NOT EXISTS product_content (
  product_id INT NOT NULL PRIMARY KEY,
  content JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
SET @ggs_reset_installed = (SELECT COUNT(*) FROM settings WHERE key_name = 'catalog_reset_20261107');
SET @ggs_published_count = (SELECT COUNT(*) FROM products WHERE status = 'published');
SET @ggs_do_reset = (@ggs_reset_installed = 0 AND @ggs_published_count = 0);
DELETE FROM product_content WHERE @ggs_do_reset = 1;
DELETE FROM products WHERE @ggs_do_reset = 1;

INSERT INTO products
  (slug, name_en, name_ro, material, category, price_eur, stock, featured, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro, specs, status, stock_threshold, images)
SELECT
  'magsafe-apple-watch-dock',
  'MagSafe & Apple Watch Stand - Dark Grey ASA-CF',
  'Suport MagSafe si Apple Watch - ASA-CF gri inchis',
  'ASA-CF Dark Grey',
  'desk',
  49,
  3,
  1,
  'An original MagSafe and Apple Watch stand in dark grey ASA-CF, with an angled phone face, raised watch support and rear cable routing. Stand only.',
  'Suport original pentru MagSafe si Apple Watch, din ASA-CF gri inchis, cu fata inclinata si traseu pentru cabluri. Se livreaza doar suportul.',
  'Give your everyday devices a place of their own. Two charging positions come together in a compact geometric stand for your desk or bedside table.

ORIGINAL DESIGN BY GOMEZ GARAGE STUDIO
Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania.

Recessed side panels and the fine ASA-CF texture give the stand its industrial character. The inclined phone face supports comfortable viewing, while the raised Apple Watch position keeps both devices accessible. Cables pass towards the rear of the stand.

The photographed MagSafe and Apple Watch charging pucks illustrate the intended setup. Check charger dimensions before ordering; fit can vary between charger models. The stand itself contains no charging electronics.

WHAT IS INCLUDED
One dark grey ASA-CF stand. Phone, watch, charging pucks, cables, power adapters and photography props are not included.',
  'Un loc dedicat pentru dispozitivele folosite zilnic. Doua pozitii de incarcare sunt reunite intr-un suport geometric compact pentru birou sau noptiera.

DESIGN ORIGINAL GOMEZ GARAGE STUDIO
Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania.

Panourile laterale adancite si textura fina a materialului ASA-CF dau suportului un caracter industrial. Fata inclinata pozitioneaza telefonul pentru vizualizare, iar sprijinul superior pastreaza Apple Watch la indemana. Cablurile sunt directionate spre spate.

Pucurile MagSafe si Apple Watch din fotografii ilustreaza configuratia de utilizare. Verifica dimensiunile incarcatoarelor inainte de comanda; potrivirea poate varia intre modele. Suportul nu contine electronica de incarcare.

CE PRIMESTI
Un suport din ASA-CF gri inchis. Telefonul, ceasul, pucurile de incarcare, cablurile, adaptoarele de alimentare si recuzita nu sunt incluse.',
  'Original CAD design by Gomez Garage Studio, FDM printed in dark grey ASA-CF, an ASA composite containing carbon fibre. Fine layer texture remains visible on the finished surface.',
  'Design CAD original Gomez Garage Studio, printat FDM din ASA-CF gri inchis, un compozit ASA cu fibra de carbon. Textura fina a straturilor ramane vizibila pe produs.',
  '[{"key":"Material","value":"ASA-CF"},{"key":"Colour","value":"Dark grey"},{"key":"Setup","value":"MagSafe + Apple Watch charging pucks"},{"key":"Included","value":"Stand only; chargers and devices excluded"}]',
  'draft',
  3,
  '[]'
WHERE @ggs_do_reset = 1;
INSERT INTO product_content (product_id, content)
SELECT id, '{"sku":"GGS-MAG1-ASACF-DGR","seo_title_en":"MagSafe & Apple Watch Stand in ASA-CF | Gomez Garage Studio","seo_title_ro":"Suport MagSafe si Apple Watch ASA-CF | Gomez Garage Studio","seo_description_en":"An original MagSafe and Apple Watch stand in dark grey ASA-CF, with an angled phone face, raised watch support and rear cable routing. Stand only.","seo_description_ro":"Suport original pentru MagSafe si Apple Watch, din ASA-CF gri inchis, cu fata inclinata si traseu pentru cabluri. Se livreaza doar suportul.","specs_ro":[{"key":"Material","value":"ASA-CF"},{"key":"Culoare","value":"Gri inchis"},{"key":"Configuratie","value":"Pucuri de incarcare MagSafe + Apple Watch"},{"key":"Inclus","value":"Doar suportul; incarcatoarele si dispozitivele nu sunt incluse"}]}'
FROM products WHERE slug = 'magsafe-apple-watch-dock' AND @ggs_do_reset = 1;

INSERT INTO products
  (slug, name_en, name_ro, material, category, price_eur, stock, featured, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro, specs, status, stock_threshold, images)
SELECT
  'media-dock-organizer',
  'Media Dock - USB, SD & microSD Organizer',
  'Media Dock - Organizator USB, SD si microSD',
  'PLA-CF Black',
  'desk',
  39,
  3,
  1,
  'Keep USB drives and memory cards visible in 34 dedicated slots. An original matte black PLA-CF desk organizer with an inclined face, accessory tray and non-slip feet.',
  'Stickuri USB si carduri organizate in 34 de locasuri dedicate. Design original din PLA-CF negru, cu fata inclinata, tava pentru accesorii si picioruse antialunecare.',
  'Keep your next shoot organised. Media Dock brings USB drives, SD cards and microSD cards into one visible, accessible desktop arrangement.

ORIGINAL DESIGN BY GOMEZ GARAGE STUDIO
Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania.

34 DEDICATED SLOTS
10 USB-A, 8 USB-C, 4 full-size SD and 12 microSD slots. The inclined face keeps each row in view, while the front tray holds small adapters, card readers and accessories. Four non-slip feet help keep the organizer in place.

Printed in matte black PLA-CF, with a fine texture and subtle layer lines. The GGS3D maker''s mark is integrated into the underside.

FIT AND FUNCTION
Slot access depends on the width and shape of each drive body. Oversized drives may cover neighbouring slots. Media Dock provides passive storage with no electronic components or data connection.

WHAT IS INCLUDED
One Media Dock organizer with four non-slip feet. USB drives, memory cards, adapters and all other accessories shown are not included.

CARE
Wipe gently with a soft, slightly damp cloth. Keep away from heat and prolonged direct sunlight.',
  'Pregateste-ti urmatoarea sesiune foto cu accesoriile la indemana. Media Dock reuneste stickurile USB si cardurile SD si microSD intr-un organizator de birou usor de folosit.

DESIGN ORIGINAL GOMEZ GARAGE STUDIO
Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania.

34 DE LOCASURI DEDICATE
10 USB-A, 8 USB-C, 4 pentru carduri SD si 12 pentru microSD. Fata inclinata pastreaza randurile la vedere, iar tava frontala ofera loc pentru adaptoare, cititoare de carduri si accesorii mici. Patru picioruse antialunecare ajuta la stabilitate.

Printat din PLA-CF negru mat, cu textura fina si straturi discrete. Marcajul GGS3D este integrat pe partea inferioara.

COMPATIBILITATE SI UTILIZARE
Accesul la locasuri depinde de latimea si forma carcasei stickului. Modelele late pot acoperi locasurile vecine. Media Dock este un organizator pasiv, fara componente electronice sau conexiune de date.

CE PRIMESTI
Un organizator Media Dock cu patru picioruse antialunecare. Stickurile, cardurile, adaptoarele si celelalte accesorii din fotografii nu sunt incluse.

INTRETINERE
Sterge usor cu o laveta moale, putin umeda. Pastreaza departe de caldura si expunere prelungita la soare.',
  'Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania. Made from black PLA-CF, a PLA composite containing carbon fibre.',
  'Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania. Realizat din PLA-CF negru, un compozit PLA cu fibra de carbon.',
  '[{"key":"Dimensions W x D x H","value":"18 x 12.5 x 8 cm"},{"key":"Capacity","value":"10 USB-A / 8 USB-C / 4 SD / 12 microSD"},{"key":"Material","value":"Black PLA-CF"},{"key":"Feet","value":"Four non-slip feet"},{"key":"Included","value":"Organizer only"}]',
  'draft',
  3,
  '[]'
WHERE @ggs_do_reset = 1;
INSERT INTO product_content (product_id, content)
SELECT id, '{"sku":"GGS-MDK1-PLACF-BLK","seo_title_en":"USB Drive, SD & microSD Organizer - 34 Slots | Gomez Garage Studio","seo_title_ro":"Organizator USB, SD si microSD - 34 locasuri | Gomez Garage Studio","seo_description_en":"Keep USB drives and memory cards visible in 34 dedicated slots. An original matte black PLA-CF desk organizer with an inclined face, accessory tray and non-slip feet.","seo_description_ro":"Stickuri USB si carduri organizate in 34 de locasuri dedicate. Design original din PLA-CF negru, cu fata inclinata, tava pentru accesorii si picioruse antialunecare.","specs_ro":[{"key":"Dimensiuni L x A x I","value":"18 x 12,5 x 8 cm"},{"key":"Capacitate","value":"10 USB-A / 8 USB-C / 4 SD / 12 microSD"},{"key":"Material","value":"PLA-CF negru"},{"key":"Picioruse","value":"Patru picioruse antialunecare"},{"key":"Inclus","value":"Doar organizatorul"}]}'
FROM products WHERE slug = 'media-dock-organizer' AND @ggs_do_reset = 1;

INSERT INTO products
  (slug, name_en, name_ro, material, category, price_eur, stock, featured, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro, specs, status, stock_threshold, images)
SELECT
  'treble-clef-headphone-stand',
  'Treble Clef Headphone Stand - Black PLA-CF',
  'Suport casti Cheia Sol - PLA-CF negru',
  'PLA-CF Black',
  'desk',
  39,
  3,
  0,
  'A sculptural treble clef headphone stand with a curved headband support, broad base and four non-slip feet. Original design, 3D printed in matte black PLA-CF.',
  'Suport de casti in forma de cheie sol, cu sprijin curbat, baza lata si patru picioruse antialunecare. Design original, printat din PLA-CF negru mat.',
  'Give your headphones a place in your music setup. A sculptural treble clef combines a recognisable musical shape with a practical curved headband support.

ORIGINAL DESIGN BY GOMEZ GARAGE STUDIO
Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania.

The thick profile rises from a broad freestanding base. Four black non-slip feet help keep the stand in place, while the open loops remain a distinctive part of your desk when the headphones are in use. The GGS3D maker''s mark is integrated into the underside.

Black PLA-CF gives the piece a fine matte texture, suited to a music desk, home studio or listening corner. Fine printed layers remain visible on close inspection.

HEADPHONE FIT
The headphones pictured demonstrate the curved saddle. Fit depends on your headset''s dimensions and headband shape. Contact the studio before ordering if your headset has an unusually shaped headband.

WHAT IS INCLUDED
One headphone stand with four non-slip feet. Headphones, audio equipment and decorative props are not included.

CARE
Wipe with a soft, slightly damp cloth. Keep away from heat and prolonged direct sunlight.',
  'Un loc dedicat pentru casti in spatiul tau de muzica. Forma sculpturala a cheii sol combina un simbol muzical usor de recunoscut cu un sprijin curbat pentru bentita castilor.

DESIGN ORIGINAL GOMEZ GARAGE STUDIO
Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania.

Profilul gros se ridica dintr-o baza lata. Patru picioruse negre antialunecare ajuta la stabilitate, iar buclele deschise pastreaza forma expresiva chiar si cand folosesti castile. Marcajul GGS3D este integrat pe dedesubt.

PLA-CF negru ofera o textura fina, mata, potrivita pe un birou de muzica, intr-un home studio sau langa echipamentul audio. Straturile printate raman vizibile de aproape.

COMPATIBILITATE
Castile din fotografii ilustreaza sprijinul curbat. Potrivirea depinde de dimensiunile castilor si de forma bentitei. Contacteaza studioul inainte de comanda pentru o bentita cu forma neobisnuita.

CE PRIMESTI
Un suport de casti cu patru picioruse antialunecare. Castile, echipamentele audio si recuzita nu sunt incluse.

INTRETINERE
Sterge cu o laveta moale, putin umeda. Pastreaza departe de caldura si expunere prelungita la soare.',
  'An original CAD design by Gomez Garage Studio, 3D printed in Romania using black PLA-CF, a PLA composite containing carbon fibre.',
  'Design CAD original Gomez Garage Studio, printat 3D in Romania din PLA-CF negru, un compozit PLA cu fibra de carbon.',
  '[{"key":"Height","value":"28.5 cm"},{"key":"Width","value":"18 cm"},{"key":"Depth","value":"7 cm"},{"key":"Material","value":"Black PLA-CF"},{"key":"Feet","value":"Four non-slip feet"},{"key":"Included","value":"Stand only; headphones excluded"}]',
  'draft',
  3,
  '[]'
WHERE @ggs_do_reset = 1;
INSERT INTO product_content (product_id, content)
SELECT id, '{"sku":"GGS-TC-PLA-CF-BLK","seo_title_en":"Treble Clef Headphone Stand in Black PLA-CF | Gomez Garage Studio","seo_title_ro":"Suport casti Cheia Sol din PLA-CF negru | Gomez Garage Studio","seo_description_en":"A sculptural treble clef headphone stand with a curved headband support, broad base and four non-slip feet. Original design, 3D printed in matte black PLA-CF.","seo_description_ro":"Suport de casti in forma de cheie sol, cu sprijin curbat, baza lata si patru picioruse antialunecare. Design original, printat din PLA-CF negru mat.","specs_ro":[{"key":"Inaltime","value":"28,5 cm"},{"key":"Latime","value":"18 cm"},{"key":"Adancime","value":"7 cm"},{"key":"Material","value":"PLA-CF negru"},{"key":"Picioruse","value":"Patru picioruse antialunecare"},{"key":"Inclus","value":"Doar suportul; castile nu sunt incluse"}]}'
FROM products WHERE slug = 'treble-clef-headphone-stand' AND @ggs_do_reset = 1;

INSERT INTO products
  (slug, name_en, name_ro, material, category, price_eur, stock, featured, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro, specs, status, stock_threshold, images)
SELECT
  'zippo-lighter-display-stand',
  'Zippo Lighter Display Stand - Matte Black PETG',
  'Stand brichete Zippo - PETG negru mat',
  'PETG Matte Black',
  'display',
  45,
  3,
  1,
  'Display up to 12 Zippo lighters in three stepped rows, with a dedicated 125 ml fluid-can compartment and accessory tray. Original design in matte black PETG.',
  'Stand pentru pana la 12 brichete Zippo, pe trei randuri, cu loc pentru recipient de 125 ml si tava pentru accesorii. Design original din PETG negru mat.',
  'Give your lighter collection a dedicated place on the shelf. Three stepped rows hold up to 12 standard Zippo lighters, with four individual pockets per row.

ORIGINAL DESIGN BY GOMEZ GARAGE STUDIO
Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania.

The side compartment fits the 125 ml lighter-fluid can shown, while the shallow tray keeps flints, wicks and small accessories together. Non-slip feet help keep the display in place.

MATERIAL AND FINISH
Matte black PETG creates a restrained backdrop for metal finishes, engraving and colourful lighter artwork.

Fine printed layers remain visible on the finished matte black PETG surface.

FIT
Designed around standard Zippo lighters and the photographed fluid can. Oversized cases or added ornaments may affect fit; contact the studio with measurements before ordering.

WHAT IS INCLUDED
One display stand with non-slip feet. Lighters, fluid can and accessories are not included. This is an independent display accessory compatible with standard Zippo lighters.

CARE
Wipe gently with a soft, slightly damp cloth. Keep away from heat and prolonged direct sunlight.',
  'Ofera colectiei tale un loc dedicat pe raft. Trei randuri in trepte gazduiesc pana la 12 brichete Zippo standard, cu patru locasuri individuale pe fiecare rand.

DESIGN ORIGINAL GOMEZ GARAGE STUDIO
Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania.

Compartimentul lateral este potrivit pentru recipientul de benzina de 125 ml din fotografii, iar tava pastreaza pietrele, fitilurile si accesoriile mici impreuna. Piciorusele antialunecare ajuta la stabilitate.

MATERIAL SI FINISAJ
PETG negru mat ofera un fundal discret pentru finisajele metalice, gravurile si desenele de pe brichete.

Straturile fine ale printului raman vizibile pe suprafata din PETG negru mat.

COMPATIBILITATE
Proiectat pentru brichete Zippo standard si recipientul prezentat. Carcasele supradimensionate sau ornamentele adaugate pot afecta potrivirea; contacteaza studioul cu dimensiunile inainte de comanda.

CE PRIMESTI
Un stand cu picioruse antialunecare. Brichetele, recipientul de benzina si accesoriile nu sunt incluse. Suportul este un produs independent, compatibil cu brichetele Zippo standard.

INTRETINERE
Sterge usor cu o laveta moale, putin umeda. Pastreaza departe de caldura si expunere prelungita la soare.',
  'Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania using matte black PETG. The stepped pockets, side compartment and accessory tray form one printed body.',
  'Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania din PETG negru mat. Locasurile in trepte, compartimentul lateral si tava sunt integrate in corpul printat.',
  '[{"key":"Dimensions W x D x H","value":"26 x 13 x 11 cm"},{"key":"Capacity","value":"Up to 12 standard Zippo lighters"},{"key":"Fluid can","value":"125 ml, as pictured"},{"key":"Material","value":"PETG Matte Black"},{"key":"Included","value":"Stand with non-slip feet; accessories excluded"}]',
  'draft',
  3,
  '[]'
WHERE @ggs_do_reset = 1;
INSERT INTO product_content (product_id, content)
SELECT id, '{"sku":"GGS-ZIP-PETG-BLK","seo_title_en":"Display Stand for 12 Zippo Lighters | Gomez Garage Studio","seo_title_ro":"Stand pentru 12 brichete Zippo | Gomez Garage Studio","seo_description_en":"Display up to 12 Zippo lighters in three stepped rows, with a dedicated 125 ml fluid-can compartment and accessory tray. Original design in matte black PETG.","seo_description_ro":"Stand pentru pana la 12 brichete Zippo, pe trei randuri, cu loc pentru recipient de 125 ml si tava pentru accesorii. Design original din PETG negru mat.","specs_ro":[{"key":"Dimensiuni L x A x I","value":"26 x 13 x 11 cm"},{"key":"Capacitate","value":"Pana la 12 brichete Zippo standard"},{"key":"Recipient","value":"125 ml, ca in fotografii"},{"key":"Material","value":"PETG Matte Black"},{"key":"Inclus","value":"Stand cu picioruse antialunecare; fara accesorii"}]}'
FROM products WHERE slug = 'zippo-lighter-display-stand' AND @ggs_do_reset = 1;

INSERT INTO products
  (slug, name_en, name_ro, material, category, price_eur, stock, featured, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro, specs, status, stock_threshold, images)
SELECT
  'zippo-lighter-display-stand-walnut',
  'Zippo Lighter Display Stand - Walnut Collector''s Edition',
  'Stand brichete Zippo - Editia Walnut',
  'Walnut Wood PLA',
  'display',
  55,
  1,
  0,
  'A limited run of four Walnut Wood PLA display stands for 12 Zippo lighters, with a fluid-can compartment and accessory tray. Original Gomez Garage Studio design.',
  'Editie limitata la patru standuri din Walnut Wood PLA, pentru 12 brichete Zippo, cu loc pentru recipient si accesorii. Design original Gomez Garage Studio.',
  'Give your lighter collection a dedicated place on the shelf. Three stepped rows hold up to 12 standard Zippo lighters, with four individual pockets per row.

ORIGINAL DESIGN BY GOMEZ GARAGE STUDIO
Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania.

The side compartment fits the 125 ml lighter-fluid can shown, while the shallow tray keeps flints, wicks and small accessories together. Non-slip feet help keep the display in place.

WALNUT COLLECTOR''S EDITION
This Walnut run is limited to four stands in total. Made from SUNLU Walnut Wood PLA, a PLA composite containing 15% real wood powder according to the filament manufacturer. Walnut is the commercial material variant; the finish has a warm brown wood-like appearance.

Fine printed layers remain visible on the finished warm brown wood-filled PLA surface.

FIT
Designed around standard Zippo lighters and the photographed fluid can. Oversized cases or added ornaments may affect fit; contact the studio with measurements before ordering.

WHAT IS INCLUDED
One display stand with non-slip feet. Lighters, fluid can and accessories are not included. This is an independent display accessory compatible with standard Zippo lighters.

CARE
Wipe gently with a soft, slightly damp cloth. Keep away from heat and prolonged direct sunlight.',
  'Ofera colectiei tale un loc dedicat pe raft. Trei randuri in trepte gazduiesc pana la 12 brichete Zippo standard, cu patru locasuri individuale pe fiecare rand.

DESIGN ORIGINAL GOMEZ GARAGE STUDIO
Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania.

Compartimentul lateral este potrivit pentru recipientul de benzina de 125 ml din fotografii, iar tava pastreaza pietrele, fitilurile si accesoriile mici impreuna. Piciorusele antialunecare ajuta la stabilitate.

EDITIA WALNUT
Seria Walnut este limitata la patru standuri in total. Realizat din SUNLU Walnut Wood PLA, un compozit PLA care contine 15% pulbere reala de lemn, conform producatorului filamentului. Walnut este varianta comerciala a materialului, cu aspect de lemn in tonuri calde de maro.

Straturile fine ale printului raman vizibile pe suprafata din PLA cu pulbere de lemn, in tonuri calde de maro.

COMPATIBILITATE
Proiectat pentru brichete Zippo standard si recipientul prezentat. Carcasele supradimensionate sau ornamentele adaugate pot afecta potrivirea; contacteaza studioul cu dimensiunile inainte de comanda.

CE PRIMESTI
Un stand cu picioruse antialunecare. Brichetele, recipientul de benzina si accesoriile nu sunt incluse. Suportul este un produs independent, compatibil cu brichetele Zippo standard.

INTRETINERE
Sterge usor cu o laveta moale, putin umeda. Pastreaza departe de caldura si expunere prelungita la soare.',
  'Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania using Walnut Wood PLA. The stepped pockets, side compartment and accessory tray form one printed body.',
  'Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania din Walnut Wood PLA. Locasurile in trepte, compartimentul lateral si tava sunt integrate in corpul printat.',
  '[{"key":"Dimensions W x D x H","value":"26 x 13 x 11 cm"},{"key":"Capacity","value":"Up to 12 standard Zippo lighters"},{"key":"Fluid can","value":"125 ml, as pictured"},{"key":"Material","value":"Walnut Wood PLA"},{"key":"Edition","value":"Four stands in total"},{"key":"Included","value":"Stand with non-slip feet; accessories excluded"}]',
  'draft',
  3,
  '[]'
WHERE @ggs_do_reset = 1;
INSERT INTO product_content (product_id, content)
SELECT id, '{"sku":"GGS-ZIP-WAL","seo_title_en":"Walnut Display Stand for 12 Zippo Lighters | Gomez Garage Studio","seo_title_ro":"Stand Walnut pentru 12 brichete Zippo | Gomez Garage Studio","seo_description_en":"A limited run of four Walnut Wood PLA display stands for 12 Zippo lighters, with a fluid-can compartment and accessory tray. Original Gomez Garage Studio design.","seo_description_ro":"Editie limitata la patru standuri din Walnut Wood PLA, pentru 12 brichete Zippo, cu loc pentru recipient si accesorii. Design original Gomez Garage Studio.","specs_ro":[{"key":"Dimensiuni L x A x I","value":"26 x 13 x 11 cm"},{"key":"Capacitate","value":"Pana la 12 brichete Zippo standard"},{"key":"Recipient","value":"125 ml, ca in fotografii"},{"key":"Material","value":"Walnut Wood PLA"},{"key":"Editie","value":"Patru standuri in total"},{"key":"Inclus","value":"Stand cu picioruse antialunecare; fara accesorii"}]}'
FROM products WHERE slug = 'zippo-lighter-display-stand-walnut' AND @ggs_do_reset = 1;

INSERT INTO products
  (slug, name_en, name_ro, material, category, price_eur, stock, featured, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro, specs, status, stock_threshold, images)
SELECT
  'tealight-holder-set-black',
  'Black Tealight Holder Set of 3 - PLA-CF',
  'Set 3 suporturi tealight negre - PLA-CF',
  'PLA-CF Black',
  'home',
  29,
  3,
  0,
  'Three sculptural tealight holders with twisted geometric surfaces, 3D printed in matte black PLA-CF. Original design in 10, 8 and 6 cm heights. Candles not included.',
  'Trei suporturi tealight cu fatete rasucite, printate din PLA-CF negru mat. Design original, cu inaltimi de 10, 8 si 6 cm. Lumanarile nu sunt incluse.',
  'Bring a quiet architectural accent to your home. Three twisted geometric surfaces catch the light from different angles, creating a coordinated display for a console, sideboard or dining table.

ORIGINAL DESIGN BY GOMEZ GARAGE STUDIO
Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania.

MATERIAL AND FINISH
Made from black PLA-CF, a PLA composite containing carbon fibre, with a fine matte texture. Fine printed layers and surface texture remain visible. The GGS3D maker''s mark is on the underside.

THE SET
Three holders, 10, 8 and 6 cm high. Each base is 6 cm across, with a 39 mm recess for a tealight. Check the outside diameter of your tealight cup before ordering.

WHAT IS INCLUDED
One complete set of three holders. Candles and decorative props are not included.

USE AND CARE
Keep tealights in their original metal cups; do not pour wax directly into the holders. Place on a stable, heat-resistant surface away from flammable objects. Never leave burning candles unattended. Wipe the holders with a soft, slightly damp cloth; do not put in the dishwasher.',
  'Un accent arhitectural discret pentru casa ta. Fatetele rasucite surprind lumina din unghiuri diferite si formeaza un ansamblu potrivit pe o consola, o comoda sau masa de dining.

DESIGN ORIGINAL GOMEZ GARAGE STUDIO
Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania.

MATERIAL SI FINISAJ
Realizat din PLA-CF negru, un compozit PLA cu fibra de carbon, cu textura fina, mata. Straturile si textura printului raman vizibile. Marcajul GGS3D se afla pe partea inferioara.

SETUL
Trei suporturi cu inaltimi de 10, 8 si 6 cm. Fiecare baza masoara 6 cm, iar locasul pentru tealight are 39 mm. Verifica diametrul exterior al cupei lumanarii inainte de comanda.

CE PRIMESTI
Un set complet de trei suporturi. Lumanarile si recuzita nu sunt incluse.

UTILIZARE SI INTRETINERE
Pastreaza lumanarile in cupele metalice originale; nu turna ceara direct in suport. Asaza pe o suprafata stabila, rezistenta la caldura, departe de obiecte inflamabile. Nu lasa lumanarile aprinse nesupravegheate. Sterge suporturile cu o laveta moale, putin umeda; nu le pune in masina de spalat vase.',
  'An original Gomez Garage Studio design, created from scratch in CAD and 3D printed in Romania using black PLA-CF, a PLA composite containing carbon fibre.',
  'Design original Gomez Garage Studio, proiectat de la zero in CAD si printat 3D in Romania din PLA-CF negru, un compozit PLA cu fibra de carbon.',
  '[{"key":"Set","value":"Three holders"},{"key":"Heights","value":"10 / 8 / 6 cm"},{"key":"Base width","value":"6 cm each"},{"key":"Tealight recess","value":"39 mm diameter"},{"key":"Material","value":"Black PLA-CF"},{"key":"Included","value":"Holders only; candles excluded"}]',
  'draft',
  3,
  '[]'
WHERE @ggs_do_reset = 1;
INSERT INTO product_content (product_id, content)
SELECT id, '{"sku":"GGS-TLH3-PLACF-BLK","seo_title_en":"Black Tealight Holders Set of 3 in PLA-CF | Gomez Garage Studio","seo_title_ro":"Set 3 suporturi tealight negre PLA-CF | Gomez Garage Studio","seo_description_en":"Three sculptural tealight holders with twisted geometric surfaces, 3D printed in matte black PLA-CF. Original design in 10, 8 and 6 cm heights. Candles not included.","seo_description_ro":"Trei suporturi tealight cu fatete rasucite, printate din PLA-CF negru mat. Design original, cu inaltimi de 10, 8 si 6 cm. Lumanarile nu sunt incluse.","specs_ro":[{"key":"Set","value":"Trei suporturi"},{"key":"Inaltimi","value":"10 / 8 / 6 cm"},{"key":"Latime baza","value":"6 cm fiecare"},{"key":"Locas tealight","value":"Diametru 39 mm"},{"key":"Material","value":"PLA-CF negru"},{"key":"Inclus","value":"Doar suporturile; fara lumanari"}]}'
FROM products WHERE slug = 'tealight-holder-set-black' AND @ggs_do_reset = 1;

INSERT INTO products
  (slug, name_en, name_ro, material, category, price_eur, stock, featured, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro, specs, status, stock_threshold, images)
SELECT
  'tealight-holder-set-rainbow-one-of-a-kind',
  'Rainbow Tealight Holder Set of 3 - One of a Kind',
  'Set 3 suporturi tealight Rainbow - Unicat',
  'Rainbow PLA',
  'home',
  34,
  1,
  0,
  'One rainbow tealight holder set, exactly as photographed. Three twisted geometric forms in graduated heights, 3D printed in rainbow PLA. Candles not included.',
  'Un singur set Rainbow, exact cel fotografiat. Trei suporturi cu fatete rasucite si inaltimi diferite, printate din PLA rainbow. Lumanarile nu sunt incluse.',
  'The set you see is the set you receive. Three twisted geometric forms combine yellow, teal, violet and warm copper tones, with a different gradient across each holder.

ORIGINAL DESIGN BY GOMEZ GARAGE STUDIO
Designed from scratch in CAD and 3D printed by Gomez Garage Studio in Romania.

ONE-OFF COLOUR ARRANGEMENT
This listing is for the exact three holders shown in the real product photographs. Only one set is available in this colour arrangement. The colour comes from the rainbow PLA filament itself. Fine printed layers and surface texture remain visible. The GGS3D maker''s mark is on the underside.

THE SET
Three holders, 10, 8 and 6 cm high. Each base is 6 cm across, with a 39 mm recess for a tealight. Check the outside diameter of your tealight cup before ordering.

WHAT IS INCLUDED
One complete set of three holders. Candles and decorative props are not included. Quantity 1 means one complete set of three.

USE AND CARE
Keep tealights in their original metal cups; do not pour wax directly into the holders. Place on a stable, heat-resistant surface away from flammable objects. Never leave burning candles unattended. Wipe the holders with a soft, slightly damp cloth; do not put in the dishwasher.',
  'Setul din fotografii este setul pe care il primesti. Trei forme geometrice rasucite combina galben, turcoaz, violet si tonuri calde de cupru, cu treceri de culoare diferite pe fiecare suport.

DESIGN ORIGINAL GOMEZ GARAGE STUDIO
Proiectat de la zero in CAD si printat 3D de Gomez Garage Studio in Romania.

COMBINATIE UNICA DE CULORI
Listarea este pentru cele trei suporturi din fotografiile reale. Este disponibil un singur set cu aceasta distributie a culorilor. Culorile provin din filamentul PLA rainbow. Straturile si textura printului raman vizibile. Marcajul GGS3D se afla pe partea inferioara.

SETUL
Trei suporturi cu inaltimi de 10, 8 si 6 cm. Fiecare baza masoara 6 cm, iar locasul pentru tealight are 39 mm. Verifica diametrul exterior al cupei lumanarii inainte de comanda.

CE PRIMESTI
Un set complet de trei suporturi. Lumanarile si recuzita nu sunt incluse. Cantitatea 1 reprezinta un set complet de trei suporturi.

UTILIZARE SI INTRETINERE
Pastreaza lumanarile in cupele metalice originale; nu turna ceara direct in suport. Asaza pe o suprafata stabila, rezistenta la caldura, departe de obiecte inflamabile. Nu lasa lumanarile aprinse nesupravegheate. Sterge suporturile cu o laveta moale, putin umeda; nu le pune in masina de spalat vase.',
  'An original Gomez Garage Studio design, created from scratch in CAD and 3D printed in Romania using rainbow PLA. The multicolour filament creates the actual transitions across this exact set.',
  'Design original Gomez Garage Studio, proiectat de la zero in CAD si printat 3D in Romania din PLA rainbow. Filamentul multicolor formeaza trecerile de culoare ale acestui set.',
  '[{"key":"Set","value":"Three holders"},{"key":"Heights","value":"10 / 8 / 6 cm"},{"key":"Base width","value":"6 cm each"},{"key":"Tealight recess","value":"39 mm diameter"},{"key":"Material","value":"Rainbow PLA"},{"key":"Included","value":"Holders only; candles excluded"},{"key":"Availability","value":"One set in the photographed colour arrangement"}]',
  'draft',
  3,
  '[]'
WHERE @ggs_do_reset = 1;
INSERT INTO product_content (product_id, content)
SELECT id, '{"sku":"GGS-TLH3-PLA-RBW-001","seo_title_en":"Rainbow Tealight Holders Set of 3 - One of a Kind | Gomez Garage Studio","seo_title_ro":"Set suporturi tealight Rainbow unicat | Gomez Garage Studio","seo_description_en":"One rainbow tealight holder set, exactly as photographed. Three twisted geometric forms in graduated heights, 3D printed in rainbow PLA. Candles not included.","seo_description_ro":"Un singur set Rainbow, exact cel fotografiat. Trei suporturi cu fatete rasucite si inaltimi diferite, printate din PLA rainbow. Lumanarile nu sunt incluse.","specs_ro":[{"key":"Set","value":"Trei suporturi"},{"key":"Inaltimi","value":"10 / 8 / 6 cm"},{"key":"Latime baza","value":"6 cm fiecare"},{"key":"Locas tealight","value":"Diametru 39 mm"},{"key":"Material","value":"PLA rainbow"},{"key":"Inclus","value":"Doar suporturile; fara lumanari"},{"key":"Disponibilitate","value":"Un singur set in combinatia de culori fotografiata"}]}'
FROM products WHERE slug = 'tealight-holder-set-rainbow-one-of-a-kind' AND @ggs_do_reset = 1;

INSERT INTO settings (key_name, value)
SELECT 'catalog_reset_20261107', 'installed' WHERE @ggs_do_reset = 1
ON DUPLICATE KEY UPDATE key_name = settings.key_name;
COMMIT;
SELECT CASE
  WHEN @ggs_reset_installed > 0 THEN 'Already installed - no changes made'
  WHEN @ggs_published_count > 0 THEN 'NOT reset - published products exist'
  ELSE 'Installed - seven draft products ready for photos and videos'
END AS catalog_result;
