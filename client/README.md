# Client LOGA3 – descărcare directă

Client Python pentru `https://schwarzw.pi-asp.de/loga3`, fără Selenium și fără
Playwright. Autentificarea folosește HTTP direct (LoginSrv, sesiunea LOGA și
tokenul XSRF); parola nu este stocată pe disc.

Pentru traseele analizate, endpoint-uri, comportamentul deduplicării și limitele
soluției, consultați [FINDINGS.md](FINDINGS.md).

## Ce descarcă

- documentele din „Generierte Dokumente” prin dashboard sau TalentCard;
- datele calendarului pentru fiecare lună de la octombrie 2024 până la ultima
  lună încheiată;
- două PDF-uri locale pentru fiecare lună: `kalendarium.pdf` și
  `zeitprotokoll.pdf`.

PDF-urile lunare sunt construite din răspunsul oficial al calendarului din
dashboard, iar `calendar-data.json` este păstrat lângă ele pentru audit. Ele
reproduc informația accesibilă prin calendar, însă nu sunt o copie binară a
exporturilor proprietare „Smarte Dinge”. Exportul proprietar al serverului
(„Zeitprotokoll generieren”) poate fi descărcat separat prin comanda `reports`
(cu profilul RPC din masca L3 „Zeitdaten”), vezi mai jos.

Fiecare fișier este inventariat cu SHA-256. Un conținut deja prezent nu se
descarcă din nou; la schimbare, versiunea precedentă se păstrează în
`downloads/archive`.

## Rulare rapidă

În Windows, dublu-click pe `ruleaza_loga3.bat`. La prima rulare acesta creează
`.venv`, instalează dependențele și solicită utilizatorul/parola LOGA. Rezultatul
este în `downloads`.

Echivalentul în PowerShell:

```powershell
py -m venv .venv
.venv\Scripts\python -m pip install -r requirements.txt
.venv\Scripts\python loga3_downloader.py
```

Opțional, credențialele pot fi furnizate numai pentru sesiunea curentă:

```powershell
$env:LOGA_USERNAME = "utilizator"
$env:LOGA_PASSWORD = "parola"
```

## Comenzi utile

Verifică autentificarea:

```powershell
.venv\Scripts\python loga3_downloader.py login
```

Descarcă documentele dashboard:

```powershell
.venv\Scripts\python loga3_downloader.py generated --source dashboard
```

Pentru arborele TalentCard sunt necesare identificatoarele persoanei:

```powershell
.venv\Scripts\python loga3_downloader.py generated `
  --source talent --man MAN --ak AK --pnr PNR
```

Generează rapoartele pentru un interval explicit. `--only-missing` nu modifică
fișierele deja existente:

```powershell
.venv\Scripts\python loga3_downloader.py monthly `
  --start 2024-10 --end 2026-08 --only-missing
```

Rularea fără subcomandă execută ambele căi: documentele dashboard și rapoartele
lunare.

Rapoartele proprietare (masca L3 „Zeitdaten”, `privateRPC`) se descarcă pe baza
unui profil capturat. Copiați `rpc_profiles.example.json` în `rpc_profiles.json`
(ignorat de git) și setați `LOGA_MAN`, `LOGA_AK`, `LOGA_PNR`:

```powershell
$env:LOGA_MAN = "SBK"; $env:LOGA_AK = "SBK"; $env:LOGA_PNR = "3017484"
.venv\Scripts\python loga3_downloader.py reports `
  --start 2024-10 --end 2026-08 --only-missing
```

Profilul ține, în ordine, apelurile `privateRPC` (masca se creează întâi cu
`openMask`, apoi `actionMask`), valorile extrase (`{{MASK_INSTANCE}}`) și modul
de descărcare (`private/document?document-id=...`). Detalii în
[FINDINGS.md](FINDINGS.md).

## Structura rezultatului

```text
downloads/
  generated/...
  monthly/YYYY-MM/calendar-data.json
  monthly/YYYY-MM/kalendarium.pdf
  monthly/YYYY-MM/zeitprotokoll.pdf
  archive/...
  manifest.json
  .state/runtime.json
```

`manifest.json` și `runtime.json` nu conțin parola, cookie-urile sau tokenul
XSRF.

## Diagnostic

```powershell
.venv\Scripts\python loga3_downloader.py self-test
.venv\Scripts\python loga3_downloader.py --verbose login
```

Folosește `--insecure` doar dacă sistemul local nu poate valida certificatul
serverului; în mod normal verificarea TLS rămâne activă.
