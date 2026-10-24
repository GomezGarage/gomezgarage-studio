# Compatibilitate hosting — actualizare 24 octombrie 2026

Extensia draft-only din contractul 1.3.0 și `view_stats` au trecut probe izolate locale
pe MariaDB 10.6.28, PHP 8.1.34 și Apache 2.4.69. Testele folosesc o instanță MariaDB
temporară fără TCP, TLS local și numai date sintetice. Ele nu verifică runtime-ul specific
cPanel/CloudLinux, granturile sau schema bazei live și nu reprezintă deploy.

Matrice testată: **Apache 2.4.69, PHP 8.1.34 și MariaDB 10.6.28**.
Acest raport descrie exclusiv probe locale, fără acces la producție.

| Probă locală, macOS arm64 | Rezultat |
|---|---|
| PHP 8.1.34, lint toate fișierele PHP din repository | PASS |
| PHP 8.1.34, suită sintetică SQLite | PASS, 122 verificări |
| Product views, fixture SQLite | PASS, 14 verificări |
| PHP 8.1.34 + MariaDB 10.6.28, instanță temporară fără TCP | PASS, 61 verificări, inclusiv granturi de coloană draft |
| OpenAPI 3.1 / JSON Schema 2020-12 | PASS, 78 răspunsuri SQLite + 15 MariaDB + 5 exemple + 2 cereri draft |
| PHP CLI HTTP, entry points reale | PASS |
| Apache 2.4.69 + mod_php 8.1.34, HTTPS pe loopback | PASS, certificat temporar verificat de client |

Binarele au fost extrase din bottles Homebrew, fără instalare/serviciu persistent.
SHA-256 bottles: MariaDB `0d5e51f96ba103c2a3d4a44d890481c073843477efed517fa7cfc3b2b1a95b75`,
PHP `d9a81735163dc0a89d8c702ef5b181f2b0054bd4b786fda4e48a5d5a77f5cec1`.

MariaDB a trecut verificările descrise în [proba MySQL](mysql-verification.md),
cu `tx_read_only`, `max_statement_time=3` și eroarea 1969 la întreruperea efectivă a
unei interogări de test. Sesiunea este UTC. Proba folosește o identitate sintetică cu
permisiuni minime doar pentru test; în hosting se folosește contul DB existent cu
drepturile lui actuale. Sesiunea read-only refuză DML în cod, iar conexiunea de draft
folosește același cont implicit. Testele confirmă că ruta
draft nu poate publica, modifica stocul/featured, șterge produse sau scrie comenzi.
Paginarea și proiecția JSON funcționează prin PDO cu prepared statements.
EXPLAIN rămâne `ALL`, fără index: datele reduse de test nu probează performanța la volum.

Proba Apache copiază numai codul agentului într-un document root temporar. Folosește
TLS real pe 127.0.0.1, certificat efemer și fișier privat cu credentiale aleatoare.
Verifică health, Bearer, lipsa fallbackului admin, scopes, GET-only, validare,
revocare, no-store, audit redactat și refuzul citirii DB fără configurare. Nu încarcă config.php și
nu pornește rute comerciale. Procesul și fișierele temporare sunt eliminate la final.

## Corecție găsită pe PHP 8.1

Testul existent pentru permisiunile configului a detectat un rezultat stat în cache
în interiorul aceluiași proces PHP 8.1. `Config::load` golește acum explicit cache-ul
stat/realpath înainte de verificarea căii și a permisiunilor. Testul a eșuat înaintea
corecției și trece după ea. Nu am schimbat checkoutul, stocul, facturarea sau schema.

## Reproducere

Cu binare locale deja disponibile; fără instalare pe hosting:

```sh
python3 tests/agent/mysql_smoke.py --engine mariadb \
  --mysql-basedir /absolute/path/to/mariadb-10.6.28 \
  --php /absolute/path/to/php81 --responses /tmp/ggs-mariadb-responses.json
python3 tests/agent/http_smoke.py --php /absolute/path/to/php81
/tmp/ggs-agent-contract-venv/bin/python tests/agent/validate_contract.py \
  --php /absolute/path/to/php81 --mysql-responses /tmp/ggs-mariadb-responses.json
python3 tests/agent/apache_smoke.py --httpd-basedir /absolute/path/to/httpd \
  --php-module /absolute/path/to/libphp.so
```

Runnerul DB acceptă numai un basedir de binare, nu un server existent. Pentru bottles
macOS neinstalate folosește `--library-path` cu directoarele `lib` separate prin `:`;
validatorul și HTTP runner acceptă același argument. Apache
runnerul necesită structura `bin/httpd`, `lib/httpd/modules` și OpenSSL CLI.

## Limite înainte de activare

Buildul local nu reproduce patchurile CloudLinux/LVE, extensiile/limitele ea-php81,
handlerul PHP și virtual hostul din cPanel. Versiunea exactă a mediului de deploy trebuie verificată separat. Apache local folosește **mod_php**, nu certifică transmiterea
Authorization/HTTPS prin FastCGI/FPM în producție. Probează compatibilitatea codului
cu versiunile de bază, nu identitatea checkoutului local cu site-ul public.

Rămân de verificat separat: schema live, timezone-ul istoric, granturile cPanel pe
coloane, socketul DB, config privat, rate-limit storage, TLS/WAF, loguri, indexarea
și volumul. Instrucțiunile de deploy/rollback rămân în [operations.md](operations.md).
API-ul este dezactivat implicit; aceste teste nu autorizează și nu efectuează deploy.
