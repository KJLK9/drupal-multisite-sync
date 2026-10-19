# Demo: wat er gebeurt als iets misgaat

Alles via de schermen, op de twee sites. Duur: ongeveer 6 minuten. Site A is de
bron (`site-a-…`), site B de import (`site-b-…`). Op B staat alles onder
Configuration → System → Import engine (`/admin/config/system/import-engine`).

Voor je begint: draai op B één keer de run set "Catalog", zodat alles gelijk
is, en controleer dat alle breakers dicht staan. De run set draait ook zelf
elk uur (kwartier 15); een geplande run kan een scenario overschrijven, doe de
demo dus niet rond :15.

## 1. Klant zonder klantnummer (de fout staat bij de bron)

1. Site A: `/customer/add`, alleen een naam ("Demo Jansen"), opslaan.
2. Site B: Import definitions → Accounts → Run now (of de hele set).
3. Het resultaat is "completed with errors". Open de run: bij de events staat
   "Item 31 on page 1 has no usable key: the value at customerNumber is empty".
   De rest van de pagina is gewoon geïmporteerd; één slecht item stopt niets.
4. Site A: vul het klantnummer in (`CUST-DEMO-1`). Run Accounts opnieuw: het
   account wordt aangemaakt.

Boodschap: een fout in de bron wordt benoemd, de rest loopt door, en het is
vanzelf hersteld als de bron klopt.

## 2. Een fout die de ontvanger afkeurt (dead letter queue) + een prijs die wacht

Site B accepteert een accountnummer van hoogstens 64 tekens; site A staat er
255 toe.

1. Site A: nieuwe klant "Demo te lang", klantnummer
   `CUST-9999999999999999999999999999999999999999999999999999999999999999999999`.
2. Site A: `/product-price/add`: een prijs voor die klant en een willekeurig
   product (bijvoorbeeld 12,50 EUR).
3. Site B: run set "Catalog" starten. De set stopt bij Agreements met "Items
   wait for a retry".
4. Dead letter queue: het account staat er met "Account number: may not be
   longer than 64 characters". Bij Runs → Agreements staat de prijs als
   wachtend: "The item it refers to, of the import accounts, is not imported
   yet". Dit is het voorbeeld van "wachten, geen fout".
5. Dead letter queue → het item bewerken: maak `customerNumber` korter
   (`CUST-DEMO-2`), "retry after saving" aan, opslaan. Of: vink het item aan
   en kies "Handle selected now".
6. Import definitions → Agreements → Run now. De run gaat verder, de prijs vindt
   zijn klant en is klaar.

Let op, anders verwarrend: de bron heeft nog steeds het lange nummer. Draai
daarna niet nog een keer de hele set, dan mislukt het account opnieuw. Zeg dat
er: "de echte oplossing is bij de bron; hier lossen we het zonder de bron op".

## 3. De bron valt weg (circuit breaker)

1. Circuit breakers: de server van site A staat er, dicht. (Een server staat in
   de lijst zodra er een connectie naar wijst.)
2. Bij die server: Open → bevestigen. Het scherm waarschuwt dat er niets meer
   naartoe gaat.
3. Accounts → Run now. De run stopt direct met "The circuit breaker for … was
   opened by hand; reset it to continue". Er is niets aangeroepen.
4. Circuit breakers → Close → bevestigen. Accounts → Run now gaat verder waar
   hij was.

Echt uitvallen werkt hetzelfde, alleen opent de breaker dan zelf na vijf
mislukte aanroepen op rij en probeert hij na een wachttijd opnieuw.

## 4. Veiligheidsdrempel bij verwijderen

Een bron die opeens een lege of halve lijst geeft, mag de ontvanger niet
leegvegen: als meer dan 20% van de bekende items zou verdwijnen, gebeurt er
niets. Om dat met één klant te laten zien gaat de drempel even omlaag.

1. Site B: Import definitions → Accounts → Edit → stap 5 (Behaviour): drempel
   op 3%, opslaan.
2. Site A: verwijder de klant "Demo Jansen" van scenario 1.
3. Site B: Accounts → Run now. De run krijgt in de samenvatting "Sweep skipped:
   1 of 31 known items (3%) are missing from the source, more than the 3%
   allowed". Het account staat nog gewoon gepubliceerd.
4. Zet de drempel terug op 20% en run opnieuw: nu wordt het account
   gedepubliceerd (het beleid is unpublish, niets gaat verloren; komt de klant
   terug in de bron, dan wordt het account weer gepubliceerd).

## Opruimen na de demo

- Site A: verwijder de demoklanten en de demoprijs.
- Site B: drempel van Accounts terug op 20%, alle breakers dicht, run set
  "Catalog" draaien; de demoaccounts worden gedepubliceerd. Dode items in de
  dead letter queue kunnen daar met "Discard selected" weg.
