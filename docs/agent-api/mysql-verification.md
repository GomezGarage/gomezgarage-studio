# Probă MySQL izolată — etapa 1

Actualizare: matricea locală include și MariaDB 10.6.28 și PHP 8.1.
[Proba pe aceste versiuni și Apache](hosting-verification.md) este raportul curent.

Executată local la 21 octombrie 2026. **PASS: 35 verificări**, plus validarea celor
8 răspunsuri sintetice cu contractul OpenAPI 3.1. Această probă închide lipsa testului
pe un motor MySQL real din raportul inițial; nu confirmă versiunea hostingului.

Mediu: MySQL **8.4.13**, build Homebrew macOS arm64, PHP **8.5.9** / PDO MySQL.
Binarele și bibliotecile au fost extrase din bottles verificate de Homebrew într-un
director temporar; nu s-a instalat un serviciu persistent și nu s-a folosit
`brew services`. Bottle mysql@8.4 SHA-256:
`7d9b1d256ec98421f6c8bdc9e26e7e36b6d455ede7ea0142c46a8b15dcae413b`.

Runnerul creează un datadir nou, privat, pornește mysqld cu `--no-defaults`,
`--skip-networking`, `--mysqlx=OFF`, fără binlog/general log/slow log și folosește numai
un socket Unix în directorul temporar. Înainte de DDL verifică datadir, lipsa TCP și
absența bazei fixture. Nu acceptă DSN/host pentru un server existent. Root fără parolă
este exclusiv bootstrapul acestei instanțe efemere, inaccesibilă prin rețea; citirile
API folosesc un user nou cu parolă aleatoare și granturi SELECT pe coloane enumerate.
Instanța este oprită, iar datele/credențialele/logurile temporare sunt șterse la final.

DDL-ul products/orders este extras din schema.sql local, fără seeduri, setări sau
utilizatorul admin al magazinului. Fixture-urile sunt sintetice. Sunt probate:

- conectarea prin codul production `Repository::connect`, metadata TIMESTAMP(0),
  timezone UTC și sesiune read-only;
- refuzul UPDATE/DDL, SELECT *, customer_email și admin_users;
- timeoutul configurat și întreruperea efectivă a unui SELECT limitat;
- DECIMAL ca string, JSON country proiectat în SQL, status COD/paid fără încasare inventată;
- egalități de timestamp și paginare cu prepared statements native;
- scriere sintetică în timezone +03:00, citire UTC cu instanța corectă;
- citiri fără schimbarea vreunui câmp comercial, apoi actualizare controlată de fixture
  și citire incrementală;
- respingerea DATETIME și a unei scheme vechi fără payment_method, erori redactate,
  revocare înaintea DB și audit fără credentiale.

EXPLAIN pentru filtrul/ordinea `(updated_at,id)` pe schema actuală: **type=ALL, key=null**.
Cu 5 produse sintetice acest lucru nu măsoară performanța la volum. Indecșii și
încărcarea trebuie evaluate pe date sintetice reprezentative pentru hosting; migrarea
ulterioară va fi separată, versionată și testată. Nu am schimbat schema comercială.

## Reproducere

Necesită un MySQL 8.4 deja disponibil local; rulează ca utilizator obișnuit:

```sh
python3 tests/agent/mysql_smoke.py --mysql-basedir /absolute/path/to/mysql \
  --responses /tmp/ggs-mysql-responses.json
/tmp/ggs-agent-contract-venv/bin/python tests/agent/validate_contract.py \
  --mysql-responses /tmp/ggs-mysql-responses.json
```

Pentru bottles macOS extrase fără instalare, `--library-path` primește directoarele
bibliotecilor separate prin `:`. Nu modifica instalarea MySQL globală pentru acest test.
Exportul responses conține numai date sintetice și cursoare de test, niciun token sau
parolă; păstrează-l temporar, nu în artefactele de deploy.

Matricea locală a fost ulterior extinsă, conform raportului curent. Rămân de verificat pe hosting: TLS/FastCGI, schema și timezone-ul efectiv, indexarea/performance la volum. Proba locală
nu a contactat producția sau servicii externe.
