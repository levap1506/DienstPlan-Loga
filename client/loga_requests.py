"""LOGA „Erfassung Rufbereitschaft Einsatz" (Smarte Dinge ``L3SDCHOMF8U``).

Trimiterea unei „cereri" de tip Rufbereitschaft se face prin masca L3
``LZWZEITD`` într-o secvență fixă de patru pași, apoi un commit REST:

1. ``maskCreationService.openMask`` ``LZWZEITD``            (context + instanță)
2. ``maskActionService`` ``…$loadInitialEventData``        (înregistrează ziua)
3. ``maskActionService`` ``…$loadMaskPartData``            (deschide formularul)
4. ``maskActionService`` ``…$submitEventData``             (trimite evenimentele)
5. ``POST rest/frmpart`` ``operationType=update``          (persistă efectiv)

Payload-ul de la pasul 4 este **întregul set de evenimente al zilei** (blocul
normal + perechile Kommen/Gehen/Telefoneinsatz), fiecare eveniment cu GUID
propriu. Șabloanele de mai jos sunt capturi reale; clientul înlocuiește data,
orele și GUID-urile (``uuid4``) înainte de trimitere.

ATENȚIE: apelurile de mai sus scriu date reale de timp în LOGA. Testați pe o zi
viitoare sau corectați în LOGA dacă ceva nu e corect.
"""

from __future__ import annotations

import re
import uuid
from datetime import date, datetime, timedelta
from typing import Any
from urllib.parse import urljoin

# ---------------------------------------------------------------------------
# Șabloane capturate (build 20260813015921482). `{TOKEN}` / `{INST}` se
# completează per sesiune; data/orele se înlocuiesc mai jos.
# ---------------------------------------------------------------------------

INSTANCE_RE = re.compile(r"(LZWZEITD_\d+)")

_SIMPLE = (
    "7|3|85|{MB}|B0DCAB5410DA1EFFB4E3F4A1A02669CE|49|{TOKEN}|_|actionMask|8l4|22p|"
    "{INST}$timeAttendanceServerMaskPart$submitEventData|2wa|SBKSBK30174841|141|8m5|"
    "2026-10-02T00:00:00.000|8lx|143|13x|K1|13w|G1|Mobiles Arbeiten Ende|K2|G2|"
    "Stillzeit Ende|KO|GE|Gehen|Mobiles Arbeiten Anfang|Stillzeit Anfang|Kommen|PE|PA|"
    "Pause Anfang|Pause Ende|SE|SA|Schule Anfang|Schule Ende|TE|TA|Telefoneinsatz Anfang|"
    "Telefoneinsatz Ende|2026-10-03T02:08:00.000||37i|LOGA3TA|37r|8kc|8kq|13v|"
    "2026-10-03T07:30:00.000|4914D388-F979-4A5C-9758-2037E524EBB0|*|"
    "BBDA09CF-936B-46A8-BF71-F0002A7B6069|LOGA-time|2026-10-03T16:00:00.000|"
    "7064ABFB-7726-450E-B944-2051127AC83C|CBE07507-7E92-4D85-BACF-A2D8AC334445|"
    "2026-10-03T17:00:00.000|67BB0E7F-54D8-4629-94AA-D4E196882BDB|"
    "2026-10-03T01:54:00.000|31D203B9-57C4-4B02-87BD-8B61C89015F5|"
    "2026-10-03T00:00:00.000|zl|36m|Zeiterfassung|282|8lf|8.00|2026-10-02T16:00:00.000|"
    "2026-10-02T07:30:00.000|23k|23l|23e|check|23f|#FFFAD9|"
    "539228c341f8d60faff7579a6ae87b849ca4d77b|2026-10-02T23:59:59.000|#ffde00|1x1|"
    "L3SDCHOMF8U|02.10.2026  - Arbeitszeit 8h:00m|??8h:00m??|272|1|2|3|4|5|6|2|7|8|9|8|"
    "3|10|7|11|12|0|1|0|0|13|14|15|12|16|17|1|0|0|0|18|0|0|19|1|0|0|20|0|21|16|-9|0|0|"
    "0|22|0|0|19|0|0|0|23|0|24|16|-9|0|0|0|25|0|0|-10|0|0|26|0|27|16|17|0|0|0|0|20|0|0|"
    "-12|0|0|18|0|28|16|-15|0|0|0|23|0|0|-10|0|0|22|0|29|16|-15|0|0|1|26|0|1|-12|0|0|"
    "25|0|30|16|-15|0|0|1|31|0|0|-10|0|0|32|0|33|16|-9|0|0|0|32|0|0|-12|0|0|31|0|34|16|"
    "-15|0|0|0|35|0|0|-12|0|0|36|0|37|16|-9|0|0|0|36|0|0|-10|0|0|35|0|38|16|-15|0|0|0|"
    "39|0|0|-12|0|0|40|0|41|16|-9|0|0|0|40|0|0|-10|0|0|39|0|42|0|0|0|1|1|1|13|43|0|0|"
    "44|0|44|45|A|46|47|0|0|0|0|48|1|0|0|49|0|44|15|4|50|44|0|0|0|13|51|52|0|0|0|53|13|"
    "51|54|0|55|25|0|0|13|14|13|14|-17|50|44|0|0|0|13|56|57|0|0|0|53|13|56|58|0|55|26|0|"
    "0|13|14|13|14|-13|50|44|0|0|0|13|59|60|0|0|0|0|0|0|0|44|44|0|0|13|14|13|14|-17|50|"
    "44|0|0|0|13|61|62|0|0|0|0|0|0|0|44|44|0|0|13|63|13|14|-13|0|0|15|0|0|0|15|0|0|0|0|"
    "0|48|0|64|0|1|0|0|65|2|66|67|13|14|68|69|13|70|13|71|0|0|15|1|72|15|1|73|74|3|75|"
    "76|1|0|0|0|0|0|0|0|0|0|0|0|77|0|0|78|0|1|0|0|0|0|0|1|0|0|0|0|0|0|0|0|1|0|0|13|14|"
    "13|79|15|0|80|0|81|84|0|0|0|0|0|78|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|"
    "0|0|0|11|0|0|44|1|0|0|0|0|44|82|0|-59|0|0|0|0|0|13|14|13|14|0|0|0|0|0|0|0|0|0|83|"
    "84|1|0|0|85|2|1|0|0|0|0|0|0|0|0|0|10|7|82|"
)

