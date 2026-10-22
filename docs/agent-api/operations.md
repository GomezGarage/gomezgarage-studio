# Configurare, deploy și rollback — plan neexecutat

## Configurare propusă pentru review

API-ul este dezactivat implicit. Nu reutilizează `config.php`, credențiale admin,
SMTP sau Revolut. Planul de stocare/rotație de mai jos este implementat local pentru
review; aprobarea și provisioningul efectiv preced activarea.

1. Pe hostingul site-ului, pregătește un fișier JSON **în afara document root și a
   repository-ului**, pornind din [config.example.json](config.example.json). Exemplul
   are placeholders deliberat invalide, `enabled: false` și credential inactiv. Pe cPanel,
   când proiectul este direct în `public_html`, calea implicită este
   `/home/<cpanel-user>/private/studio-agent/config.json`; se poate crea din File Manager
   în afara `public_html`. Dacă proiectul nu este chiar în rădăcina documentului, verifică
   `Config::defaultPath()` și document root înainte de folosire. Directorul contorului
   trebuie să existe, să fie privat (0700), local, accesibil numai utilizatorului PHP.
   Fișierul config: 0600, sau 0640 cu grup dedicat minim; accesul pentru others este
   respins. Nu îl include în arhive de deploy sau backup necriptat.
2. Generează privat un token cu 32 bytes CSPRNG codificați base64url fără padding.
   În config păstrează numai SHA-256 hex al tokenului; livrează valoarea brută numai
   serviciului Business prin secret storage. Generează separat 32 bytes pentru
   `cursor_key_hex`. Nu folosi parole umane, token admin sau chei Revolut. Nu pune
   tokenul în URL, shell arguments/history, `.env` versionat, chat, raport sau log.
3. Definește `id` stabil fără PII (1–64 litere/cifre/_/-), expirare UTC și scopes
   minimale. `products.drafts.read` este adițional la `products.read`; nu îl înlocuiește.
   Configurația este recitită la fiecare cerere. Rotație: adaugă identitate/token nou,
   verifică utilizarea în staging, dezactivează/elimină credentialul vechi; restartul
   nu este necesar pentru schimbări JSON. Actualizează fișierul prin înlocuire atomică,
   păstrând permisiunile. Cererile deja pornite pot termina după revocare. Pentru
   oprire urgentă blochează prefixul la web server. Rotația cheii cursor invalidează
   toate cursoarele; clientul reia sincronizarea. Expirarea se reevaluează la fiecare
   pagină. Propunere pilot: rotație cel târziu la 30 zile și imediat la incident.
4. Pe cPanel, configurația privată implicită activează API-ul numai când conține
   `enabled: true`; nu sunt necesare variabile PHP de mediu. Pe alte hostinguri se poate
   furniza `GGS_AGENT_CONFIG_FILE`, iar `GGS_AGENT_ENABLED=0` este kill switch opțional.
   Nu configura valori prin HTTP. `HTTPS=on` trebuie stabilit de web server/TLS termination de încredere;
   `X-Forwarded-Proto` de la client este ignorat. Nu există bypass HTTP de dezvoltare
   în codul de producție. Fișierul Apache `api/agent/.htaccess` folosește CGIPassAuth;
   verifică suportul/AllowOverride înainte de publicare. Pe Nginx/FastCGI configurează
   echivalentul pentru Authorization și HTTPS și refuză accesul la includes/tests/docs.
5. Contul DB al API-ului este separat, cu **SELECT numai pe coloanele necesare din
   products și orders**, fără grant option, DML, DDL, FILE, proceduri sau acces la
   admin_users/settings. Acest cont și SQL-ul nu sunt oferite clientului API. DBA verifică
   granturile în staging. Nu se folosește contul existent al checkoutului.

Coloane products: id, slug, status, name_ro/en, desc_short_ro/en, desc_long_ro/en,
how_made_ro/en, material, category, price_eur, images, specs, stock, updated_at,
created_at. Coloane orders: id, order_number, status, payment_method, created_at,
updated_at, subtotal_eur, shipping_eur, total_eur, items, shipping_address,
tracking_number. Metadata information_schema.COLUMNS trebuie să fie vizibilă pentru
cele patru coloane de timp. Accesul la shipping_address permite proiecția țării în
SQL; API-ul nu exportă restul adresei. Pentru izolare și mai strictă poate fi evaluat
ulterior un view, separat de primul patch.

