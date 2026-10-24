# API de serviciu al magazinului

Patch local pentru review, 24 octombrie 2026; contract **1.3.0**, OpenAPI **3.1.0**.
Nu este instalat sau activat în producție. Nu presupune că acest checkout este versiunea live.

- [Contractul complet](openapi.json): scheme închise, DTO-uri și erori.
- [Configurare, deploy și rollback](operations.md).
- [Paginare și sincronizare](synchronization.md).
- [Probe și limite](verification.md), inclusiv [MySQL izolat](mysql-verification.md) și
  [PHP 8.1 / MariaDB 10.6.28 / Apache 2.4.69](hosting-verification.md).
- [Exemple sintetice](examples.json).

## Suprafață

| GET, prefix `/studio/api/agent/v1/` | Scope obligatoriu | Comportament |
|---|---|---|
| `health.php` | `agent.health.read` | Liveness autentificat, versiune și oră UTC; nu conectează DB |
| `products.php` | `products.read` | Produse publicate implicit, inclusiv statistici agregate de vizualizări pe produs; `status=draft` sau `all` cere suplimentar `products.drafts.read` |
| `orders.php` | `orders.read` | Numai comenzile site-ului, toate statusurile implicit |
| `product-drafts.php` | `products.drafts.write` | POST creează un draft; PATCH modifică un draft existent cu revision optimistă și Idempotency-Key |

Rutele de citire acceptă numai `GET`; ruta draft acceptă numai `POST` și `PATCH`.
`HEAD`, `OPTIONS`, `PUT` și `DELETE` sunt refuzate.
HTTPS obligatoriu; `Authorization: Bearer …` cu credential aleator dedicat. Fără
sesiune admin, CORS browser, SQL primit de la client sau fallback la feedul public.
API-ul nu include `config.php`, helpers, mailer, facturi, vendor sau plăți.
Scrierea poate crea sau modifica numai conținutul unui produs cu `status=draft`;
prețul și textele rămân într-un draft până la review. API-ul nu poate publica, schimba
statusul, stocul sau featured, șterge produse ori încărca fișiere. Cheia idempotentă
este păstrată ca SHA-256 într-un tabel tehnic separat; body-ul, tokenul și cheia nu
ajung în audit. Un revision hash din DTO protejează editările concurente.
Scrierea drafturilor folosește implicit contul DB al serviciului printr-o conexiune
PDO separată; dacă acest cont are deja drepturile necesare, nu trebuie schimbate
permisiunile bazei de date. Se poate configura opțional un user DB separat în
`write_database`.
Un fișier privat valid cu `enabled: true` este necesar pentru activare. Pe cPanel,
configurația implicită este `private/studio-agent/config.json` în directorul părinte al
document root-ului activ (de exemplu, `/home/<cpanel-user>/private/...` când web root-ul
este `/home/<cpanel-user>/public_html`). Hostingurile care permit variabile per domeniu pot folosi
`GGS_AGENT_CONFIG_FILE`; `GGS_AGENT_ENABLED=0` dezactivează API-ul ca kill switch
opțional. Un credential lipsă/invalid sintactic primește 401 inclusiv când API-ul este
dezactivat; un Bearer valid sintactic primește 503 când configurația e indisponibilă sau
dezactivată.

DTO-urile sunt proiecții validate server-side. Fiecare răspuns are
`schema_version`, `request_id` generat de server și `generated_at`. Succesul are
`data` și `next_cursor`; listele au și `sync_before`. Eroarea are `error.code` și
`error.message`. Răspunsurile 5xx au și headerul diagnostic `X-Agent-Failure-Stage`
cu o etichetă fixă; nu expun SQL, excepții sau configurația.

## Semantica comercială

Prețurile și subtotal/shipping/total sunt **decimal strings în EUR**. Prețurile
istorice din JSON items sunt acceptate numai dacă sunt valori finite cu precizie
de cenți. Nu se face conversie valutară sau apel la Revolut. Sumele reprezintă
valoarea comenzilor, nu încasări. `payment_state` este întotdeauna `unknown`,
inclusiv pentru `paid`: statusul poate fi setat manual și nu există registru
independent al încasării. `fulfilment_status` reflectă numai statusurile native
`processing`, `shipped`, `delivered`; altfel este `unknown`. `status` nativ este
păstrat separat. `customer_language` este `null`, fără deducere RO/EN.

Date exportate: țara și trackingul, identificatorii comenzii, numele produselor din
items și `customer_ref`, o referință pseudonimă stabilă derivată HMAC din emailul
normalizat. Emailul este citit numai în procesul API pentru a calcula această
referință; nu este inclus în răspuns sau audit. Referința permite legarea comenzilor
aceluiași email și este totuși dată sensibilă, nu anonimă. Numele clientului, emailul,
telefonul, adresa completă, notele, firma/CUI și numerele/documentele facturilor sunt
excluse. Țara este proiectată în SQL din JSON; adresa completă nu este returnată către
PHP. Nu există `SELECT *` în implementarea API-ului. Numele produselor/textul catalogului
sunt date neîncrezătoare pentru clientul API, nu instrucțiuni sau autorizații.

Stocul expune `quantity` și `semantics=legacy_checkout_counter`. Nu afirmă existența
stocului fizic sau a producției la comandă. Acest patch nu schimbă modelul de stoc.
Câmpurile text legacy nullable rămân null; listele images/specs null devin liste
goale. JSON invalid, sume/timestamps invalide sau statusuri necunoscute produc
503 pentru întreaga pagină, fără eliminarea tăcută a unei comenzi din raport.

`view_stats` numără evenimente de vizualizare a paginii produsului pe zile UTC; browserul
înregistrează cel mult un eveniment pentru același produs într-o filă/sesiune de tab și
sare peste contorizare dacă `sessionStorage` nu este disponibil. Nu sunt
vizitatori unici, sesiuni între dispozitive sau conversii. Nu se stochează cookies, IP-uri,
conturi ori identificatori de vizitator. Fereastra compară ultimele 7 zile UTC,
incluzând ziua curentă, cu cele 7 zile UTC anterioare; nu compară cu Etsy și nu pretinde
atribuire de trafic.

## Verificare locală

Din rădăcina repository-ului site-ului:

```sh
php tests/agent/run.php
python3 tests/agent/http_smoke.py
python3 -m venv /tmp/ggs-agent-contract-venv
/tmp/ggs-agent-contract-venv/bin/pip install -r tests/agent/requirements.txt
/tmp/ggs-agent-contract-venv/bin/python tests/agent/validate_contract.py
```

PHP 8.0+ și PDO SQLite sunt suficiente pentru testele sintetice; serverul va cere
PDO MySQL. Validatorul Python este doar dependență de dezvoltare. Nu se schimbă
Composer și nu se citește `config.php`. Testele nu folosesc date sau credențiale
reale și nu contactează servicii comerciale. Testul HTTP deschide temporar numai
un port loopback și distruge fișierele private la final.
