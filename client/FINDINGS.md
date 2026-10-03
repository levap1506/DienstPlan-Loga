# Note tehnice și constatări LOGA3

Acest document descrie observațiile tehnice și limitele clientului. Nu conține credențiale, cookie-uri, tokenuri XSRF, date personale sau răspunsuri de la server.

## Obiectiv

Au fost analizate trasee după autentificare:

1. **Generierte Dokumente**: documente grupate pe luni; se descarcă doar elementele noi sau modificate.
2. **Zeiten**: selectarea unei luni și producerea unui calendar și Zeitprotokoll pentru fiecare lună încheiată.
3. **privateRPC (Mask/PEP)**: transport criptat GWT; folosit pentru exportul proprietar „Zeitprotokoll generieren" (`reports`) și pentru crearea de Dienstsplit-uri.
4. **Cereri Smarte Dinge**: „Erfassung Rufbereitschaft Einsatz" (`request`) — Kommen/Gehen și Telefoneinsatz, direct din CLI.

`loga3_downloader.py` este un client HTTP direct, fără Selenium, Playwright sau un browser automatizat.

## Autentificare

Portalul folosește GWT, însă fluxul analizat nu presupune criptografie de rețea suplimentară. Scriptul află din resursele publice versiunea LOGA și identificatorii GWT necesari, apoi:

1. trimite request-ul GWT la `bts/<versiune>/Login/LoginSrv`;
2. verifică primirea cookie-ului `JSESSIONID`;
3. deschide `private/layout?action=afterlogin`;
4. preia tokenul de protecție la `private/xsrf`;
5. păstrează cookie-urile și tokenul numai în memoria unui `requests.Session`.

Parola este luată din `LOGA_PASSWORD` sau din prompt-ul mascat și nu ajunge în manifest, stare persistentă, loguri sau Git.

## Traseul 1 — Generierte Dokumente

Lista dashboard / Personal Cloud se încarcă prin:

```text
GET private/api/dashboard/personalCloud/loadFiles
```

Documentul se descarcă după identificatorul primit în metadata:

```text
GET private/document?document-id=<id>
```

Aceasta este sursa implicită pentru `generated --source dashboard`.

Pentru arborele unei persoane este disponibilă și TalentCard / Personalakte:

```text
GET private/api/TalentCard/personalAkte/generated
GET private/api/TalentCard/personalAkte/download/<...>
```

Această variantă necesită `man`, `ak` și `pnr`, prin argumente sau prin `LOGA_MAN`, `LOGA_AK`, `LOGA_PNR`.

### Deducuplicare

`downloads/manifest.json` păstrează metadata și SHA-256 pentru fiecare fișier.

- Metadata neschimbată plus hash local valid: nu se cere iar conținutul.
- `--verify-all`: descarcă iar și compară SHA-256.
- Conținut identic: reutilizează o copie locală prin hard-link, iar unde nu este posibil prin copiere.
- Conținut nou la aceeași destinație: versiunea precedentă se mută în `downloads/archive`, fără ștergere definitivă.

Prin urmare, numele fișierului nu este singurul criteriu de deduplicare.

## Traseul 2 — luni și rapoarte

În „Zeiten”, interfața permite navigarea între luni, selectarea unei luni și deschiderea acțiunilor prin pictograma de cheiță. Au fost observate următoarele identificatoare Smarte Dinge:

| Acțiune UI | Identificator observat |
| --- | --- |
| PDF generieren | `LAGSDKPF` |
| Zeitprotokoll generieren | `LAGSDZPG` |

Clientul folosește API-ul dashboard pentru datele lunii:

```text
GET private/api/dashboard/calendar/loadEventsInRange?from=YYYY-MM-01&to=YYYY-MM-DD
```

`MonthlyDirectExporter` trimite câte un request pe lună, păstrează răspunsul ca `calendar-data.json` și extrage intrările datate, intervalele și duratele disponibile. Cu ReportLab produce local:

```text
monthly/YYYY-MM/kalendarium.pdf
monthly/YYYY-MM/zeitprotokoll.pdf
```

Intervalul implicit este `2024-10` până la ultima lună complet încheiată. Luna curentă este exclusă implicit; `--start`, `--end` și `--only-missing` permit control explicit.

Fingerprint-ul rapoartelor include hash-ul răspunsului calendarului, luna și tipul raportului. Un răspuns calendar schimbat regenerează fișierul; `--only-missing` păstrează intenționat orice fișier existent.

## Limita importantă: PDF local vs. export proprietar

