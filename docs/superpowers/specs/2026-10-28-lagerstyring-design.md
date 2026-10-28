# Lagerstyring af containere – designspec

Dato: 2026-10-28
Status: Godkendt mundtligt i brainstorm, afventer gennemlæsning af denne tekst.

## 1. Formål

Et webbaseret system, der erstatter en magnettavle til styring af skibscontainere på to lagre.
Det betjenes fra en tablet i gaffeltrucken og fra en pc på kontoret, og begge ser samme virkelighed.
Systemet skal:

- vise hvor hver container står, og hvem der har den,
- forhindre at en container placeres eller tages et sted, hvor det fysisk ikke kan lade sig gøre,
- lade kontoret slå op, hvad det koster i flytninger og tid at komme ind til en bestemt container,
- logge al aktivitet med bruger, og kunne fortryde de seneste handlinger.

## 2. Virkeligheden, der modelleres

- Alle containere er ens (samme størrelse). Hver har et fysisk nummer. Systemet knytter desuden et navn og en kunde til den.
- Containerne er virksomhedens egne og lejes ud. En container er derfor enten **på lager** eller **udleveret til kunde** og beholder sit nummer og sin historik, når den kommer hjem igen.
- Containere står i **rækker**, stablet oven på hinanden, med under 10 cm mellem rækkerne. En række kan kun tilgås fra den ene ende. Skal man have fat i den inderste container i bunden af en række med 10 i dybden og 3 i højden, skal 29 containere flyttes først.
- Lager 1 (indendørs): 3 rækker, hver 10 dyb og 3 høj (90 pladser), plus en buffer med 4 stakke, hver 3 høj (12 pladser).
- Lager 2 (udendørs): formentlig 3 rækker, 6 dyb og 3 høj. Tallene er usikre, og layoutet skal kunne rettes af kontoret uden udvikling.

## 3. Begreber og datamodel

Alle pladser beskrives med én byggesten: en række med dybde og højde, der tilgås fra enden. Bufferen er bare rækker med dybde 1.

| Begreb | Felter | Noter |
|---|---|---|
| Lager (`warehouses`) | navn, sortering | "Lager 1", "Lager 2" |
| Område (`zones`) | lager, navn, sortering | Grupperer rækker på skærmen: "Rækker", "Buffer" |
| Række (`storage_rows`) | område, navn, dybde, højde, sortering | Pladser beregnes som dybde × højde. Ingen pladstabel. `rows` er reserveret i MySQL. |
| Kunde (`customers`) | kundenummer (unikt), navn | |
| Container (`containers`) | nummer (unikt), navn, kunde (valgfri), status, række, dybde, højde | Status: `stored` / `delivered` / `pending` / `transit`. Plads-felterne er null ved `delivered` og `pending`. Ved `transit` holder de den reserverede bufferplads på det andet lager. Unik indeks på (række, dybde, højde). |
| Bruger (`operators`) | navn, aktiv | Tablet-brugere uden kodeord. Hedder `operators` for ikke at kollidere med Laravels `users`. |
| Admin (`users`) | navn, e-mail, adgangskode | Laravels standardbrugere. Kun til opsætning. |
| Logpost (`activity_log`) | bruger, handling, container, fra-plads, til-plads, fra-kunde, til-kunde, fra-navn, til-navn, fortrudt-af, tidspunkt | Handling: `created`, `placed`, `moved`, `delivered`, `returned`, `edited`, `transferred`, `arrived`, `undone`. Fortrudte poster slettes ikke; de får `undone_by` sat til den post, der rullede tilbage. |
| Indstillinger (`settings`) | nøgle, værdi | `minutes_per_move` (standard 4) |

Pladskoordinater: dybde 1 er forrest (ved indgangen), højde 1 er nederst.

Pladsnavne (tilføjet efter første test): række, plads og højde med mellemrum, så man kan tælle sig frem. "Lager 1 · A 3 2" er række A, plads 3 fra indgangen, højde 2 nedefra. Rækker med kun én plads (bufferstakke) har ikke pladstallet med: "Lager 1 · Buffer 2 1" er stak 2, højde 1. Tavlen viser pladstal under kolonnerne og højdetal i venstre side.

