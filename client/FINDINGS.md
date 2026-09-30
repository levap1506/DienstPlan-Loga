# Note tehnice și constatări LOGA3

Acest document descrie observațiile tehnice și limitele clientului. Nu conține credențiale, cookie-uri, tokenuri XSRF, date personale sau răspunsuri de la server.

## Obiectiv

Au fost analizate două trasee după autentificare:

1. **Generierte Dokumente**: documente grupate pe luni; se descarcă doar elementele noi sau modificate.
2. **Zeiten**: selectarea unei luni și producerea unui calendar și Zeitprotokoll pentru fiecare lună încheiată.

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
- Nu a fost efectuată în acest checkout o descărcare autenticată cu un cont LOGA real; prima rulare cu contul utilizatorului trebuie să confirme accesul și structura răspunsurilor curente.

Comenzi utile:

```powershell
python loga3_downloader.py self-test
python loga3_downloader.py --verbose login
python loga3_downloader.py generated --source dashboard
python loga3_downloader.py monthly --start 2024-10
```

TLS este verificat implicit. `--insecure` este numai pentru diagnostic pe stații care nu pot valida certificatul server.