PDF-urile scriptului sunt rapoarte locale bazate pe datele calendarului. Permit rularea periodică fără browser și păstrează JSON-ul brut pentru audit, dar nu sunt copii binar identice ale PDF-urilor LOGA.

În analiza UI, „PDF generieren” a fost asociat cu generare client-side, iar „Zeitprotokoll generieren” cu un document pregătit de server și afișat în preview. Apelurile Smarte Dinge sunt GWT-RPC compactat și poartă contextul calendarului. Nu s-a presupus că un corp RPC capturat pentru o lună poate fi reutilizat corect la toate lunile.

Dacă este obligatoriu ca rezultatul să fie exact exportul proprietar LOGA, este necesară o captură autorizată request/response pentru fiecare Smart Thing și validarea ei pe mai multe luni. Această extensie nu este inclusă acum.

## privateRPC — transport criptat (implementat)

Apelurile `/loga3/privateRPC/<Service>` (Mask/PEP, DataMining, ServerData etc.)
sunt GWT-RPC împachetat cu o criptare de transport. A fost derivată din JS-ul
public L2Main și este implementată în `loga_rpc.py`:

- cheia AES-192 = `b"1$7d%C&S"` + `token[8:24]`, unde `token` este valoarea
  `Rpc-Xsrf` (aceeași ca `?xsrf=` din REST);
- cifru: **AES-192-CBC, IV zero, PKCS#7** (determinist);
- corpul requestului = hex( AES( base64( `7|3|<n>|<moduleBase>|<strongName>|49|<token>|_|<method>|<args>` ) ) );
- headere: `Content-Type: text/x-gwt-rpc; charset=utf-8`, `Rpc-Xsrf`,
  `X-GWT-Module-Base: .../<versiune>/L2Main/`, `X-GWT-Permutation`,
  `rpc-context-app: LOGA`, `rpc-context-msk: LWSPEP`.

Astfel, un corp capturat poate fi **decriptat**, șablonat (token/lună/ID) și
**re-criptat** pentru fiecare apel, în loc să fie rejucat opac. Argumentele
`<args>` rămân serializarea GWT proprie fiecărui build, deci se folosesc
șabloane din capturi, nu construcție de la zero. `loga_rpc.py` conține un
auto-test cu un vector fix care verifică atât decriptarea, cât și faptul că
`encrypt(decrypt(x))` reproduce exact corpul original.

## Mask/privateRPC — crearea unui Dienstsplit (finding, 2026-10-02)

Obiectiv: crearea unui split (alocare împărțită între doi angajați) direct din
bot, prin `MaskActionSrv.callMaskAction`, în loc de UI.

**Ce funcționează**
- Transportul criptat (mai sus) este corect: apelurile nemaskate reușesc din
  sesiunea botului, ex. `commonGwtService.shouldRefreshTerminal` →
  `//OK[0,1,["8kc"],3,7]`, `navigation.loadClientInitData` → `//OK[...]`,
  `commonGwtService.loga3OpenParams` → `//OK[...]`.
- Headerele necesare: `Content-Type: text/x-gwt-rpc; charset=UTF-8`,
  `Referer: .../private/layout?action=afterlogin`, `Rpc-Xsrf`,
  `X-GWT-Module-Base: .../<build>/L2Main/`, `X-GWT-Permutation`,
  `rpc-context-app: LOGA`, `rpc-context-msk: LWSPEP`.

**Metode Mask observate (build `20260813015921482`):** `MaskActionSrv.callMaskAction`;
`MaskDataGwtService.getMaskConfig`, `getToolbarLabelData`,
`getSplitScreenEnableDisableOption`, `readSingleDataWithOptions`,
`getTableViewFilteringUserDefaultValues`, `writeProtUserLog`;
`ServerDataGwtService.saveContext`, `notifyContextChange`, `getLogaMessages`,
`getAkAbstand`, `loadCorporateStyleMap`.

**Blocajul:** identificatorii de mask `4c060823-0fdb-43b0-b988-7377c71f3f49`
și `y2em5e3qC1fcZrCggryW` sunt **constante** (identici între logări), deci nu
sunt generați per sesiune. Cu toate acestea, din sesiunea botului toate
apelurile Mask (`getToolbarLabelData`, `getMaskConfig`, `saveContext`,
`notifyContextChange`, `callMaskAction`) răspund **HTTP 500**, deși
envelope-ul, id-urile și headerele sunt identice cu cele din browser. Apelurile
nemaskate din aceeași sesiune răspund 200. Prin urmare 500-ul nu ține de
criptare/id/headere, ci de **lipsa contextului server-side al aplicației PEP**
(masca deschisă în sesiune). Rejucarea pașilor REST de bootstrap cunoscuți
(`spepdatachangeservice/updatetabs`) nu deblochează apelurile Mask.

