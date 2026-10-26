-- Owner-authorized replacement of the old public catalog. Run AFTER home_category.sql.
-- Archive existing listings without deleting order history or product identifiers.
-- Replay does not reset completed products, prices, images, stock or publication status.
-- Readable UTF-8 text; apostrophes use standard SQL quoting (two apostrophes).
-- Source: database/catalog-products.json. Product data and import effects are unchanged.
SET NAMES utf8mb4;
START TRANSACTION;
SET @ggs_catalog_installed = (SELECT COUNT(*) FROM settings WHERE key_name = 'catalog_release_20261026');
UPDATE products SET status = 'draft', featured = 0
WHERE @ggs_catalog_installed = 0 AND slug NOT IN ('magsafe-apple-watch-dock', 'media-dock-organizer', 'treble-clef-headphone-stand', 'zippo-lighter-display-stand', 'tealight-holder-set-black', 'tealight-holder-set-rainbow-one-of-a-kind');
INSERT INTO products
  (slug, name_en, name_ro, desc_short_en, desc_short_ro, desc_long_en, desc_long_ro, how_made_en, how_made_ro, material, category, price_eur, stock, stock_threshold, images, specs, status, featured)
VALUES
(
  'magsafe-apple-watch-dock',
  'MagSafe & Apple Watch Stand - Dark Grey ASA-CF',
  'Suport MagSafe si Apple Watch - ASA-CF gri inchis',
  'A geometric stand for your MagSafe and Apple Watch charging setup. Printed in dark grey ASA-CF for a textured finish on your desk or bedside table.',
  'Un suport geometric pentru incarcatoarele MagSafe si Apple Watch. Printat din ASA-CF gri inchis, cu finisaj texturat, pentru birou sau noptiera.',
  'Give your everyday devices a place of their own. This MagSafe and Apple Watch stand brings two charging positions into one compact, angular form, with an inclined phone face and a raised watch support.

Recessed side panels and the fine texture of dark grey ASA-CF give the stand its industrial character. Cable routing at the rear helps keep the setup tidy, whether you use it beside your keyboard or on a nightstand.

An original Gomez Garage Studio design, developed in-house and 3D printed in Romania. This listing is for the stand; devices and charging accessories in the photographs illustrate the setup.',
  'Un loc dedicat pentru dispozitivele pe care le folosesti zilnic. Suportul MagSafe si Apple Watch reuneste doua pozitii de incarcare intr-o forma compacta, cu fata inclinata pentru telefon si suport inaltat pentru ceas.

Panourile laterale adancite si textura fina a materialului ASA-CF gri inchis ii dau un caracter industrial. Traseul cablurilor prin spate ajuta la pastrarea unui spatiu ordonat, pe birou sau pe noptiera.

Design original Gomez Garage Studio, proiectat in-house si printat 3D in Romania. Produsul prezentat este suportul; dispozitivele si accesoriile de incarcare din fotografii ilustreaza utilizarea.',
  'Designed from the first CAD model by Gomez Garage Studio and FDM printed in dark grey ASA-CF, an ASA composite containing carbon fibre. The visible print texture is part of the actual finish.',
  'Proiectat de Gomez Garage Studio, de la primul model CAD, si printat FDM din ASA-CF gri inchis, un compozit ASA cu fibra de carbon. Textura vizibila a printului face parte din finisajul real.',
  'ASA-CF Dark Grey',
  'desk',
  0,
  0,
  3,
  '[]',
  '[{"key":"Material","value":"ASA-CF"},{"key":"Colour","value":"Dark grey"},{"key":"Design","value":"MagSafe + Apple Watch charging stand"}]',
  'draft',
  1
),
(
  'media-dock-organizer',
  'Media Dock - USB, SD & microSD Desk Organizer',
  'Media Dock - Organizator USB, SD si microSD',
  'Keep USB drives, SD and microSD cards in view. A sloped desktop organizer with dedicated slots and a front accessory tray, printed in matte black PETG.',
  'Stickuri USB, carduri SD si microSD organizate la vedere. Suport de birou inclinat, cu sloturi dedicate si tava pentru accesorii, din PETG negru mat.',
  'Spend less time searching for the card or drive you need. Media Dock gathers your USB drives, SD cards and microSD cards into clearly separated rows, so your storage media stays visible and easy to reach.

The sloped face makes each row accessible, while the front tray gives small adapters and accessories a place to land. Its wide base and matte black PETG finish fit naturally into a photography workspace, editing desk or everyday office.

An original Gomez Garage Studio design, 3D printed in Romania. Media Dock provides passive storage for your drives and cards. The drives, cards and accessories shown demonstrate how it can be used.',
  'Gaseste mai repede cardul sau stickul de care ai nevoie. Media Dock aduna stickurile USB, cardurile SD si microSD pe randuri separate, astfel incat mediile de stocare sa ramana vizibile si la indemana.

Fata inclinata ofera acces la fiecare rand, iar tava frontala pastreaza adaptoarele si accesoriile mici intr-un singur loc. Baza lata si finisajul din PETG negru mat se potrivesc pe un birou de fotograf, intr-un spatiu de editare sau la biroul de zi cu zi.

Design original Gomez Garage Studio, printat 3D in Romania. Media Dock organizeaza mediile de stocare in locasuri dedicate. Stickurile, cardurile si accesoriile din fotografii ilustreaza utilizarea.',
  'Designed in-house and FDM printed in matte black PETG. The slots, sloped face and front tray are integrated into a single printed body with visible, fine layer texture.',
  'Proiectat in-house si printat FDM din PETG negru mat. Sloturile, fata inclinata si tava frontala sunt integrate intr-un singur corp printat, cu o textura fina a straturilor.',
  'PETG Matte Black',
  'desk',
  0,
  0,
  3,
  '[]',
  '[{"key":"Material","value":"PETG"},{"key":"Finish","value":"Matte black"},{"key":"Storage","value":"USB-A, USB-C, SD, microSD"},{"key":"Function","value":"Passive desktop organizer"}]',
  'draft',
  1
),
(
  'treble-clef-headphone-stand',
  'Treble Clef Headphone Stand - Black PLA-CF',
  'Suport casti Cheia Sol - PLA-CF negru',
  'A sculptural treble clef headphone stand for your desk or music corner. Printed in black PLA-CF, with a curved headband support and a broad base.',
  'Un suport de casti in forma de cheie sol, pentru birou sau coltul de muzica. Printat din PLA-CF negru, cu sprijin curbat pentru bentita si baza lata.',
  'Keep your headphones within reach and give your music corner a distinctive focal point. The flowing treble clef rises from a broad base, combining a familiar musical shape with a practical place to rest your headphones.

A curved saddle supports the headband at the top. Below it, the open loops keep the silhouette light and recognisable, even when the stand is empty. Black PLA-CF gives the piece a fine matte texture that works beside a piano, audio equipment or a clean desktop setup.

An original Gomez Garage Studio design, developed and 3D printed in Romania. Headphones shown in the photographs illustrate the stand in use.',
  'Pastreaza castile la indemana si da personalitate coltului tau de muzica. Cheia sol se ridica dintr-o baza lata si transforma un simbol muzical familiar intr-un suport practic pentru casti.

Sprijinul curbat din partea superioara sustine bentita, iar buclele deschise pastreaza silueta usor de recunoscut chiar si atunci cand suportul este gol. PLA-CF negru ii ofera o textura fina, mata, potrivita langa pian, echipamente audio sau pe un birou ordonat.

Design original Gomez Garage Studio, proiectat si printat 3D in Romania. Castile din fotografii ilustreaza utilizarea suportului.',
  'An original CAD design by Gomez Garage Studio, FDM printed in black PLA-CF, a PLA composite containing carbon fibre. The printed layers preserve the flowing profile and subtle surface texture.',
  'Design CAD original Gomez Garage Studio, printat FDM din PLA-CF negru, un compozit PLA cu fibra de carbon. Straturile printate contureaza profilul curbat si textura discreta a suprafetei.',
  'PLA-CF Black',
  'desk',
  0,
  0,
  3,
  '[]',
  '[{"key":"Material","value":"PLA-CF"},{"key":"Colour","value":"Black"},{"key":"Shape","value":"Treble clef"}]',
  'draft',
  0
),
(
  'zippo-lighter-display-stand',
  'Zippo Lighter Display Stand - 12 Lighters & Accessory Storage',
  'Stand pentru 12 brichete Zippo si accesorii',
  'Display 12 Zippo lighters across three stepped rows, with a side compartment for a fluid can and a front accessory tray. Printed in matte black PETG.',
  'Expune 12 brichete Zippo pe trei randuri in trepte, cu un compartiment lateral pentru recipientul de benzina si tava pentru accesorii. Din PETG negru mat.',
  'Give your lighter collection a place where every design can be seen. This display stand holds up to twelve standard Zippo lighters in three stepped rows, with four individual pockets per row.

A dedicated compartment on the right keeps a 125 ml lighter-fluid can beside the collection, while the shallow front tray holds small maintenance accessories. The matte black PETG body provides a restrained backdrop for brushed metal, engraved finishes and colourful artwork.

Designed for a collector''s shelf, cabinet or personal display. An original Gomez Garage Studio design, 3D printed in Romania. Lighters, fluid can and accessories in the photographs are display props.',
  'Ofera colectiei tale un loc in care fiecare model poate fi vazut. Standul gazduieste pana la douasprezece brichete Zippo standard, pe trei randuri in trepte, cu patru locasuri individuale pe fiecare rand.

Compartimentul din dreapta pastreaza un recipient de benzina de 125 ml langa colectie, iar tava frontala ofera spatiu pentru accesoriile mici de intretinere. Corpul din PETG negru mat pune in valoare metalul periat, gravurile si modelele colorate ale brichetelor.

Gandit pentru raftul unui colectionar, o vitrina sau un spatiu personal de expunere. Design original Gomez Garage Studio, printat 3D in Romania. Brichetele, recipientul si accesoriile din fotografii sunt recuzita. Suportul este un produs independent, compatibil cu brichete Zippo.',
  'Designed from scratch in CAD by Gomez Garage Studio and FDM printed in matte black PETG. The stepped pockets, side compartment and accessory tray are integrated into the printed body.',
  'Proiectat de la zero in CAD de Gomez Garage Studio si printat FDM din PETG negru mat. Locasurile in trepte, compartimentul lateral si tava pentru accesorii sunt integrate in corpul printat.',
  'PETG Matte Black',
  'display',
  0,
  0,
  3,
  '[]',
  '[{"key":"Material","value":"PETG"},{"key":"Finish","value":"Matte black"},{"key":"Capacity","value":"12 standard Zippo lighters; 3 rows of 4"},{"key":"Accessory storage","value":"125 ml fluid can compartment + front tray"}]',
  'draft',
  1
),
(
  'tealight-holder-set-black',
  'Sculptural Tealight Holder Set - 3 Black PLA-CF Holders',
  'Set 3 suporturi lumanari tealight - PLA-CF negru',
  'Three sculptural tealight holders in different heights, with gently twisting facets and a matte black PLA-CF finish. A coordinated accent for a modern interior.',
  'Trei suporturi tealight de inaltimi diferite, cu fatete usor rasucite si finisaj din PLA-CF negru mat. Un accent decorativ pentru un interior modern.',
  'Create a small composition of light and shadow. This set brings together three tealight holders in different heights, each shaped by gently twisting facets that catch the light from a different angle.

Arrange them as a group on a console or shelf, or space them along a table. The matte black PLA-CF finish gives the sculptural forms a quiet, tactile character that fits clean contemporary interiors.

An original Gomez Garage Studio design, 3D printed in Romania. The listing is for one set of three holders.',
  'O mica compozitie de lumina si umbra. Setul reuneste trei suporturi tealight de inaltimi diferite, fiecare conturat de fatete usor rasucite, care surprind lumina din unghiuri diferite.

Asaza-le impreuna pe o consola sau pe un raft, ori distribuite de-a lungul unei mese. Finisajul mat din PLA-CF negru da formelor sculpturale un caracter discret si o textura placuta vizual, potrivita in interioare contemporane.

Design original Gomez Garage Studio, printat 3D in Romania. Produsul este un set de trei suporturi.',
  'Designed in-house and FDM printed in black PLA-CF, a PLA composite containing carbon fibre. Fine print layers follow the faceted profile and remain visible in the finished surface.',
  'Proiectate in-house si printate FDM din PLA-CF negru, un compozit PLA cu fibra de carbon. Straturile fine urmaresc profilul fatetat si raman vizibile in suprafata finisata.',
  'PLA-CF Black',
  'home',
  0,
  0,
  3,
  '[]',
  '[{"key":"Material","value":"PLA-CF"},{"key":"Colour","value":"Black"},{"key":"Set","value":"3 holders, different heights"}]',
  'draft',
  0
),
(
  'tealight-holder-set-rainbow-one-of-a-kind',
  'Rainbow Tealight Holder Set - One-of-a-Kind Trio',
  'Set 3 suporturi lumanari tealight Rainbow - Unicat',
  'A one-of-a-kind trio of sculptural tealight holders, printed in rainbow PLA. Three heights and flowing colour transitions make this exact set unique.',
  'Un set unicat de trei suporturi tealight sculpturale, printate din PLA rainbow. Trei inaltimi si treceri de culoare care individualizeaza acest set.',
  'Bring colour into a clean interior with a small sculptural centrepiece. Three tealight holders in different heights combine gently twisting facets with transitions of turquoise, violet and golden tones.

This listing is for the single rainbow set shown in the original product photographs. The colour placement belongs to these exact three pieces; it is not a choice of standard colour variants. Display them together to follow the colours across the group.

An original Gomez Garage Studio design, 3D printed in rainbow PLA in Romania. One set of three holders is available.',
  'Adu culoare intr-un interior simplu, cu un mic ansamblu sculptural. Cele trei suporturi tealight, de inaltimi diferite, combina fatetele usor rasucite cu treceri intre turcoaz, violet si tonuri aurii.

Aceasta listare este pentru singurul set rainbow prezentat in fotografiile originale de produs. Distributia culorilor apartine exact acestor trei piese; nu este o selectie de variante de culoare standard. Expuse impreuna, culorile leaga vizual intregul grup.

Design original Gomez Garage Studio, printat 3D din PLA rainbow in Romania. Este disponibil un singur set de trei suporturi.',
  'Designed in-house and FDM printed in rainbow PLA. The multicolour filament creates transitions through each print; the three pieces shown form the unique set offered here.',
  'Proiectate in-house si printate FDM din PLA rainbow. Filamentul multicolor creeaza treceri de culoare pe parcursul printului; cele trei piese prezentate formeaza setul unicat oferit aici.',
  'PLA Rainbow',
  'home',
  0,
  1,
  1,
  '[]',
  '[{"key":"Material","value":"PLA Rainbow"},{"key":"Set","value":"3 holders, different heights"},{"key":"Edition","value":"One of a kind - one set available"}]',
  'draft',
  0
)
ON DUPLICATE KEY UPDATE slug = products.slug;
INSERT INTO settings (key_name, value) VALUES ('catalog_release_20261026', 'installed')
ON DUPLICATE KEY UPDATE key_name = settings.key_name;
COMMIT;
