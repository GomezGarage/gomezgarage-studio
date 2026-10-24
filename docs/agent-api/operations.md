# Configurare, deploy și rollback — plan neexecutat

## Configurare propusă pentru review

API-ul este dezactivat implicit. Nu reutilizează `config.php`, credențiale admin,
SMTP sau Revolut. Planul de stocare/rotație de mai jos este implementat local pentru
review; aprobarea și provisioningul efectiv preced activarea.

1. Pe hostingul site-ului, pregătește un fișier JSON **în afara document root și a
   repository-ului**, pornind din [config.example.json](config.example.json). Exemplul
   are placeholders deliberat invalide, `enabled: false` și credential inactiv. Pe cPanel,
   când proiectul este direct în `public_html`, calea implicită este
   directorul părinte al document root-ului activ, de regulă
   `/home/<cpanel-user>/private/studio-agent/config.json` când document root-ul este
   `/home/<cpanel-user>/public_html`; se poate crea din File Manager în afara web root.
   Verifică document root-ul domeniului în cPanel înainte de folosire. Directorul contorului
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
5. Pune în `database` credențialele contului de serviciu deja creat pentru API. Aplicația
   nu rulează GRANT/REVOKE și nu schimbă permisiunile acelui cont. Citirile folosesc o
   sesiune PDO read-only; scrierile de draft folosesc implicit o conexiune separată cu
   același cont. Astfel se păstrează accesul DB existent, iar API-ul oferă numai funcțiile
   comerciale definite explicit. Nu este nevoie de SQL pentru permisiuni; tabela tehnică
   de idempotency se creează doar dacă lipsește.
6. `write_database` este opțional numai dacă alegi tu ulterior un cont separat. Pentru
   deploy-ul funcțiilor draft trebuie să existe migrarea
   `20261024_agent_product_draft_requests.sql`. Câmpurile și operațiile expuse de API
   rămân limitate la drafturi de conținut; asta nu restrânge drepturile contului DB.

Coloane citite de Repository: products — id, slug, status, name_ro/en, desc_short_ro/en, desc_long_ro/en,
how_made_ro/en, material, category, price_eur, images, specs, stock, updated_at,
created_at; orders — id, order_number, status, payment_method, created_at,
updated_at, subtotal_eur, shipping_eur, total_eur, items, shipping_address,
tracking_number și customer_email; product_views_daily — product_id, view_date și
view_count. Acestea sunt cerințele de schemă ale funcțiilor API, nu o cerere de schimbare
a permisiunilor contului. `customer_email` este folosit intern numai pentru HMAC-ul
`customer_ref`; răspunsul nu expune email, telefon sau adresa completă. API-ul nu schimbă
granturile DB. Cu contul existent al serviciului, folosește drepturile pe care acesta le
are deja.

Conexiunile DB sunt permise doar prin localhost/loopback pe același hosting. Hostingul
cu DB remote necesită verificarea TLS și un patch dedicat, nu extinderea implicită a
DSN-ului. Declară engine `mysql` sau `mariadb` după verificare. Tipurile de timp sunt
verificate la conectare; `timestamp_storage_verified=true` se setează numai după
verificarea semanticii descrise în [sincronizare](synchronization.md). Sesiunea nouă
de citire folosește UTC și `SET SESSION TRANSACTION READ ONLY`; conexiunea draft folosește
același cont sau contul opțional separat, fără să modifice granturile DB ori sesiunile
checkoutului. Consultă
[MySQL SET TRANSACTION](https://dev.mysql.com/doc/refman/8.4/en/set-transaction.html).

Health verifică credentialul, scope-ul și disponibilitatea contorului; este **liveness**,
nu dovada disponibilității DB. Readiness se probează
în staging cu date sintetice pe products/orders.

## Audit și limite de găzduire

Audit JSON în error_log configurat al PHP: identity sau null, request_id, rută și metodă
fixe, status/rezultat, timp UTC, durată ms, număr rânduri. Pentru 5xx se loghează un
`failure_stage` fix (`configuration`, `rate_limiter`, `database` sau `response_encoding`)
și răspunsul include headerul `X-Agent-Failure-Stage` (include și `audit` dacă sinkul
însuși e indisponibil). Nu se loghează textul excepției. `health.php` nu deschide conexiune
DB, astfel că etapa `database` nu se aplică acelei rute. Nu se loghează token/hash/cursor,
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

Contractul 1.2.0 necesită tabela agregată `product_views_daily`. Pentru o bază existentă,
rulează o singură dată migrarea izolată `database/migrations/20261024_product_views_daily.sql`
în phpMyAdmin/cPanel dacă tabela lipsește. Nu sunt necesare GRANT-uri noi dacă userul API
existent are deja acces complet. Endpointul de colectare folosește conexiunea website-ului;
browserul nu primește credential DB.

Extensia draft din contractul 1.3.0 necesită tabela tehnică
`agent_product_draft_requests`, creată o singură dată prin migrarea izolată
`database/migrations/20261024_agent_product_draft_requests.sql` dacă lipsește. Contul DB
existent este folosit cu drepturile lui actuale; API-ul nu cere GRANT nou. Fără tabelă,
scope API sau funcțiile de draft din cod, rutele de scriere nu pot funcționa, iar rutele
read-only rămân independente.
`write_database` nu este obligatoriu.

Nu rerula `database/schema.sql`: include seeduri/setări/admin și nu este migrator. Schema live poate fi diferită:
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
   schema/timp/TLS/loguri și posibilitatea izolării configului. PHP 8.0+ și
   extensiile PDO MySQL, JSON, hash sunt necesare; nu sunt necesare pachete Composer noi.
3. Staging separat cu schema reprezentativă și numai date sintetice, fără credențiale
   de plăți/SMTP/curier. Probe pe motorul efectiv, inclusiv accesul DB al contului existent,
   timeoutul, JSON și refuzul metodelor pe web serverul efectiv. Testează rotația/revocarea.
4. Backup al versiunii instalate, configurației private și bazei prin mecanismul aprobat;
   restaurare verificată. Nu aplica migrarea înainte de aprobare/deploy. Păstrează prefixul API blocat/dezactivat.
5. Publică numai endpointurile `api/agent/v1/*.php`, `api/agent/.htaccess` dacă este
   compatibil, fișierele `includes/agent/*.php`, `api/record-product-view.php`,
   `includes/product-views.php` și frontendul `assets/js/shop.js`. Păstrează protecția
   existentă pe includes. **Nu publica tests, docs, fișiere temporare sau configul privat.**
6. După aprobarea activării, configurează credentialul/scopes și contul DB existent în
   fișierul privat, apoi activează prefixul.
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
înlocuiește verificarea configurației conexiunii și schemei mediului de deploy.