**Rezolvare (2026-10-03):** blocajul nu era criptarea, ci **lipsa bootstrap-ului
măștii**. Primul `callMaskAction` pe care aplicația îl trimite la deschiderea
măștii (envelope de 19 elemente, fără interval) **deschide masca în sesiunea
server-side**; imediat după el, toate apelurile Mask (`getToolbarLabelData`,
`callMaskAction`, …) răspund **200**, inclusiv din sesiunea botului. Secvența
minimă necesară, verificată cap-coadă:
1. `callMaskAction` — acțiunea de deschidere (`LOGA_MASK_OPEN_ACTION`);
2. `callMaskAction` — încărcarea lunii țintă (`LOGA_MASK_MONTH_ACTION`);
3. `callMaskAction` — acțiunea de creare split (`LOGA_SPLIT_ACTION_TEMPLATE`).

Envelope-urile se completează cu `LogaRpc::fill()` + token/`moduleBase` per
sesiune. `LogaSplitCreator::create()` execută automat pașii 1–2 înainte de 3.

**Dovadă:** pentru un split real (owner `3021652`, partner `9003722`, objekt
`VSÄDNCH`, shift `*\!ÄDNCHR09\!r2`) răspunsul botului este **identic, octet cu
octet**, cu cel al browserului:
`//OK[0,3,0,2,0,0,2,0,0,0,0,0,0,0,3,0,0,0,4,0,3,0,2,0,1,["3nd","8mb","8mj","8mh"],3,7]`.
Crearea este idempotentă (re-trimiterea nu duplică split-ul).

**Explorare anterioară (2026-10-02) — de ce părea imposibil:**
- Pagina `private/layout` expune `maskToOpen`/`l3MaskIdToOpen`/`l3ParametersToOpenWith`,
  dar ele sunt umplute de GWT (JS), nu din query params.
- Rejucarea secvenței REST de bootstrap (`loadActualUserData`, `loadobjekts`,
  `loadtabs`, `spepdatachangeservice/updatetabs`, `spepdataservice/*`, …) → 200,
  dar nu stabilea contextul de mască; lipsea exact `callMaskAction` de deschidere.
- Un POST simplu de login (fără JS/2FA) nu autentifică; sesiunea reală vine din
  login-ul interactiv.

**Artefacte:** `loga/LogaRpc.php`, `LogaClient::privateRpc()`,
`loga/LogaSplitCreator.php` (creare funcțională: bootstrap mască + template),
`loga/client/loga_rpc.py` (+ self-test).

## Masca L3 „Zeitdaten" și exporturile proprietare (finding, 2026-10-03)

După login, masca L3 „Zeitdaten" (`LZWZEITD`) se creează printr-un singur apel
GWT:

```text
POST privateRPC/maskCreationService
envelope: 7|3|8|<moduleBase>|B0DCAB5410DA1EFFB4E3F4A1A02669CE|49|<xsrf>|_|openMask|8l4|LZWZEITD|1|2|3|4|5|6|1|7|8|
```

Răspunsul conține instanța de mască `LZWZEITD_<millis>` și **toate**
identificatoarele Smarte Dinge (`LAGSDKPF`, `LAGSDZPG`, `LAGSDZWS`, …).

Operațiile pe mască sunt `maskActionService.actionMask`:

```text
envelope: ...|actionMask|8l4|22p|LZWZEITD_<millis>$<metoda>|2wa|<man><ak><pnr>1|...
metode: sendCurrentTimeKontoToClient, loadFilterData, generateTimeDocument, logUserAction
```

Datele lunare vin și prin `calendarCacheService.getData` (cu masca `LZWZEITD`).
În `logUserAction` apar acțiunile „PDF generieren" (`LAGSDKPF`,
`documentdownload`) și „Zeitprotokoll generieren" (`LAGSDZPG`, `documentalt`).
Documentul generat se descarcă prin:

```text
GET private/document?xsrf=<xsrf>&document-id=<id>
```

Fiecare rulare creează o instanță nouă (id-ul include un millis), deci nu se
refolosește între sesiuni.

**Client:** `LogaClient.private_rpc()` trimite apelurile criptate, iar
`MonthlyRecipeRunner` execută un profil `rpc_profiles.json` cu `rpc_service` +
`envelope` (templat cu `{{XSRF}}`, `{{L2_MODULE_BASE}}`, `{{MAN}}{{AK}}{{PNR}}`)
și `extract` (ex. `MASK_INSTANCE`), apoi descarcă PDF-ul prin
`private/document?document-id=...` sau prin `poll_dashboard`. Comanda:

