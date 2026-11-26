# Changelog

## [1.20.1] - 2026-11-26

### Opraveno
- Nastavení: lišta „Uložit nastavení“ už není plovoucí (sticky) – způsobovala zaseknutí vykreslování stránky v Chrome.

## [1.20.0] - 2026-11-26

### Změněno
- **Nový vzhled nastavení** (Bombora → B2B Agent): záložky Přehled · Partneři a registrace · Doručení lihovin · Eurofaktura a doklady · S&V STOCK · Nástroje; sekce v kartách, barvy webu, plovoucí lišta „Uložit nastavení“ (ukládá všechny záložky najednou), poslední otevřená záložka se pamatuje. Přehled ukazuje stav modulů (platba na fakturu, doklady v Můj účet, S&V STOCK, čekající registrace) s odkazy.
- S&V STOCK: když už Eurofaktura k objednávce vystavila fakturu, box před rozdělením upozorní a po rozdělení zapíše do poznámky kontrolní seznam ručních kroků (opravný daňový doklad na lihoviny + příjemka na sklad).

## [1.19.0] - 2026-11-26

### Přidáno
- S&V STOCK: tlačítko **„Vrátit do původní objednávky“** u objednávky lihovin (s potvrzením). Položky (vč. příznaku odečtení skladu) a případně přesunutý poplatek za dopravu se vrátí do původní objednávky, ta se přepočítá a znovu synchronizuje s Eurofakturou – fakturuje Bombora group. Objednávka lihovin jde do koše (bez e-mailů, bez webhooku Eurofaktury, sklad beze změny). Poznámka upozorní na fakturu pro S&V k stornu, nahrané faktury S&V a objednaný Messenger.
- U objednávky jen označené jako S&V (nerozdělené) tlačítko **„Zrušit označení S&V“** – doklady z Eurofaktury se zákazníkovi opět zobrazí.

## [1.18.0] - 2026-11-26

### Přidáno
- **Modul „Lihoviny přes S&V STOCK“** (Bombora → B2B Agent → sekce 5, výchozí vypnuto; nouzově `ZG_B2B_SV_DISABLE` ve wp-config.php). Metabox „S&V STOCK – lihoviny“ v detailu objednávky:
  - **Rozdělit objednávku**: lihoviny z kategorií S&V se přesunou do nové propojené objednávky stejného zákazníka (bez e-mailů, bez druhého odečtení skladu, stav a datum platby převzaté). Původní objednávka se přepočítá a znovu synchronizuje s pluginem Eurofaktura.cz. Objednávka jen s lihovinami se pouze označí.
  - Objednávka lihovin se **neposílá pluginu Eurofaktura.cz** (blokovaný webhook). Zákazník u ní **nikdy nevidí doklady z Eurofaktury**.
  - **Vystavit v Eurofaktuře**: faktura Bombora → S&V přes API (odběratel S&V, volitelně pole skladu S&V, ceny 1:1 z objednávky, bez dopravy); PDF přijde e-mailem ke kontrole.
  - **Faktura S&V pro zákazníka**: nahrání PDF s číslem, volitelně e-mail zákazníkovi s přílohou; zobrazí se v Můj účet (sloupec Doklady i detail objednávky). Mazání nahraných faktur.
  - **Messenger**: „Objednat Messenger“ (API importParcel – vyzvednutí u S&V v Kladně, balíky podle hmotnosti, kontrola věku, kód VO-číslo), číslo zásilky a sledování, poznámka zákazníkovi s odkazem na sledování, „Štítky PDF“ (printLabels) a e-mail S&V k expedici se štítky (kopie vám). Testovací prostředí Messengeru je ve výchozím stavu zapnuté.

## [1.17.0] - 2026-11-26

### Změněno
- Můj účet → Objednávky (CZ+EN):
  - Sloupec „Celkem“ ukazuje jen cenu, bez textu „za N položek“.
  - Nový sloupec **„Doklady“** před „Akce objednávky“: jedno tlačítko ke stažení PDF pro každý doklad objednávky (faktura, opravný doklad, proforma), s číslem dokladu. Stornovaný doklad je šedý, přeškrtnutý, s označením „(storno)“. Doklady už se nepřidávají mezi akce objednávky.
  - Objednávky, u kterých se doklady z Eurofaktura.cz ještě nenačetly (např. starší než 45 dní), se po zobrazení seznamu načtou na pozadí a objeví se při příští návštěvě.

## [1.16.1] - 2026-11-26

