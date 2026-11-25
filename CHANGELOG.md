# Changelog

## [1.6.1] - 2026-11-26

### Přidáno
- Anglický název a popis webu (Bombora → B2B Agent → „Název webu – EN“) pro EN verzi – titulky stránek a e-maily. WPML „Blog Title“ na tomto webu k překladu nenabízí. Registrace do WPML z 1.6.0 odstraněna.

## [1.6.0] - 2026-11-26

### Přidáno
- **Více firem v jednom účtu.** Můj účet → Adresy → „Firmy a fakturační údaje“: hlavní firma (fakturační adresa účtu) + další firmy. Zákazník přidá firmu v pop-upu (IČO → ARES, DIČ → VIES), firma čeká na schválení, administrátorovi přijde e-mail. Žádost lze zrušit; schválenou firmu mění / odebírá jen administrátor.
- **Schvalování firem:** Uživatelé → „Firmy ke schválení“, počet v bublině menu a upozornění; v profilu zákazníka tabulka firem (úprava údajů, stav, smazání, přidání firmy administrátorem). Zákazníkovi odejde e-mail při schválení / zamítnutí (v jeho jazyce).
- **Pokladna:** „Fakturovat na firmu“ (jen schválené firmy, předvybraná naposledy použitá). Vybraná firma vyplní fakturační údaje objednávky (firma, IČO, DIČ, sídlo) → proforma / faktura v Eurofaktuře na tuto firmu. Profil účtu (hlavní firma) se objednávkou nepřepíše. U objednávky meta `_zg_b2b_company_id` / `_zg_b2b_company_label`.
- Firmy a doručovací adresy jsou nezávislé.
- Název a popis webu se registrují do WPML (doména „WP“) pro překlad do EN.

## [1.5.6] - 2026-11-25

### Opraveno
- „Použít jako výchozí doručovací adresu“ na konci formuláře i se šablonou webu, která řadí pole přes CSS `order`.

## [1.5.5] - 2026-11-25

### Opraveno
- Pořadí polí ve formuláři adresy: název pobočky nahoře, „Použít jako výchozí“ až na konci (WooCommerce řadí pole podle priority).

## [1.5.4] - 2026-11-25

### Změněno
- Přidání a úprava doručovací adresy v **pop-up okně** přímo na stránce Adresy (bez přechodu na jinou stránku). Zavření křížkem, tlačítkem Zrušit, klávesou Esc nebo kliknutím mimo okno; na mobilu se okno vysune zespodu. Při chybě validace se okno po odeslání znovu otevře s vyplněnými údaji a chybovou zprávou. Bez JavaScriptu zůstává formulář na samostatné stránce.

## [1.5.3] - 2026-11-25

### Změněno
- Nastavení přesunuto z menu WooCommerce pod **Bombora → B2B Agent** (bez Bombora Manageru zůstává pod WooCommerce). Adresa stránky `admin.php?page=zg-b2b` beze změny.

## [1.5.2] - 2026-11-25

### Opraveno
- Tlačítka adres přebíjela pravidla šablony pro formuláře v Můj účet (tyrkysové pozadí) – vyšší specificita.
- Styly a skripty adres se načtou i při přeloženém endpointu (např. `/muj-ucet/adresa/`).
- Adresy ve dvou sloupcích (na mobilu v jednom).

## [1.5.1] - 2026-11-25

### Změněno
- Doručovací adresy jsou přímo v Můj účet → **Adresy** (fakturační adresa + adresář poboček na jedné stránce); samostatná záložka „Doručovací adresy“ zrušena, staré odkazy přesměrují. „Doručovací adresa → Upravit“ upraví výchozí adresu adresáře.
- Tlačítka adres v designu webu: obrysová „Upravit“ / „Nastavit jako výchozí“, tmavé „Přidat doručovací adresu“ / „Uložit“, červené obrysové „Smazat“; akční formuláře bez rámečku karty.

## [1.5.0] - 2026-11-25

### Přidáno
- **Doručovací adresy (pobočky)**: Můj účet → Doručovací adresy (více adres s názvem, výchozí adresa), výběr adresy v pokladně pod fakturačními údaji, automatické „Doručit na jinou adresu“, uložení nové adresy z pokladny.
- Adresa se eviduje po polích (`shipping_*` – firma, jméno, ulice, PSČ, město, země, telefon) pro WMS a dopravce; název pobočky je samostatná meta objednávky `_bombora_shipping_address_label` a zobrazuje se zvlášť (admin, e-maily, Můj účet).
- Výchozí adresa adresáře se synchronizuje s doručovací adresou v profilu WooCommerce.
- Překlady EN (en_US, en_GB).
- Release přes GitHub Actions + Bombora Manager.

### Změněno
- Plugin přejmenován na **Bombora B2B Agent**, složka `zg-b2b` → `bombora-b2b-agent` (převod přes Bombora Manager). Text domain `zg-b2b`, nastavení a meta klíče beze změny.