## 4. Regler

Reglerne ligger i rene PHP-klasser uden kendskab til Livewire, så de kan testes direkte.

### 4.1 Kan tages (`PlacementRules::canTake`)
En container kan tages, når
- alle pladser i rækken med lavere dybde er tomme, og
- pladsen lige over den er tom (eller den står øverst).

### 4.2 Kan sættes (`PlacementRules::canPlace`)
En plads er lovlig, når
- alle pladser i rækken med lavere dybde er tomme,
- pladsen selv er tom, og
- den er i højde 1 eller har en container lige under sig.

### 4.3 Advarsel ved hul (`PlacementRules::warnings`)
Sættes en container i en kolonne, mens en kolonne med højere dybde i samme række ikke er fuld, gives en advarsel: "Du spærrer N tomme pladser bag ved." Flytningen tillades efter bekræftelse.

### 4.4 Flytning (`MoveService`)
Én transaktion, der
1. låser containeren og de to berørte rækker,
2. tjekker at containeren stadig står præcis der, hvor skærmen troede (ellers fejlen "Tavlen er ændret, prøv igen"),
3. tjekker `canTake` på kilden og `canPlace` på målet,
4. opdaterer containeren og skriver én logpost.

Samme klasse udfører udlevering (`canTake`, status → `delivered`, plads nulstilles), hjemkomst (`canPlace`, status → `stored`, kunde kan skiftes) og første placering af en ny container.

### 4.4a Flytning mellem lagre via transit (`TransferPlanner`, `MoveService::transfer` og `arrive`)
Tilføjet efter første test, for at undgå fejl når en container skal over på det andet lager.
1. I panelet for en container, der kan tages, står en knap pr. andet lager: "Flyt til Lager 2". Under knappen står den bufferplads, den får.
2. Systemet vælger bufferpladsen: den lovlige plads i lagerets bufferområder, der spærrer færrest tomme pladser inde og står lavest, ved lighed den første i opsætningens rækkefølge.
3. Efter bekræftelse får containeren status `transit`. Den gamle plads bliver fri, og bufferpladsen er reserveret. Logposten hedder `transferred`.
4. Når containeren er sat ned, trykker chaufføren "Sat i buffer" i panelet eller i listen "I transit". Efter bekræftelse er status `stored` på samme plads, og logposten hedder `arrived`.

Regler: knappen virker kun, når containeren kan tages. Er bufferen fuld, eller har lageret ingen buffer, kan knappen ikke bruges, og grunden står under den. En container i transit kan ikke tages, trækkes, vælges eller udleveres, og intet kan sættes oven på den. Den tæller som optaget plads overalt, også ved ændring af layout og sletning. Den gamle måde, hvor man vælger containeren, skifter lager og trykker på en grøn plads, findes stadig til flytning direkte ind i en række.

### 4.5 Fortryd (`UndoService`)
- Fortrydbare handlinger: `placed`, `moved`, `delivered`, `returned`, `edited`, `transferred`, `arrived`.
- Fortryd af `transferred` sætter containeren tilbage på den gamle plads, hvis reglerne tillader det, og frigiver reservationen. Fortryd af `arrived` sætter den tilbage i transit, hvis intet er sat oven på den.
- De 15 nyeste ikke-fortrudte poster er "fortrydbare", men kun den nyeste af dem kan fortrydes ad gangen (stak). Når den er fortrudt, bliver den næste nyeste tilgængelig.
- Fortryd af `placed`, `moved`, `delivered` og `returned` udføres som en almindelig flytning via `MoveService` med alle regler slået til. Kan det ikke lade sig gøre (kildepladsen er optaget eller spærret), afvises det med forklaring, og intet ændres. Fortryd af `edited` gendanner navn og kunde fra logposten.
- En fortrydelse skriver en ny logpost af typen `undone` og sætter `undone_by` på originalen.

### 4.6 Ændring af layout
Dybde eller højde på en række kan kun sættes ned, hvis alle pladser, der forsvinder, er tomme. Rækker og områder kan kun slettes, når de er tomme.