### Opraveno
- Proforma se vytváří jen u objednávek s platbou na fakturu. Dřív šlo tlačítkem „Vytvořit proformu“ spustit vytváření i u jiné platby (např. převodem) a při chybě se opakovaně plánovaly další pokusy; ty se teď u jiných plateb ukončí bez akce.

## [1.16.0] - 2026-11-26

### Změněno
- **Doklady z Eurofaktura.cz jsou samostatný zapínatelný modul**: Bombora → B2B Agent → „4. Modul: Doklady z Eurofaktura.cz v Můj účet“ (výchozí stav **vypnuto**). Vypnutý modul nevolá Eurofakturu, nenačítá doklady, ruší své naplánované úlohy a zákazníci vidí doklady jako před verzí 1.15.0 (proforma / faktura z platby na fakturu). Uložené seznamy dokladů zůstávají pro opětovné zapnutí.
- Stav modulu v nastavení: zapnuto / vypnuto / plugin Eurofaktura.cz není aktivní nebo připojený / pozastaveno po chybách.

### Přidáno
- Nouzové vypnutí bez administrace: `define( 'ZG_B2B_EF_DOCS_DISABLE', true );` ve `wp-config.php`.
- Pojistka: po 5 chybách Eurofaktury za sebou (bez odpovědi / HTTP 5xx / výjimka) se volání na 30 min pozastaví, zapíše se do logu `zg-b2b-eurofaktura`.
- Výjimky z pluginu Eurofaktura.cz se zachytávají – chyba v něm neshodí Můj účet ani administraci objednávky.

## [1.15.0] - 2026-11-26

### Přidáno
- **Doklady z pluginu Eurofaktura.cz v Můj účet** (CZ+EN): faktury a opravné daňové doklady vystavené přes oficiální plugin Eurofaktura.cz (webhooky) se zobrazují v detailu objednávky v bloku „Doklady“ a jako akce v seznamu objednávek.
  - Seznam dokladů se načítá stejnou akcí jako metabox pluginu Eurofaktura (`order.refresh`) a ukládá se k objednávce (`_zg_ef_docs`): 2 min po změně stavu objednávky, každých 30 min (objednávky za 45 dní; s fakturou jednou za 6 h) a při otevření detailu objednávky (pokud je seznam starší než 10 min).
  - PDF se stahuje přes chráněný odkaz webu (přihlášený vlastník + klíč objednávky + ID dokladu, který k objednávce patří) a streamuje se z Eurofaktury (`document.pdf`) bez ukládání na disk. Veřejný odkaz Eurofaktury („Otevřít link“) zákazník nevidí.
  - Stornovaná faktura: štítek „stornováno“, přeškrtnutý název, nezobrazuje se v akcích seznamu objednávek. Výdejky a objednávky zákazníka se nezobrazují.
- Platba na fakturu: faktura k proformě se nejdřív hledá v dokladech pluginu Eurofaktura.cz, až pak přes API (`SalesInvoiceList`).

### Změněno
- Metabox objednávky přejmenován na **„B2B doklady (Můj účet)“** (odlišení od metaboxu „Eurofaktura.cz“): přehled dokladů, které vidí zákazník, čas načtení a tlačítko „Načíst doklady“. Část s proformou se zobrazuje jen u platby na fakturu.
- Proforma zůstává z B2B Agenta (plugin Eurofaktura.cz proformy nevytváří); po vystavení faktury ji v Můj účet nahradí faktura.

## [1.14.0] - 2026-11-26

### Změněno
- Můj účet – všechny úpravy v pop-upu:
  - Adresy → „Upravit“ u hlavní firmy otevře fakturační a kontaktní údaje (standardní formulář WooCommerce vč. zámků B2B) v pop-upu. Přímý odkaz `/…/adresa/billing/` i chyba při uložení zobrazí přehled Adres s otevřeným oknem.
  - Detaily účtu: přehled (jméno, zobrazované jméno, e-mail, heslo) s tlačítky „Upravit údaje“ a „Změnit heslo“ – formulář WooCommerce v pop-upu; při chybě se okno znovu otevře.

## [1.13.1] - 2026-11-26

### Opraveno
- Pokladna: po úpravě adresy a obnovení stránky se pod uloženou adresou zobrazovala pole adresy (animace WooCommerce „Doručit na jinou adresu“ je znovu rozbalila). Stav polí se nyní vynutí i po inicializaci pokladny.

### Změněno
- Pop-up úpravy adresy: tlačítko **„Uložit adresu“** (místo „Hotovo – použít adresu“) – zkontroluje povinná pole, adresu hned uloží a okno zavře; text v okně upraven.

## [1.13.0] - 2026-11-26

