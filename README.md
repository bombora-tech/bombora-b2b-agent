# Bombora B2B Agent – instalace a kontrola

## Instalace
1. Instalace a aktualizace přes **Bombora → Moduly** (Bombora Manager ≥ 1.3.1, repo `bombora-tech/bombora-b2b-agent`, složka `bombora-b2b-agent`).
   - Přechod ze starého `zg-b2b`: Bombora → Moduly → **Převést a aktualizovat** (stará složka se tiše deaktivuje a smaže bez uninstall – nastavení, B2B účty i objednávky zůstanou). Oba najednou aktivní být nesmí.
   - Vydání nové verze: zvýšit `Version` + `ZG_B2B_VERSION`, doplnit `CHANGELOG.md`, push do `main` → GitHub Actions vytvoří release s `bombora-b2b-agent.zip`.
2. **Bombora → B2B Agent**:
   - zkontrolujte předvybrané kategorie (pivo, lihoviny…) → **Uložit nastavení** (štítek se přiřadí při uložení),
   - vyberte stránku registrace a VOP,
   - ověřte meta klíče IČO/DIČ (musí odpovídat polím v pokladně, výchozí `billing_ic` / `billing_dic`).
3. Vytvořte stránku „Registrace partnera“ a vložte do ní (Bricks → Shortcode) `[zg_b2b_registration]`.
4. Do šablony karty produktu v Bricks přidejte Shortcode elementy:
   - `[zg_cz_only_notice]` pod tlačítko košíku,
   - `[zg_b2b_price_gate]` místo ceny/košíku (zobrazí se jen nepřihlášeným),
   - do výpisu/Query loop volitelně `[zg_cz_only_badge]`.
5. Tlačítko kamkoli (hlavička, homepage): `[zg_b2b_register_button]` (`variant="primary|outline|light"`, `size="sm|lg"`, `text="…"`).

## Co plugin dělá
- **Štítek „Doručení pouze v ČR“** – vytvoří se automaticky, název lze změnit v nastavení. Uložením se přiřadí produktům v zaškrtnutých kategoriích (i nově přidaným); odškrtnutím kategorie se jejím produktům odebere (kromě produktů v jiné zaškrtnuté kategorii). Ručně jde přidat/odebrat u produktu.
- Zákazník je informován: box na kartě produktu, štítek ve výpisu, řádek „Doručení: pouze Česko“ v košíku a pokladně, upozornění v rekapitulaci pokladny (mění se podle zvolené země), chyba v košíku a **zablokování objednávky** při doručení mimo ČR. Zákazníkům s adresou v účtu mimo ČR se u těchto produktů skryje „Do košíku“.
- **Ceny** vidí jen schválení partneři a administrátoři. Ceny se skrývají i ve schema (Rank Math / WooCommerce) a ve Store API.
- **Registrace**: ARES (CZ) automaticky doplní firmu, sídlo a DIČ; VIES ověří DIČ firem z EU. Účet vzniká se stavem *Čeká na schválení*, vám přijde e-mail.
- **Schvalování**: Uživatelé → „Čeká na schválení B2B“ → akce **Schválit B2B**. Zákazníkovi odejde e-mail. Stav jde změnit i v profilu uživatele.
- Nativní registrace WooCommerce/WordPress je vypnutá; neschválený účet se nepřihlásí.
- Účty vytvořené před aktivací pluginu se berou jako schválené (lze vypnout v nastavení).

## Kontrolní seznam po instalaci
- [ ] Jako nepřihlášený (anonymní okno): karta produktu i výpis bez cen, žádné „0 Kč“ (hlavně u vlastních Bricks prvků s cenou bez/s DPH).
- [ ] Zdrojový kód karty produktu: v JSON-LD není `"price"`.
- [ ] Registrace s CZ IČO → doplnění z ARES → e-maily (admin + zákazník).
- [ ] Schválení v Uživatelé → e-mail → přihlášení → ceny viditelné, pokladna předvyplněná.
- [ ] Košík s lihovinou + změna země v pokladně na SK → upozornění + objednávku nelze odeslat.
- [ ] Cache (LiteSpeed/WP Rocket): přihlášení uživatelé se necachují.
- [ ] EN verze (WPML): texty přeložit v Překladu řetězců (doména `zg-b2b` a `admin_texts_zg_b2b_settings`), registrační stránku přeložit se stejným shortcodem.

## Barvy
Upravte proměnné v `assets/css/zg-b2b.css` (`--zg-b2b-primary` atd.) nebo je přepište v Bricks → Theme Styles → Custom CSS.