_TELEFON = (
    "7|3|89|{MB}|B0DCAB5410DA1EFFB4E3F4A1A02669CE|49|{TOKEN}|_|actionMask|8l4|22p|"
    "{INST}$timeAttendanceServerMaskPart$submitEventData|2wa|SBKSBK30174841|141|8m5|"
    "2026-10-02T00:00:00.000|8lx|143|13x|K1|13w|G1|Mobiles Arbeiten Ende|K2|G2|"
    "Stillzeit Ende|KO|GE|Gehen|Mobiles Arbeiten Anfang|Stillzeit Anfang|Kommen|PE|PA|"
    "Pause Anfang|Pause Ende|SE|SA|Schule Anfang|Schule Ende|TE|TA|Telefoneinsatz Anfang|"
    "Telefoneinsatz Ende|2026-10-03T02:19:00.000||37i|LOGA3TA|37r|8kc|8kq|13v|"
    "2026-10-03T07:30:00.000|FA0A8CD6-FA46-4BEA-BC47-3066C0745BF4|*|"
    "61D56B07-304C-44B4-85E7-35A716635CBB|LOGA-time|2026-10-03T16:00:00.000|"
    "F657640A-8788-47B0-9117-39BE0F08912C|D328196C-9391-4768-A7EA-97AD6D313085|"
    "2026-10-03T16:01:00.000|47BB9DBB-DAC0-455A-A06A-93FC73ECE4B1|"
    "2026-10-03T22:41:00.000|BA542083-9184-41AA-9625-50DC20CDC59F|"
    "2026-10-03T00:24:00.000|D18F1F1A-7821-4A94-82F3-A3259A53647A|"
    "2026-10-03T00:00:00.000|2026-10-03T00:28:00.000|B910B038-7729-4957-B5C2-0DE0B55143E5|"
    "zl|36m|Zeiterfassung|282|8lf|8.00|2026-10-02T16:00:00.000|2026-10-02T07:30:00.000|"
    "23k|23l|23e|check|23f|#FFFAD9|539228c341f8d60faff7579a6ae87b849ca4d77b|"
    "2026-10-02T23:59:59.000|#ffde00|1x1|L3SDCHOMF8U|"
    "02.10.2026  - Arbeitszeit 8h:00m|??8h:00m??|272|1|2|3|4|5|6|2|7|8|9|8|3|10|7|11|12|"
    "0|1|0|0|13|14|15|12|16|17|1|0|0|0|18|0|0|19|1|0|0|20|0|21|16|-9|0|0|0|22|0|0|19|0|"
    "0|0|23|0|24|16|-9|0|0|0|25|0|0|-10|0|0|26|0|27|16|17|0|0|0|0|20|0|0|-12|0|0|18|0|"
    "28|16|-15|0|0|0|23|0|0|-10|0|0|22|0|29|16|-15|0|0|1|26|0|1|-12|0|0|25|0|30|16|-15|"
    "0|0|1|31|0|0|-10|0|0|32|0|33|16|-9|0|0|0|32|0|0|-12|0|0|31|0|34|16|-15|0|0|0|35|0|"
    "0|-12|0|0|36|0|37|16|-9|0|0|0|36|0|0|-10|0|0|35|0|38|16|-15|0|0|0|39|0|0|-12|0|0|"
    "40|0|41|16|-9|0|0|0|40|0|0|-10|0|0|39|0|42|0|0|0|1|1|1|13|43|0|0|44|0|44|45|A|46|"
    "47|0|0|0|0|48|1|0|0|49|0|44|15|6|50|44|0|0|0|13|51|52|0|0|0|53|13|51|54|0|55|25|0|"
    "0|13|14|13|14|-17|50|44|0|0|0|13|56|57|0|0|0|53|13|56|58|0|55|26|0|0|13|14|13|14|"
    "-13|50|44|0|0|0|13|59|60|0|0|0|0|0|0|0|44|44|0|0|13|14|13|14|-17|50|44|0|0|0|13|"
    "61|62|0|0|0|0|0|0|0|44|44|0|0|13|14|13|14|-13|50|44|0|0|0|13|63|64|0|0|0|0|0|0|0|"
    "44|44|0|0|13|65|13|14|-22|50|44|0|0|0|13|66|67|0|0|0|0|0|0|0|44|44|0|0|13|65|13|"
    "14|-23|0|0|15|0|0|0|15|0|0|0|0|0|48|0|68|0|1|0|0|69|2|70|71|13|14|72|73|13|74|13|"
    "75|0|0|15|1|76|15|1|77|78|3|79|80|1|0|0|0|0|0|0|0|0|0|0|0|81|0|0|82|0|1|0|0|0|0|0|"
    "1|0|0|0|0|0|0|0|0|1|0|0|13|14|13|83|15|0|84|0|85|84|0|0|0|0|0|82|0|0|0|0|0|0|0|0|"
    "0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|0|11|0|0|44|1|0|0|0|0|44|86|0|-67|0|0|0|0|0|13|14|"
    "13|14|0|0|0|0|0|0|0|0|0|87|88|1|0|0|89|2|1|0|0|0|0|0|0|0|0|0|10|7|86|"
)