### Změněno
- Doručovací adresa: pole firmy se jmenuje **„Název podniku“** (nápověda: restaurace, kavárna, vinotéka, obchod nebo firma) a je **povinné** – v pokladně, v Můj účet → Adresy i v B2B Dashboardu.
- Název podniku = název uložené adresy: úprava v pop-upu v pokladně přejmenuje i uloženou adresu; nová adresa uložená z pokladny se pojmenuje podle názvu podniku (samostatné pole „Název pobočky“ v pokladně odstraněno).

## [1.12.1] - 2026-11-26

### Opraveno
- Pop-up úpravy adresy v pokladně se zobrazoval jen v pravém sloupci (šablona dávala poslednímu prvku formuláře `position: sticky`); nyní přes celou obrazovku.

## [1.12.0] - 2026-11-26

### Změněno
- Pokladna: úprava uložené doručovací adresy („Upravit adresu“) se otevírá v **pop-upu** (stejné okno jako v Můj účet → Adresy). Změny se ukládají automaticky, okno zavře „Hotovo – použít adresu“, křížek, kliknutí mimo okno nebo Esc. Na mobilu se okno vysouvá zespodu. Zadání nové adresy („Jiná adresa“) zůstává přímo v pokladně.

## [1.11.3] - 2026-11-26

### Změněno
- Pokladna – úprava doručovací adresy je zřetelný editační panel: tyrkysový rámeček, hlavička „Upravujete adresu: [pobočka]“ s informací, že se změny ukládají automaticky, křížek pro zavření a dole tlačítko „Hotovo – použít adresu“ se stavem ukládání. Nová adresa má hlavičku „Nová doručovací adresa“.

## [1.11.2] - 2026-11-26

### Změněno
- Pokladna: „Fakturační údaje firmy mění provozovatel webu – napište nám.“ (místo „velkoobchod“).
- Pole úpravy / zadání doručovací adresy se zobrazují hned pod výběrem doručovací adresy (dřív až pod kontaktní osobou v dalším bloku).

## [1.11.1] - 2026-11-26

### Změněno
- Pokladna: odstraněn nadpis „Fakturovat na firmu“ (výběr firmy je přímo pod „Fakturační údaje“).

## [1.11.0] - 2026-11-26

### Přidáno
- Můj účet → nová položka menu **„Kontaktní osoby“** (vedle Adres; CZ `/muj-ucet/kontaktni-osoby/`, EN `/en/my-account/contact-persons/`): přehled kontaktních osob, přidání a úprava v pop-upu, mazání. V pokladně odkaz „Spravovat kontaktní osoby“.

### Změněno
- Sekce kontaktních osob přesunuta ze stránky Adresy do vlastní položky menu.

### Opraveno
- Pokladna: zbytečná mezera a dvojitá čára mezi fakturačními údaji a doručovací adresou.

## [1.10.1] - 2026-11-26

### Opraveno
- Pokladna (mobil): počet v závorce („(1 firma)“, „(3 adresy)“) se nezalamuje na dva řádky.

## [1.10.0] - 2026-11-26

### Přidáno
- Pokladna – nový blok **„Kontaktní osoba“** (příjemce zásilky): výběr z uložených kontaktních osob (rozbalovací seznam jako u firem/adres) nebo **nová osoba** (jméno, příjmení, telefon) s volbou „Uložit do mých kontaktních osob“. Příjemce se zapíše do doručovacího jména a telefonu objednávky – i u „Stejná jako fakturační adresa“ (doručovací adresa se doplní z fakturační).
- Seznam kontaktních osob se při prvním použití naplní z kontaktu účtu a uložených doručovacích adres; v Můj účet → Adresy je přehled s mazáním.

### Změněno
- Fakturace: kontaktní údaje účtu (jméno, příjmení, telefon, e-mail) se v pokladně nezobrazují vůbec (zobrazí se jen, pokud chybí povinný údaj).
- Doručovací adresa: místo řádku s kontaktem tlačítko „Upravit adresu“; jméno a telefon v polích adresy řídí blok „Kontaktní osoba“.

### Opraveno
- Mobil: pokladna „ujížděla“ do strany – iOS zvětšoval stránku při kliknutí do pole s písmem 15 px; pole mají na mobilu 16 px, delší texty se zalamují a vodorovné přetečení je oříznuto.

## [1.9.0] - 2026-11-26

### Změněno
- Pokladna – výběr firmy i doručovací adresy ve stylu výběru dopravy: nahoře **zvolená položka** (zvýrazněná, tyrkysový pruh vlevo), pod ní rozbalovací řádek **„Fakturovat na jinou firmu (N firem)“ / „Doručit na jinou adresu (N adres)“** s ikonou a šipkou; po výběru se seznam sbalí. Nahrazuje odkaz „Vybrat jinou firmu“.