```text
python loga3_downloader.py reports --start 2024-10 --only-missing
```

Un exemplu complet, cu envelope-urile capturate, este în
`rpc_profiles.example.json`.

## Private Cloud — Gehaltsabrechnungen și alte documente (finding, 2026-10-03)

Widget-ul „Private Cloud" folosește masca L3 `LMADOKMT` (security
`LMAWADOK`). Fluxul complet, capturat:

```text
GET  private/api/dashboard/personalCloud/loadFiles?securityId=LMAWADOK&maskId=LMADOKMT
POST privateRPC/maskCreationService   openMask|8l4|LMADOKMT|...        → instanța LMADOKMT_<millis>
POST privateRPC/maskActionService     LMADOKMT_<i>$loadMenus
POST privateRPC/maskActionService     LMADOKMT_<i>$indexFilesForElasticSearch
POST privateRPC/maskActionService     LMADOKMT_<i>$loadFilesForScreen
POST privateRPC/maskActionService     LMADOKMT_<i>$getNewItemsBadgeCount
POST privateRPC/maskActionService     LMADOKMT_<i>$generatePreview|2wa|<fileId>
POST privateRPC/maskActionService     LMADOKMT_<i>$saveFileAccessInfo|2wa|<fileId>
GET  private/api/dashboard/personalCloud/loadPreview/<index>/GENERATED  → imagine PNG
GET  private/document?document-id=<id>                                  → PDF
```

- `loadFiles` întoarce `{name, extension, docId, menuType:"GENERATED", created,
  formattedCreationDate, payslip, ...}`. Când masca nu e deschisă, `docId` sunt
  indici locali (0,1,2); după `openMask` devin id-uri globale (ex. 67,71,106).
- **Numele real** (cu lună și tip) vine din `generatePreview`, nu din `loadFiles`:
  `Abrechnung AN Standard_September_2026.pdf` sau
  `Meldebescheinigung Zusatzversorgung AN_Januar_2026.pdf`. Deci Cloud-ul nu
  conține doar fluturași de salariu; există un caz special în ianuarie
  (Meldebescheinigung). Descărcarea are și `Content-Disposition` cu același nume.
- `loadPreview/<index>/GENERATED` întoarce un **PNG** (funcționează și din
  sesiunea clientului).

**Blocaj rămas:** `private/document?document-id=<id>` nu folosește `docId`-ul din
`loadFiles`; este un id (aparent secvențial) rezolvat de server doar în sesiunea
care a deschis Cloud-ul prin fluxul aplicației. Din sesiunea clientului (chiar și
după `openMask` + `loadFilesForScreen`) răspunde **500**. Clientul poate deci
lista fișierele și numele lor reale, dar descărcarea PDF necesită încă un pas
(maparea fileId → document-id), care trebuie capturat.

## Cerere „Erfassung Rufbereitschaft Einsatz" (finding, 2026-10-03)

SmartThing-ul „Erfassung Rufbereitschaft Einsatz" (Smart ID `L3SDCHOMF8U`,
descriere „SD zur Zeiterfassung NUR für Einsatzzeiten in der Rufbereitschaft")
se trimite prin masca Zeitdaten:

```text
POST privateRPC/maskActionService   LZWZEITD_<i>$timeAttendanceServerMaskPart$loadMaskPartData   (deschide formularul)
POST privateRPC/maskActionService   LZWZEITD_<i>$timeAttendanceServerMaskPart$submitEventData     (trimite)
                                    → //OK[0,1,["310"],3,7]
```

Envelope-ul `submitEventData` (84 de elemente) conține **setul complet de
evenimente al zilei** (GUID-uri + ore + simboluri `KO`/`GE`/`TA`/`TE`/…),
identitatea `SBKSBK30174841` și constanta `L3SDCHOMF8U`; data țintă apare ca
`2026-10-02T…`. Trimiterea salvează evenimentele zilei, deci pentru o zi nouă
GUID-urile trebuie generate de client, iar orele/datele templatate.

**Diff între două trimiteri (2026-10-03):** payload-ul `submitEventData` NU
conține doar intrarea nouă, ci **întregul set de evenimente al zilei** (blocul
normal de lucru + perechile `Kommen`/`Gehen`), fiecare eveniment cu GUID propriu.
La adăugarea unui eveniment crește tabelul de string-uri (84 → 93) și apar
GUID-uri noi (ex. `9A95796B-…`, `51ADB605-…`) plus ora nouă
(`2026-10-03T22:30:00.000`). De aceea un șablon fix este nesigur: ar rescrie și
blocul normal (care trebuie păstrat). Pentru push e nevoie de: citirea zilei,
înlocuirea doar a perechii `Kommen`/`Gehen` (GUID-uri noi) și retrimiterea
întregului set — adică reconstrucția modelului de evenimente al măștii.