## 5. "Kom ind til"-beregning (`AccessCalculator`)

For en container på lager:
- **Antal flytninger** = antal containere i rækken med lavere dybde + antal containere over den i dens egen kolonne.
- **Estimeret tid** = antal × `minutes_per_move`.
- **Liste** over de containere, der skal flyttes, med nummer, navn og kunde, i den rækkefølge de kan tages.
- **Parkeringskapacitet**: antal ledige, lovlige pladser lige nu i bufferen og i resten af lageret, så kontoret kan se, om der er plads til at parkere dem.

Beregningen ændrer intet; den er et opslag.

## 6. Skærme

Samme sider til tablet og pc. Tabletten får store trykflader (minimum 48 px), pc'en viser mere ad gangen. Al tekst er dansk, ingen sprogvælger.

### 6.1 Vælg bruger
Startskærm med én stor knap pr. aktiv bruger. Valget gemmes på enheden (session + local storage). Navnet står i topbjælken med en "skift"-knap.

### 6.2 Tavlen
- Faner pr. lager. Hvert område tegnes som sine rækker under hinanden, hver række i isometrisk visning (afsnit 7).
- Hver container er en brik med nummer og kundenavn, farvet efter kunde (fast farve pr. kunde udledt af kundenummeret).
- Ved siden af tavlen: feltet **"Udleveret til kunde"** med liste over udleverede containere. Det er både drop-mål (udlevering) og kilde (hjemkomst).
- **Træk og slip:** Hold fingeren på en brik ca. 250 ms, så den løfter sig, og træk. Kort tryk åbner container-panelet, almindeligt stryg ruller siden. Lovlige mål lyser grønt; alt andet er inaktivt. Brikker, der ikke kan tages, viser en lås og kan ikke løftes.
- **Bekræftelsesboks** ved slip på et lovligt mål: nummer, navn, kunde, fra, til, eventuelle advarsler, knapperne "Bekræft" og "Annullér". Ved hjemkomst kan kunden vælges blandt eksisterende kunder.
- **Søg:** Felt øverst. Søger i containernummer, containernavn, kundenavn og kundenummer. Træffere lyser op, alt andet dæmpes, og en resultatliste viser placering (også på det andet lager eller "udleveret").
- **Opdatering:** Tavlen henter nyt hvert 3. sekund (Livewire polling), pauser mens der trækkes. Mister enheden forbindelsen, vises en rød bjælke, og bekræft-knappen låses, indtil den er tilbage.

### 6.3 Container-panel
Åbnes ved tryk på en brik eller et søgeresultat: nummer, navn, kunde, plads, "kom ind til"-resultatet (afsnit 5), seneste handlinger på containeren, og redigering af navn og kunde (logges som `edited`).

### 6.4 Log
Egen side: alle handlinger, nyeste øverst, med bruger, tidspunkt, handling, container, fra og til. Filtrering på bruger og container. Fortryd-knap på den post, der aktuelt kan fortrydes; de øvrige fortrydbare poster viser "fortryd først handlingen over".

### 6.5 Opsætning (kræver admin-login)
Lagre, områder og rækker (med dybde/højde); kunder; tablet-brugere; oprettelse af containere (nummer, navn, kunde, placeres via tavlen bagefter eller oprettes som udleveret); indstillingen minutter pr. flytning; download af seneste database-backup.

Oprettelse kan ske som nummerserie: "Nummer" og "Til nummer", f.eks. 4101 til 4120 eller MSKU001 til MSKU020 (samme forbogstaver, foranstillede nuller bevares, højst 200 ad gangen). Numre, der findes, springes over og nævnes.

### 6.6 Ny container fra tavlen
Knappen "Ny container" over listen "Venter på plads". Nummer (påkrævet), navn og kunde. Containeren oprettes som "venter på plads", logges under den valgte medarbejder og er valgt bagefter, så de grønne pladser lyser. Containernumre gemmes med store bogstaver og enkelte mellemrum, og dubletter afvises uanset store og små bogstaver.