## [1.8.3] - 2026-11-26

### Změněno
- Pokladna: „Vybrat jinou firmu“ v rámečku s pozadím (jako kontaktní osoba).
- Změny kontaktních údajů (jméno, telefon, e-mail) a údajů uložené doručovací adresy se **ukládají hned** (AJAX, bez nutnosti kliknout „Hotovo“); stav „Ukládám… / ✓ Uloženo“ se zobrazí u výrazného tlačítka **Hotovo pod poli** (dřív nenápadně nahoře).

## [1.8.2] - 2026-11-26

### Změněno
- Pokladna: vidět je jen zvolená firma; ostatní firmy účtu se zobrazí až po kliknutí na „Vybrat jinou firmu“ a po výběru se seznam zase sbalí (prevence fakturace na špatnou firmu).

## [1.8.1] - 2026-11-26

### Opraveno
- B2B Dashboard: detail zákazníka skončil chybou (nenačtená třída tabulky).

## [1.8.0] - 2026-11-26

### Přidáno
- **B2B Dashboard** (Bombora → B2B Dashboard, oprávnění `manage_woocommerce`): přehled zákazníků (WP_List_Table) – stav účtu, hlavní a další firmy, doručovací adresy, počet objednávek / poslední objednávka / útrata, platba na fakturu, registrace; filtry (registrace / firmy ke schválení, schválení, zamítnutí), vyhledávání (jméno, e-mail, telefon, firma, IČO, DIČ), počet na stránku. Souhrnné dlaždice: registrace a firmy ke schválení, schválení partneři, proformy čekající na úhradu. Počet čekajících v bublině menu.
- **Detail zákazníka** na jedné stránce: stav účtu (rychlé schválení / zamítnutí), platba na fakturu, interní poznámka, kontaktní osoba, hlavní firma, další firmy (schválení, faktura, úpravy), doručovací adresy (úpravy, výchozí, smazání, přidání), posledních 15 objednávek s firmou a doručením. Odkaz „B2B detail“ v seznamu uživatelů.

### Změněno
- Pokladna: výběr firem i doručovacích adres je vždy vidět; pod ním řádek **Kontaktní osoba** (jméno · telefon · e-mail, u doručení jméno · telefon) s tlačítkem „Změnit údaje“, které rozbalí pole pro úpravu.

## [1.7.0] - 2026-11-26

### Přidáno
- Pokladna: fakturační údaje (firma + kontaktní osoba, telefon, e-mail) a doručovací adresa se zobrazují jako **sbalený souhrn** s tlačítkem „Změnit údaje“; po rozbalení výběr firmy / adresy a pole pro změnu jména, telefonu a e-mailu. Neúplné údaje nebo chyba při odeslání → automaticky rozbaleno.
- **Platba na fakturu schvalovaná pro každou firmu zvlášť** (profil zákazníka → Další firmy účtu → sloupec „Faktura“). U hlavní firmy platí dosavadní nastavení účtu. Dostupnost platební metody se řídí firmou vybranou v pokladně (filtr `zg_b2b_invoice_allowed`).

### Změněno
- Odebrání firmy: jen firmy bez objednávek (podle ID firmy / IČO / DIČ v objednávkách); v účtu musí zůstat alespoň jedna schválená firma. Odebrat lze i hlavní firmu – hlavní se pak stane další schválená firma.

## [1.6.3] - 2026-11-26

### Opraveno
- Pokladna: při skrytém IČO se skryje i tlačítko „Načíst údaje z ARESu“ (WPify).

## [1.6.2] - 2026-11-26

### Změněno
- Doručovací adresa: **Název pobočky** je povinný a slouží zároveň jako název firmy v doručovací adrese (`shipping_company`, i u dříve uložených adres); pole „Název firmy“ z formuláře odstraněno.
- Výběr země v pop-upech ve stylu webu (selectWoo), zaškrtávací pole „Použít jako výchozí“ / „Uložit adresu“ ve stylu webu.
- Zákazník může odebrat i schválenou další firmu (bez schválení administrátorem).
- Pokladna: údaje, které zákazník nemůže změnit (název firmy, IČO, DIČ a při více firmách i sídlo), se nezobrazují jako pole – jsou v kartě firmy nad formulářem (u jedné firmy jen souhrn bez volby).
- Seznam objednávek (Můj účet i administrace): sloupce **Firma** (fakturace) a **Doručení** (název pobočky / doručovací adresy).

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