**Rezolvat (2026-10-03):** secvența completă, verificată din client:

```text
openMask LZWZEITD
…$loadInitialEventData      (înregistrează evenimentele zilei în mască)
…$loadMaskPartData          (deschide formularul)
…$submitEventData           (trimite setul zilei)
POST rest/frmpart           operationType=update, dataSource=ds_<inst>$timeAttendanceServerMaskPart
```

`submitEventData` singur **nu persistă** (răspunde `//OK[0,1,["310"],3,7]`, dar
nu scrie nimic); `frmpart` este commit-ul efectiv. GUID-urile evenimentelor
trebuie regenerate (`uuid4`) la fiecare trimitere.

Comandă: `loga3_downloader.py request --date YYYY-MM-DD --kommen HH:MM
--gehen HH:MM [--telefon-anfang HH:MM --telefon-ende HH:MM]` (orele de telefon
sunt în ziua următoare). Șabloanele capturate sunt în `loga_requests.py`.

Un Antrag poate conține **mai multe** perechi `Kommen`/`Gehen` și mai multe
`Telefoneinsatz`, în aceeași zi sau după miezul nopții; formatul CLI pentru
multi-intrare (flag-uri repetate, sufix `+1` pentru ziua următoare) este
proiectat dar necesită o captură multi-intrare pentru a templata corect setul de
evenimente.

## Date locale și protecție

```text
downloads/
  generated/                    documente descărcate
  monthly/YYYY-MM/
    calendar-data.json           răspuns calendar pentru audit
    kalendarium.pdf              raport local
    zeitprotokoll.pdf            raport local
  archive/                       versiuni înlocuite
  manifest.json                  hash-uri și metadata
  .state/runtime.json            identificatori publici de build GWT
```

`.gitignore` exclude `.venv`, `downloads`, `__pycache__` și `rpc_profiles.json`. Datele angajatului și ieșirile de rulare nu se adaugă în repository.

## Verificări și limitări

- Parsarea sintactică Python a trecut pentru versiunea publicată.
- `self-test` testează offline calculul lunilor, manifestul SHA-256, arhivarea și randarea PDF deterministă.
- Verificat live (2026-10-03) din client, fără browser:
  - `reports` → Zeitprotokoll PDF real (`private/document?document-id=…`);
  - `request` → cerere Rufbereitschaft persistată (`…submitEventData` + `rest/frmpart`);
  - crearea de Dienstsplit prin `privateRPC` (bootstrap mască + `callMaskAction`).

**Limitări rămase:**
- `request` suportă deocamdată **o singură** pereche `Kommen`/`Gehen` și **una**
  `Telefoneinsatz` per zi. Un Antrag poate conține mai multe perechi (în aceeași
  zi sau după miezul nopții); generalizarea necesită încă o captură multi-intrare.
- Descărcarea PDF din Private Cloud (`document-id`) rămâne legată de sesiunea
  care a deschis Cloud-ul (vezi secțiunea Private Cloud).
- Șabloanele `submitEventData` conțin identitatea `SBK/SBK/3017484`; pentru altă
  persoană trebuie înlocuită.

Comenzi utile:

```powershell
python loga3_downloader.py self-test
python loga3_downloader.py --verbose login
python loga3_downloader.py generated --source dashboard
python loga3_downloader.py monthly --start 2024-10
python loga3_downloader.py reports --start 2024-10 --only-missing
python loga3_downloader.py request --date 2026-09-10 --kommen 16:01 --gehen 22:41 `
  --telefon-anfang 00:24 --telefon-ende 00:28
```

- `generated` — documentele dashboard/TalentCard (Cloud, inclusiv fluturași de
  salariu și Meldebescheinigung); numele local include `docId` pentru unicitate.
- `monthly` — calendar + Zeitprotokoll **local** (ReportLab), pe lună.
- `reports` — exportul **proprietar** Zeitprotokoll prin `privateRPC` (profil
  `rpc_profiles.json`).
- `request` — cerere Rufbereitschaft (`Kommen`/`Gehen` + opțional
  `Telefoneinsatz`) pentru o zi.

TLS este verificat implicit. `--insecure` este numai pentru diagnostic pe stații care nu pot valida certificatul server.