### 6.7 Statistik
Egen side i menuen for alle medarbejdere. Ændrer intet. Viser
- nøgletal for de seneste 7, 30 eller 90 dage: flytninger (placeret, flyttet, udleveret, hjemkommet, sendt til andet lager), ind (placeret eller hjemkommet), ud (udleveret) og andel optagne pladser,
- kapacitet pr. lager: optaget, reserveret til containere på vej, ledige, opdelt på rækker og buffer,
- ind og ud pr. uge de seneste 12 uger og gennemsnitlige flytninger pr. ugedag over de seneste 8 hele uger,
- flytninger pr. medarbejder i perioden,
- forecast: forventet ind og ud de næste 4 uger og hvornår hvert lager er fuldt, ud fra nettotilgangen pr. lager de seneste op til 8 hele uger. Kræver mindst 2 ugers historik.

Fortrudte handlinger og fortrydelser tæller ikke med. Under hver graf kan tallene vises som tabel.

### 6.8 Microbizz-integration
Kundens masterdata ligger i Microbizz. Systemet henter kunder og containere derfra og skriver aldrig tilbage. Systemet ejer kun, hvor containerne står.

- **Adgang:** Kontraktnummer, API-nøgle, brugernavn og kodeord ligger i `.env` (`MICROBIZZ_*`). Er de tomme, er integrationen slået fra, og alt virker som før.
- **Kunder:** `GetCustomersByLastChange`. Kobles på Microbizz-id, ellers på kundenummer. Navn og nummer opdateres. En deaktiveret eller slettet kunde markeres inaktiv, men slettes ikke.
- **Containere:** Udstyr fra `GetToolsByLastChange`. Under Opsætning vælges, hvilke stykker udstyr der er containere: et felt (gruppe, klasse eller gruppe-id) og en værdi. Man vælger også, hvilket felt der er containernummeret (nummer, kode, stregkode, serienummer eller navn), og hvilket felt der er navnet. Kunden er udstyrets tilknyttede kunde (`attachedcustomerid`). En kunde, der ikke er hentet endnu, hentes med `GetCustomerByID`.
- **Kobling:** Containere kobles på Microbizz-id, ellers på nummeret, uden hensyn til store og små bogstaver. Nye containere oprettes med den status, der er valgt under Opsætning: "venter på plads" (standard) eller "udleveret", hvilket bruges ved første import. Hentningen ændrer aldrig status eller plads for en eksisterende container.
- **Fjernet i Microbizz:** Dækker slettet, deaktiveret eller ikke længere i den valgte gruppe. Står containeren på lageret eller er i transit, bliver den på tavlen med advarslen "Fjernet i Microbizz", og kontoret tager stilling. Ellers skjules den (soft delete). Kommer den tilbage, fjernes advarslen igen.
- **Microbizz bestemmer:** For koblede containere kan nummer, navn og kunde ikke rettes her: ikke i panelet, ikke ved hjemkomst og ikke ved fortryd. For koblede kunder kan nummer og navn ikke rettes. Når integrationen er slået til, oprettes nye containere og kunder kun i Microbizz. "Ny container" på tavlen bliver til "Hent nye fra Microbizz".
- **Log:** Ændringer fra Microbizz logges som "Opdateret fra Microbizz" med Microbizz som afsender. De kan ikke fortrydes.
- **Hvornår:** Hver time kl. 6 til 18 via Laravels planlægger, og med knapperne "Hent nu" og "Hent alt forfra" under Opsætning samt "Hent nye fra Microbizz" på tavlen. Hver hentning tager kun ændringer siden sidst og bruger Microbizz' egen dato og tid som nyt udgangspunkt. Store svar hentes i flere omgange med `resumekey`. Der kan kun køre én hentning ad gangen. Udgangspunktet flyttes kun, når en hentning lykkes. Når udvælgelsen eller felterne ændres, hentes udstyret forfra.
- **Opsætning, fanen Microbizz:** Viser om integrationen er slået til, og har en knap "Test forbindelse", der viser antal udstyr og de grupper og klasser, der findes, med eksempler på felterne. Her ligger også indstillingerne og de seneste 10 hentninger med antal og fejl. Til sidst listen over containere på lageret, der er fjernet i Microbizz.

## 7. Isometrisk visning