_LOAD_INIT = (
    "7|3|24|{MB}|B0DCAB5410DA1EFFB4E3F4A1A02669CE|49|{TOKEN}|_|actionMask|8l4|22p|"
    "{INST}$loadInitialEventData|2wa|SBKSBK30174841|2uy|8m5|{NOW}|2v4|1x1||L3SDCHOMF8U|"
    "2vr|8lx|KO|GE|TA|TE|1|2|3|4|5|6|2|7|8|9|8|7|10|7|11|12|13|14|12|13|14|15|16|84|"
    "10|7|17|10|7|18|19|20|4|7|21|7|22|7|23|7|24|"
)

_LOAD_PART = (
    "7|3|11|{MB}|B0DCAB5410DA1EFFB4E3F4A1A02669CE|49|{TOKEN}|_|actionMask|8l4|22p|"
    "{INST}$timeAttendanceServerMaskPart$loadMaskPartData|2wa|SBKSBK30174841|"
    "1|2|3|4|5|6|2|7|8|9|8|1|10|7|11|"
)

_UUID_RE = re.compile(r"[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}")


def parse_date(value: str) -> date:
    return datetime.strptime(value, "%Y-%m-%d").date()


def parse_time(value: str) -> str:
    """Acceptă ``HH:MM`` sau ``HH:MM:SS`` și întoarce ``HH:MM``."""
    parts = value.split(":")
    if len(parts) not in (2, 3) or not all(p.isdigit() for p in parts):
        raise ValueError(f"Oră invalidă: {value!r} (folosește HH:MM)")
    return f"{int(parts[0]):02d}:{int(parts[1]):02d}"