Conexiunea DB este permisă doar prin localhost/loopback pe același hosting. Hostingul
cu DB remote necesită verificarea TLS și un patch dedicat, nu extinderea implicită a
DSN-ului. Declară engine `mysql` sau `mariadb` după verificare. Tipurile de timp sunt
verificate la conectare; `timestamp_storage_verified=true` se setează numai după
verificarea semanticii descrise în [sincronizare](synchronization.md). Sesiunea nouă
folosește UTC și `SET SESSION TRANSACTION READ ONLY`; granturile SELECT rămân bariera
principală, fără afectarea sesiunilor checkoutului. Consultă
[MySQL SET TRANSACTION](https://dev.mysql.com/doc/refman/8.4/en/set-transaction.html).

Health verifică credentialul, scope-ul și disponibilitatea contorului; este **liveness**,
nu dovada disponibilității DB sau a corectitudinii granturilor. Readiness se probează
în staging cu date sintetice pe products/orders.

## Audit și limite de găzduire

Audit JSON în error_log configurat al PHP: identity sau null, request_id, rută fixă,
status/rezultat, timp UTC, durată ms, număr rânduri. Nu se loghează token/hash/cursor,
query string, body, date client, configurație sau mesajele driverului. Nu activa
logging de Authorization, Cookie, request body ori dump-uri PHP/SQL la web server,
proxy, APM sau client. Configurează logurile în afara web root cu acces minim și
rotație; verifică retenția înainte de pilot. Un sink de audit care raportează eroare
produce 503 fără date. Persistența reală a error_log rămâne responsabilitatea hostului.

Setează display_errors=Off, zend.exception_ignore_args=On și deadline de worker/request.
Validarea engine-ului și a limitelor de query este obligatorie. Documentația
[MySQL max_execution_time](https://dev.mysql.com/doc/refman/8.4/en/server-system-variables.html#sysvar_max_execution_time)
și [MariaDB max_statement_time](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/aborting-statements)
descrie timeouturi de query, nu garanții stricte de resurse. Limitează traficul
neautentificat la prefix în hosting/WAF. Un host multiplu sau storage NFS nu este
acoperit de contorul local cu flock.

## Migrare

**Nicio migrație necesară sau executată.** Nu modifica `database/schema.sql` și nu îl
rerula: include seeduri/setări/admin și nu este migrator. Schema live poate fi diferită:
verifică explicit existența payment_method, items, timestamps/defaulturi/ON UPDATE și
coloanele proiectate. O lipsă produce 503; nu există fallback care fabrică date.

Nu se adaugă indecși automat. Pentru volum real, înainte de activare inspectează EXPLAIN
pentru `(updated_at,id)` și filtrele status/created_at pe o copie sintetică reprezentativă.
Indecșii compoziți pot fi necesari; dacă planul de execuție/timeoutul nu este acceptabil,
pregătește migrare separată versionată cu test și rollback. Nu dezactiva timeoutul pentru
a ascunde lipsa unui index. Nu se schimbă modelul de stoc/plată/limbă în acest patch.

## Deploy ulterior, numai după aprobare

1. Review al patchului, contractului și regulilor de credential; confirmare scope.
2. Identificare versiune instalată/PHP/DB/Apache sau Nginx. Verificare diferențe checkout–live,
   schema/timp/granturi/TLS/loguri și posibilitatea izolării configului. PHP 8.0+ și
   extensiile PDO MySQL, JSON, hash sunt necesare; nu sunt necesare pachete Composer noi.
3. Staging separat cu schema reprezentativă și numai date sintetice, fără credențiale
   de plăți/SMTP/curier. Probe pe motorul efectiv, inclusiv granturile SELECT, timeoutul,
   readonly, JSON și refuzul metodelor pe web serverul efectiv. Testează rotația/revocarea.
4. Backup al versiunii instalate și al configurației private prin mecanismul aprobat;
   restaurare verificată. DB nu se modifică. Păstrează prefixul API blocat/dezactivat.
5. Publică numai cele trei endpointuri `api/agent/v1/*.php`, `api/agent/.htaccess` dacă
   este compatibil, și cele cinci fișiere `includes/agent/*.php`. Păstrează protecția
   existentă pe includes. **Nu publica tests, docs, fișiere temporare sau configul privat.**
6. După aprobarea activării, provisionează secretul/granturile și activează prefixul.
   Probele live de citire au autorizare separată; nu folosi create-order, webhook,
   custom-order sau download-invoice pentru smoke test. Monitorizează răspunsuri/audit,
   performanță și continuitatea site-ului. Clientul API primește numai credentialul scoped.
7. Pornește sincronizarea și verifică raportul cu sursa, eticheta EUR și data sincronizării.
   Trimiterea WhatsApp/email și instalarea clientului nu aparțin acestui patch.

## Rollback și incidente

Blochează întâi prefixul `/studio/api/agent/` la hosting sau setează `enabled: false`
în fișierul privat (ori `GGS_AGENT_ENABLED=0` dacă variabilele de mediu sunt disponibile).
Dezactivează credentialele în fișierul privat, revocă userul DB de
serviciu dacă este compromis. Înlocuiește/elimină numai fișierele noi ale API-ului și
configurația lui de hosting, păstrând fișierele anterioare ale magazinului. Nu restaura
DB peste comenzi noi: nu a existat migrare sau scriere comercială de revenit.

Confirmă că prefixul este blocat și că magazinul existent funcționează prin verificări
fără efecte comerciale. Păstrează auditul privat pentru investigație conform politicii
aprobate; nu lipi loguri brute în chat. La reactivare folosește credential nou dacă a
existat expunere și reia reconcilierea; rapoartele vechi trebuie marcate stale.

Proba ulterioară pe MySQL 8.4.13 izolat este documentată în
[mysql-verification.md](mysql-verification.md). Nu înlocuiește identificarea hostingului:
EXPLAIN pe schema locală confirmă lipsa unui index potrivit pentru sincronizare.

Pentru MariaDB folosește `database.engine = "mariadb"`; pentru MySQL,
`database.engine = "mysql"`. [Matricea testată local](hosting-verification.md) nu
înlocuiește verificarea configurației, schemei și granturilor mediului de deploy.