- Hver række tegnes som én SVG: dybden går vandret (dybde 1 til venstre ved indgangen), højden går opad, og hver container er en kasse set skråt fra siden, så siden, enden og toppen er synlige. Ingen container skjuler en anden.
- Containerne tegnes genkendeligt: riller i siden, dørende med hængsler og låsestænger, hjørnebeslag. Alt sammen som SVG-former, ingen billeder og ingen WebGL.
- Tomme pladser er stiplede omrids, der lyser grønt når de er lovlige mål under et træk.
- Indgangen markeres med en pil og teksten "Indgang".
- Brikkens størrelse skalerer med skærmbredden; på tablet mindst 48 px høj.
- Hver brik er sit eget SVG-element med pointer-events, så træk og slip arbejder på præcis samme måde som på en flad tavle.

## 8. Adgang

- **Tablet-brugere** vælger bare navn. Ingen kodeord. Formålet er alene sporbarhed i loggen.
- **Admin** logger ind med e-mail og adgangskode via Laravels standard-auth. Alle opsætningsruter ligger bag `auth`. Én admin oprettes ved installation (seeder); flere kan tilføjes under opsætning.
- Ingen rollesystem ud over "tablet-bruger" og "admin".

## 9. Teknik

- PHP 8.4, Laravel 13, Livewire 4 (med Alpine), Tailwind 4, SQLite. Samme stak som mailsecurity-projektet.
- Projektmappe: `C:\lagerstyring`, eget git-repository.
- Lagdeling:
  - `app/Models` – Eloquent-modeller.
  - `app/Services/PlacementRules.php`, `MoveService.php`, `AccessCalculator.php`, `UndoService.php` – al forretningslogik.
  - `app/Livewire/*` – komponenter til tavle, søg, container-panel, log, opsætning. De kalder kun services.
  - `resources/js/drag.js` – Alpine-komponent til træk og slip med Pointer Events (hold-for-at-løfte, lovlige mål, slip).
  - `resources/views/components/iso-row.blade.php` – SVG-tegning af én række.
- Kundefarve: deterministisk hash af kundenummer til en palet på 12 farver med god kontrast i sollys.
- Ingen Node i drift; Tailwind bygges én gang og committes som færdig CSS i `public/build`.

## 10. Test

Pest feature-tests mod SQLite i hukommelsen med et seedet layout, der svarer til jeres (3 × 10 × 3, buffer 4 × 1 × 3, lager 2: 3 × 6 × 3):

- `canTake` / `canPlace` for alle kanttilfælde: forrest/bagest, bund/top, tom række, fuld række.
- Advarsel ved hul.
- Flytning, udlevering, hjemkomst og første placering, inkl. afvisning ved ulovlig kilde/mål.
- "Tavlen er ændret": to brugere flytter samme container.
- `AccessCalculator` på kendte opstillinger (f.eks. inderste bund = 29 flytninger).
- Fortryd: stak-rækkefølge, grænsen på 15, afvisning når kildepladsen er optaget, at loggen bevarer originalen.
- Layoutændring afvises, når berørte pladser ikke er tomme.
- Søgning på alle fire felter.

Livewire-komponenterne testes let (siden renderer, flytning via komponent skriver log). Selve træk og slip verificeres manuelt i browseren på tablet-størrelse.

## 11. Drift

- Hosting hos Curanet (VPS eller webhotel med PHP 8.4). Almindelig Laravel-udrulning med HTTPS.
- Cron kører `php artisan schedule:run` hvert minut; den står for backup og Microbizz-hentning.
- Databasen er én SQLite-fil i `storage/`. En planlagt kommando (`backup:run`) tager dagligt en kopi til `storage/backups/`, beholder de seneste 30, og den seneste kan hentes fra opsætningssiden.
- Ingen offline-tilstand. Tabletten skal have net; skærmen viser tydeligt, når den ikke har.

## 12. Bevidst udeladt (kan komme senere)

Containerstørrelser, arbejdslister/planlagte flytninger, pladsreservationer ud over transit, flere kunder pr. container, roller ud over admin, offline-tilstand, websockets, ægte 3D, eksport/rapporter, notifikationer.