def _strip_hms(day_iso: str, time_hm: str) -> str:
    return f"{day_iso}T{time_hm}:00.000"


def build_submit(
    token: str,
    instance: str,
    module_base: str,
    day: date,
    kommen: str,
    gehen: str,
    telefon_anfang: str | None = None,
    telefon_ende: str | None = None,
) -> str:
    """Construiește envelope-ul ``submitEventData`` pentru o zi."""
    d0 = day.isoformat()
    d1 = (day + timedelta(days=1)).isoformat()
    label = day.strftime("%d.%m.%Y")

    if telefon_anfang and telefon_ende:
        tpl = _TELEFON
        tpl = tpl.replace("2026-10-03T16:01:00.000", _strip_hms(d1, kommen))
        tpl = tpl.replace("2026-10-03T22:41:00.000", _strip_hms(d1, gehen))
        tpl = tpl.replace("2026-10-03T00:24:00.000", _strip_hms(d1, telefon_anfang))
        tpl = tpl.replace("2026-10-03T00:28:00.000", _strip_hms(d1, telefon_ende))
    else:
        tpl = _SIMPLE
        tpl = tpl.replace("2026-10-03T17:00:00.000", _strip_hms(d1, kommen))
        tpl = tpl.replace("2026-10-03T01:54:00.000", _strip_hms(d1, gehen))

    tpl = tpl.replace("2026-10-02", d0).replace("2026-10-03", d1)
    tpl = tpl.replace("02.10.2026", label)
    tpl = tpl.replace("{MB}", module_base).replace("{TOKEN}", token).replace("{INST}", instance)
    return _UUID_RE.sub(lambda _m: str(uuid.uuid4()).upper(), tpl)


def submit_rufbereitschaft(
    client: Any,
    day: date,
    kommen: str,
    gehen: str,
    telefon_anfang: str | None = None,
    telefon_ende: str | None = None,
    log: Any = None,
) -> dict[str, str]:
    """Trimite o cerere Rufbereitschaft și o persistă (openMask→…→frmpart)."""
    from loga3_downloader import LogaError  # import ciclic intârziat

    if client.runtime is None or client.xsrf_token is None:
        raise LogaError("Clientul nu este autentificat.")
    base = client.base_url
    module_base = urljoin(base, f"bts/{client.runtime.loga_version}/L2Main/")
    token = client.xsrf_token
    strong = "B0DCAB5410DA1EFFB4E3F4A1A02669CE"

    def call(env: str) -> str:
        env = (
            env.replace("{MB}", module_base)
            .replace("{TOKEN}", token)
            .replace("{INST}", inst)
        )
        return client.private_rpc("maskActionService", env, mask="LZWZEITD")

    # 1. deschide masca
    opened = client.private_rpc(
        "maskCreationService",
        f"7|3|8|{module_base}|{strong}|49|{token}|_|openMask|8l4|LZWZEITD|1|2|3|4|5|6|1|7|8|",
        mask="LZWZEITD",
    )
    match = INSTANCE_RE.search(opened)
    if not match:
        raise LogaError("openMask nu a întors instanța LZWZEITD.")
    inst = match.group(1)

    now = datetime.now().strftime("%Y-%m-%dT%H:%M:%S.000")
    call(_LOAD_INIT.replace("{NOW}", now))
    call(_LOAD_PART)

    submit = build_submit(token, inst, module_base, day, kommen, gehen, telefon_anfang, telefon_ende)
    result = client.private_rpc("maskActionService", submit, mask="LZWZEITD")

    frmpart = (
        f"<request><dataSource>ds_{inst}$timeAttendanceServerMaskPart</dataSource>"
        "<operationType>update</operationType><data/></request>"
    )
    commit = client.request(
        "POST",
        "rest/frmpart",
        data=frmpart.encode("utf-8"),
        headers={"Content-Type": "text/plain; charset=UTF-8"},
        add_xsrf=False,
        expected="frmpart",
    )
    ok = "<status>SUCCESS</status>" in commit.text
    if not ok:
        raise LogaError(f"frmpart nu a reușit: {commit.text[:200]}")
    return {"instance": inst, "submit": result, "frmpart": commit.text}
