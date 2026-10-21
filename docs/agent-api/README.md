# API read-only al magazinului

Patch local pentru review, 21 octombrie 2026; contract **1.0.0**, OpenAPI **3.1.0**.
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
| `products.php` | `products.read` | Produse publicate implicit; `status=draft` sau `all` cere suplimentar `products.drafts.read` |
| `orders.php` | `orders.read` | Numai comenzile site-ului, toate statusurile implicit |

Numai `GET`; inclusiv `HEAD` și `OPTIONS` primesc 405 cu `Allow: GET`.
HTTPS obligatoriu; `Authorization: Bearer …` cu credential aleator dedicat. Fără
sesiune admin, CORS browser, SQL primit de la client sau fallback la feedul public.
API-ul nu include `config.php`, helpers, mailer, facturi, vendor sau plăți.
`GGS_AGENT_ENABLED=1` și un fișier privat valid sunt necesare pentru activare.
Un credential lipsă/invalid sintactic primește 401 inclusiv când API-ul este dezactivat;
un Bearer valid sintactic primește 503 când configurația este indisponibilă/dezactivată.

DTO-urile sunt proiecții validate server-side. Fiecare răspuns are
`schema_version`, `request_id` generat de server și `generated_at`. Succesul are
`data` și `next_cursor`; listele au și `sync_before`. Eroarea are `error.code` și
`error.message`. Erorile sursei nu expun SQL, excepții sau configurația.

## Semantica comercială

Prețurile și subtotal/shipping/total sunt **decimal strings în EUR**. Prețurile
istorice din JSON items sunt acceptate numai dacă sunt valori finite cu precizie
de cenți. Nu se face conversie valutară sau apel la Revolut. Sumele reprezintă
valoarea comenzilor, nu încasări. `payment_state` este întotdeauna `unknown`,
inclusiv pentru `paid`: statusul poate fi setat manual și nu există registru
independent al încasării. `fulfilment_status` reflectă numai statusurile native
`processing`, `shipped`, `delivered`; altfel este `unknown`. `status` nativ este
păstrat separat. `customer_language` este `null`, fără deducere RO/EN.

Date personale exportate în acest pilot: țara și trackingul, plus identificatorii
comenzii și numele produselor din items. Numele clientului, emailul, telefonul,
adresa completă, notele, firma/CUI și numerele/documentele facturilor sunt excluse.
Țara este proiectată în SQL din JSON; adresa completă nu este returnată către PHP.
Nu există `SELECT *` în implementarea API-ului. Numele produselor/textul catalogului
sunt date neîncrezătoare pentru clientul API, nu instrucțiuni sau autorizații.

Stocul expune `quantity` și `semantics=legacy_checkout_counter`. Nu afirmă existența
stocului fizic sau a producției la comandă. Acest patch nu schimbă modelul de stoc.
Câmpurile text legacy nullable rămân null; listele images/specs null devin liste
goale. JSON invalid, sume/timestamps invalide sau statusuri necunoscute produc
503 pentru întreaga pagină, fără eliminarea tăcută a unei comenzi din raport.

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
