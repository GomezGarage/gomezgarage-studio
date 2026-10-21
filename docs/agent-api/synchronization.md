# Sincronizare și timp

## Filtre stricte

`limit` implicit 50, 1–100, decimal canonic fără semn sau zero inițial; `cursor`
maximum 4096 caractere. Query string maximum 8192 bytes. Filtre comune:
`updated_from` inclusiv și `updated_before` exclusiv, `status`. Produse: `category`
(desk/display/automotive/other). Comenzi: `created_from` inclusiv și
`created_before` exclusiv. Statusurile sunt în OpenAPI. Parametrii necunoscuți,
goale, repetați sau arrays sunt respinși cu 422. Nu există sortare arbitrară.

Toate datele de intrare sunt exact `YYYY-MM-DDTHH:MM:SSZ`, UTC, fără fracțiuni sau
alte offseturi. Intervalele inversate/goale sunt 422. Răspunsurile folosesc același
format. `generated_at` vine din ceasul PHP; limita sincronizării vine din ceasul DB.
Ceasurile trebuie sincronizate înainte de activare.

## Model de timp verificabil

Schema locală are `TIMESTAMP(0)` cu `DEFAULT CURRENT_TIMESTAMP` și
`ON UPDATE CURRENT_TIMESTAMP`. Timezone-ul sesiunilor istorice **nu este cunoscut**;
nu presupunem Europe/Bucharest. Pentru TIMESTAMP, MySQL stochează instanțele în UTC
și convertește la citire în timezone-ul sesiunii. API-ul setează **doar propria
sesiune** la `+00:00`, fără să schimbe site-ul sau datele. Vezi
[documentația MySQL](https://dev.mysql.com/doc/refman/8.4/en/time-zone-support.html).

Activarea cere verificarea tipurilor, preciziei, defaulturilor/ON UPDATE, timezone-ului
scriitorilor și eventualelor importuri istorice. `timestamp_storage_verified=false`
blochează conectarea. În plus, la fiecare conectare se verifică metadata celor patru
coloane created_at/updated_at: numai TIMESTAMP cu precizie 0 este acceptat.
DATETIME, precizia fracționară sau câmpuri lipsă produc 503; trebuie un patch separat,
nu o conversie implicită. API-ul nu poate corecta instanțe istorice introduse greșit.

## Cursor și concurență

1. Prima cerere fixează `sync_before = min(updated_before cerut, UTC DB − 5 secunde)`.
   Selectează `updated_at < sync_before`, cu filtrele cerute.
2. Ordinea este crescătoare `(updated_at,id)`. Se citesc `limit+1` rânduri; cel în
   plus stabilește existența următoarei pagini. Cursorul reține ultima pereche
   livrată, limita temporală, filtrele normalizate, ruta, identitatea și expirarea.
3. Cursorul este base64url + HMAC-SHA256, **semnat, nu criptat**. Nu conține tokenul
   sau datele clientului. Clientul îl tratează opac. Repetă aceleași filtre și limită;
   ordinea parametrilor nu contează. Modificarea lor, utilizarea pe altă rută/
   identitate, alterarea semnăturii sau expirarea după 15 minute produce 422.
   Autorizația și scopes se reverifică pe fiecare pagină, inclusiv după revocare.
4. Clientul face upsert după `(source,id)`, în ordine secvențială a paginilor. După
   terminarea tuturor paginilor salvează `sync_before`. Următoarea parcurgere începe
   cu `updated_from = ultimul sync_before − 60 secunde` (suprapunere de pilot).
   Pentru aceeași secundă și același id acceptă ultima citire, nu elimină actualizarea
   doar pentru că timestampul este egal. Nu avansează checkpointul la eroare.
5. Dacă un rând încă necitit se modifică și trece de limita temporală, apare în
   următoarea parcurgere. Testul simulează acest caz și egalitățile de timestamp.
   Pentru catalog complet folosește `status=all` și scope-ul de draft. Pentru comenzi
   folosește `status=all`, fără filtre de created_at; intervalele sunt utile raportării.

**Acesta nu este un snapshot tranzacțional între cereri și nu garantează captarea
oricărei schimbări.** Tranzacțiile care fac commit târziu cu timestamps vechi,
backdatingul/importurile, un scriitor care nu actualizează updated_at sau schimbări
multiple în aceeași secundă pot depăși suprapunerea. Nu există change log/tombstones
și nu se observă tranzițiile intermediare. O mutare published→draft dispare dintr-un
feed doar published; nu poate fi tratată automat ca ștergere.

Pentru pilot: reconciliere completă periodică (propunere zilnic și după întreruperi,
expirarea cursorului sau schimbări de scopes), fără filtre incrementale, apoi
verificarea absențelor într-o parcurgere completă ulterioară. Absența într-o pagină
nu dovedește ștergerea. După un curs expirat, reia din checkpointul persistent cu
suprapunere, nu din ultimul id văzut. Păstrează metadatele ultimei sincronizări și
marchează raportul incomplet/stale când o parcurgere eșuează. Garanțiile de evenimente
fără pierderi ar cere un change log separat, în afara acestui patch.

## Retry și limite

60 cereri autentificate/identitate/minut UTC, cumulat pe cele trei rute pe **un singur
host**. Un fixed window poate admite 120 cereri de o parte și alta a graniței de minut.
Fără rate limiting distribuit. Contorul are lock exclusiv, stocare locală privată și
maximum un fișier per identitate provisionată. Contenția/eroarea de stocare produce
503; depășirea produce 429 și Retry-After până la minutul următor. Nu se creează fișiere
pentru tokenuri invalide. Limitarea traficului neautentificat se configurează în web
server/WAF înainte de expunere; aplicația nu pretinde protecție DDoS.

Conexiune PDO: timeout 3 secunde; query: MySQL `max_execution_time=3000` sau MariaDB
`max_statement_time=3`. Acestea nu garantează un deadline absolut al întregii cereri.
Hostul trebuie să limiteze cererea/workerul la aproximativ 10 secunde, clientul la 15.
Răspunsul JSON este limitat la 2 MiB după serializare; un rând/pagină prea mare produce
503 (limita nu garantează consum maxim de memorie în timpul citirii).
Pentru 429/503 sau timeout de transport, maximum 3 reîncercări cu jitter și backoff,
respectând Retry-After. Nu retry automat 401/403/405/422. La 503 persistent, verifică
configurația/schema/datele, nu continua polling agresiv.