## Platba na fakturu (proforma z Eurofaktury) – v1.1
1. V Eurofaktuře založte **samostatného API uživatele** pro web (API nepovoluje souběžné požadavky jednoho uživatele – nesdílejte ho se synchronizací skladu).
2. Bombora → B2B Agent → sekce 3: API URL (ověřte v API konzoli Eurofaktury), uživatel, secret key, token. Bezpečněji v `wp-config.php`:
   `define('ZG_EF_USERNAME','…'); define('ZG_EF_SECRET','…'); define('ZG_EF_TOKEN','…'); define('ZG_EF_ENDPOINT','…');`
3. Tlačítkem **Zavolat API** (např. `SalesQuoteList`) ověřte přihlášení.
4. WooCommerce → Nastavení → Platby → **Platba na fakturu (B2B proforma)**: zapnout, splatnost, číslo účtu/IBAN, zdroj VS.
5. Testovací objednávka → zkontrolujte proformu v Eurofaktuře (částky, DIČ, položky) a log `zg-b2b-eurofaktura`.

Průběh:
- Objednávka → proforma v Eurofaktuře (`SalesQuoteCreate`, reference `VO-číslo objednávky`) → stav „Čeká na vyřízení“, e-mail s PDF proformy v příloze a platebními údaji.
- Proforma ke stažení na děkovací stránce a v Můj účet → Objednávky.
- Platbu spárujete a fakturu vystavíte v Eurofaktuře (z proformy). Plugin každou hodinu hledá fakturu odběratele (shoda podle čísla proformy / `VO-…`, jinak jediná faktura se stejnou částkou), uloží ji, v Můj účet nahradí proformu a objednávku přepne na „Zpracovává se“ (e-mail s fakturou v příloze).
- Panel **Eurofaktura** v detailu objednávky: vytvořit proformu znovu, hledat fakturu, obnovit PDF, zadat čísla ručně.
- Při chybě API: 5 opakování s odstupem, poté e-mail na adresu z nastavení.

Ověřit při prvním testu (API dokumentace Eurofaktury není celá veřejná):
- že `SalesQuote` je ve vaší Eurofaktuře doklad „nabídka / proforma“ a stav `Issued` dává číslo,
- názvy polí DIČ/IČO odběratele (nastavení „Pole odběratele“),
- že se reference `VO-…` přenáší z proformy na fakturu (jinak párování podle částky / ručně),
- variabilní symbol na PDF proformy vs. nastavení VS v platební metodě.

## Doručovací adresy (pobočky) – v1.5
- **Můj účet → Doručovací adresy** (`/muj-ucet/dorucovaci-adresy/`, EN `/delivery-addresses/`): více adres s vlastním názvem (např. pobočka restaurace), úprava, smazání, výchozí adresa. Při prvním otevření se doručovací adresa z registrace uloží jako výchozí.
- **Pokladna:** pod fakturačními údaji výběr *Stejná jako fakturační adresa* / uložené pobočky / *Jiná adresa* (volitelně uložit do adresáře). Výběr vyplní doručovací pole, zaškrtne „Doručit na jinou adresu“ a přepočítá dopravu (platí i pravidla CZ-only).
- Liší-li se výchozí doručovací adresa od fakturační, je „Doručit na jinou adresu“ zaškrtnuté automaticky.
- **Adresa se eviduje výhradně po polích** (firma, jméno, ulice, PSČ, město, země, telefon) – v objednávce standardní `shipping_*` pole pro WMS / dopravce / Eurofakturu. Název pobočky je samostatná meta `_bombora_shipping_address_label` (+ `_bombora_shipping_address_id`), zobrazuje se zvlášť (admin objednávky, e-maily, Můj účet), do adresních polí se nepřidává.
- Výchozí adresa adresáře = doručovací adresa v profilu WooCommerce (synchronizace oběma směry: Můj účet → Adresy ↔ adresář).
- Admin: seznam adres v profilu uživatele.
- Filtry: `zg_b2b_addr_max_addresses` (30), `zg_b2b_addr_endpoint_slug`, `zg_b2b_addr_checkout_enabled`, `zg_b2b_addr_field_definitions`.
- Vyžaduje klasickou (Bricks) pokladnu, ne Checkout block.

## Více firem v účtu – v1.6
- Můj účet → Adresy → **Firmy a fakturační údaje**: hlavní firma = fakturační údaje účtu; další firmy přidává zákazník (ARES/VIES) → stav *Čeká na schválení*.
- Schválení: Uživatelé → **Firmy ke schválení** → profil zákazníka → tabulka „Další firmy účtu“ (stav, úprava, smazání, přidání). Údaje firem mění jen administrátor.
- Pokladna: **Fakturovat na firmu** (jen schválené). Fakturační údaje objednávky = vybraná firma; profil účtu se nemění. Meta objednávky `_zg_b2b_company_id`, `_zg_b2b_company_label`.
- Data: user meta `zg_b2b_companies` (pole firem), `zg_b2b_companies_pending` (počet čekajících), `zg_b2b_last_company`.
