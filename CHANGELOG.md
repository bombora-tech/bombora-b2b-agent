# Changelog

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
