# Verificare locală — 21 octombrie 2026

## Draft-only product writes — 24 octombrie 2026

Extensia locală propune contractul 1.3.0. Nu este publicată, migrată sau activată.

| Verificare | Rezultat |
|---|---|
| PHP lint pentru entrypoints, repository și teste agent | PASS |
| `php tests/agent/run.php` | PASS, 116 verificări SQLite sintetice, inclusiv scriere de draft, replay idempotent, revision conflict și refuzul publicării/stocului |
| `python3 tests/agent/http_smoke.py` | PASS, loopback auth/method/body/scope și refuzul fallbackului writer la reader |
| OpenAPI 3.1 / JSON Schema 2020-12 | PASS, 76 răspunsuri sintetice și 5 exemple |
| MariaDB 10.6.28 / MySQL 8.4.13 cu granturi column-level | Codul probei a fost extins; **nu a fost rulat**, binarele izolate nu sunt disponibile în acest mediu |
| Apache + PHP HTTPS | **Nerulat** pentru extensia nouă; binarele mod_php izolate nu sunt disponibile în acest mediu |

Nu s-a conectat la o bază existentă și nu s-a rulat migrarea pe hosting. Înainte de deploy
trebuie rulate `mysql_smoke.py` pe ambele motoare/versiuni documentate și `apache_smoke.py`,
apoi verificate schema și permisiunile reale în staging separat.

## Extensia view_stats — status la 24 octombrie 2026

Verificările de mai jos sunt istorice pentru extensia `view_stats` din contractul 1.2.0;
extensia curentă 1.3.0 este raportată separat mai sus.

| Verificare | Rezultat |
|---|---|
| `php tests/product-views.php` | PASS, 14 probe sintetice pentru origin, metodă, body și agregare fără identificatori |
| `php tests/agent/run.php` | PASS, 93 verificări SQLite sintetice, inclusiv delta de 7 zile |
| `python3 tests/agent/http_smoke.py` | PASS, rutele API existente, autentificare și headers |
| OpenAPI 3.1 / JSON Schema 2020-12 | PASS, 59 răspunsuri sintetice și 4 exemple |
| PDO MariaDB 10.6.28 / MySQL 8.4.13 pentru noul JOIN | **Nereverificat în această sesiune**; instanțele izolate nu sunt disponibile |

Tabela și endpointul sunt pregătite, dar migrarea nu a fost rulată și nu s-a făcut deploy.
Pentru activare sunt necesare migrarea izolată și grant SELECT descrise în
[operations.md](operations.md), apoi probele runtime pe MariaDB 10.6.28 și PHP-ul hostingului.

API pregătit pentru review, dezactivat implicit. Aceste probe nu confirmă un deploy.

| Verificare | Rezultat |
|---|---|
| PHP 8.1.34 lint | PASS, 10 fișiere PHP |
| `tests/agent/run.php` | PASS, 88 verificări SQLite sintetice |
| HTTP entry points PHP | PASS, auth, scopes, revocare, throttle și audit |
| OpenAPI 3.1 / JSON Schema 2020-12 | PASS, 58 răspunsuri SQLite, 8 MariaDB, 4 exemple |
| PHP 8.1.34 + MariaDB 10.6.28 | PASS, 35 verificări într-o instanță fără TCP |
| PHP 8.5.9 + MySQL 8.4.13 | PASS, 35 verificări într-o instanță fără TCP |
| Apache 2.4.69 + mod_php 8.1.34 HTTPS | PASS, loopback cu certificat temporar verificat |

Detalii și reproducere: [MariaDB/PHP/Apache](hosting-verification.md),
[MySQL](mysql-verification.md), [configurare și rollback](operations.md).

Probele acoperă credentiale absente/invalide/expirate/revocate, scopes inclusiv drafturi,
lipsa fallbackului admin, GET-only, validarea filtrelor, cursor semnat și expirare,
egalități de timestamp, UTC, bani decimal/EUR, proiecție fără date personale și erori
redactate. Testul permisiunilor a detectat cache stat pe PHP 8.1; loaderul îl golește
explicit înaintea revalidării. Citirile păstrează snapshotul datelor comerciale identic.

Testele nu încarcă config.php/vendor/mailer/invoice și nu trimit mesaje sau plăți.
Instanțele DB sunt temporare, cu bootstrap de fixture separat de userul SELECT-only
al API-ului. Codul de producție este probat prin PDO și granturi efective, inclusiv
interzicerea scrierilor, a coloanelor nepermise și întreruperea unui SELECT la timeout.

Limitele rămase sunt explicite:

- Configurația live, schema, timezone-ul istoric, granturile, TLS/FastCGI/WAF și
  protecția fișierelor trebuie verificate în mediul de deploy.
- EXPLAIN pe schema locală arată scanare fără index adecvat; performanța la volum
  nu este validată. Nu s-a efectuat nicio migrare.
- Paginarea nu este snapshot tranzacțional și nu oferă tombstones sau toate
  tranzițiile intermediare. Consumatorul trebuie să folosească overlap și reconciliere.
- Limitarea este locală per host; traficul neautentificat necesită limitare la hosting.
- Statusul comenzii nu dovedește încasarea; payment_state rămâne unknown.
- Runtime-ul și transportul consumatorului sunt în afara acestei extensii de website.

Checkoutul comercial, facturarea, stocul și configurația reală nu sunt modificate.
Modificările preexistente au fost păstrate; composer.lock nu este inclus în acest patch.
