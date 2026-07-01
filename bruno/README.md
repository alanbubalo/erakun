# eRakun — Bruno API kolekcija

API klijent koji služi kao **prototip sučelja** eRakun posrednika. eRakun nema
grafičko sučelje — sustav je REST API — pa ovu Bruno kolekciju koristimo kao
sučelje za demonstraciju i izvor screenshotova za korisničke upute.

[Bruno](https://www.usebruno.com/) je open-source, offline API klijent. Kolekcija
je pohranjena kao plain-text `.bru` datoteke u repou (`bruno/eRakun/`), pa je
verzionirana i čitljiva u diffu.

## Otvaranje

1. Instaliraj Bruno (`brew install --cask bruno` ili s usebruno.com).
2. **Open Collection** → odaberi mapu `bruno/eRakun`.
3. Gore desno odaberi okolinu **Local** (`base_url = http://localhost:8000/api`).

## Pokretanje poslužitelja

```bash
php artisan serve            # http://localhost:8000
```

Prvi put pripremi bazu i testni PKI:

```bash
php artisan migrate              # ili migrate:fresh za čistu bazu
php artisan pki:generate --parties   # izda potpisne certifikate svim subjektima
```

> `pki:generate --parties` je najlakši način da dobavljač dobije aktivan
> potpisni certifikat (alternativa je upload `.p12` kroz *Upload Certificate*).
> Bez certifikata fiskalizacija i potpisani UBL ne rade.

## Preporučeni redoslijed (za screenshotove)

| # | Mapa / zahtjev | Što pokazuje |
|---|----------------|--------------|
| 1 | **01 Parties** → Create Party (Supplier, Buyer) | registracija obveznika |
| 2 | **02 Certificates** → List / Upload Certificate | potpisni certifikati subjekta |
| 3 | **03 Invoices** → Create Invoice | kreiranje računa, izračun PDV-a |
| 4 | **03 Invoices** → Get Invoice / Get Invoice XML | detalji + UBL 2.1 / HR-CIUS |
| 5 | **04 Fiscalization & Delivery** → Fiscalize / Deliver | CIS prijava + AS4 dostava |
| 6 | **05 Inbound & Network** → Receive Inbound UBL / MPS | zaprimanje + otkrivanje sudionika |

Subjekti se u rutama adresiraju **po OIB-u** (`/parties/{oib}`), a računi po
internom `id` (`/invoices/{id}`). Kolekcija varijable `supplier_oib`, `buyer_oib`
i `invoice_id` drži u okolini *Local*; `invoice_id` se automatski sprema nakon
*Create Invoice*.

### Bitni preduvjeti

- **KPD kod** stavke mora biti iz CPA liste (npr. `622020`, `960212`). Zahtjev
  provjerava samo duljinu, ali UBL Schematron (HR-BR-25) odbija nepostojeći kod
  pri prijelazu u `queued`.
- **Potpisani UBL** nastaje tek prijelazom `draft -> queued`. Tek tada
  *Get Invoice XML* vraća potpis, a *Deliver* i *Receive Inbound UBL* rade
  (inbound odbija nepotpisani UBL s `422`).
- **Redoslijed za potpunu dostavu:** Create Invoice → Update Status (`queued`)
  → Deliver / Fiscalize. Uspješan *Deliver* pomiče status izlaznog računa na
  `delivered` (kroz `queued -> sent -> delivered`); *Fiscalize* ne dira status
  (rezultat je na `fiscal_messages`).

## Companion servisi (za fiskalizaciju i dostavu)

Fiskalizacija i AS4 dostava šalju zahtjeve vanjskim servisima konfiguriranim u
`.env`:

- `FISCALIZATION_SERVICE_URL` (zadano `http://localhost:8001`) — mock CIS-a
- `AS4_DEFAULT_PEER_URL` (zadano `http://localhost:8002`) — drugi posrednik

Za demonstraciju razmjene između dvije instance pogledaj `.env.peer` i
`tests/Integration/TwoInstanceRoundTripTest.php`. Bez tih servisa *Fiscalize* /
*Deliver* vraćaju grešku jer odredište ne odgovara — koraci 1–4 i MPS rade
samostalno.
