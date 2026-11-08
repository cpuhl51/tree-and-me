"""Zählt die Bäume im Wiener Baumkataster für die Statistik der App.
Aufruf: python tools/build_stats.py  ->  schreibt stats.json
Quelle: Stadt Wien – data.wien.gv.at (CC BY 4.0)."""
import csv, io, json, re, sys, urllib.request, datetime, collections, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
URL = ("https://data.wien.gv.at/daten/geo?service=WFS&request=GetFeature&version=1.0.0"
       "&typeName=ogdwien:BAUMKATOGD&srsName=EPSG:4326&outputFormat=csv")
rules = json.loads((ROOT / "regeln.json").read_text(encoding="utf-8"))
closed = set(rules["nicht_zugaenglich"])
nadel = set(rules["nadel_gattungen"])
essbar = [re.compile(p) for p in rules["obst_essbar"]]

def genus(art):
    return (art or "").strip().split(" ")[0] or "unbekannt"

def category(art):
    a = (art or "").strip()
    if genus(a).lower() in nadel:
        return "nadel"
    if any(p.search(a) for p in essbar):
        return "obst"
    return "laub"

def main(path=None):
    if path:
        text = open(path, encoding="utf-8").read()
    else:
        with urllib.request.urlopen(URL, timeout=600) as r:
            text = r.read().decode("utf-8")
    rows = list(csv.DictReader(io.StringIO(text)))
    if len(rows) < 100000:
        sys.exit(f"Zu wenige Datensätze ({len(rows)}) – Abbruch, stats.json bleibt unverändert")
    ok = [r for r in rows if r["GEBIETSGRUPPE"] not in closed]
    stats = {
        "stand": datetime.date.today().isoformat(),
        "quelle": "Stadt Wien – data.wien.gv.at (CC BY 4.0)",
        "gesamt": len(rows),
        "zugaenglich": len(ok),
        "bezirke": dict(sorted(collections.Counter(r["BEZIRK"] for r in ok).items(), key=lambda x: int(x[0]) if x[0].isdigit() else 99)),
        "gattungen": dict(collections.Counter(genus(r["GATTUNG_ART"]) for r in ok).most_common()),
        "kategorien": dict(collections.Counter(category(r["GATTUNG_ART"]) for r in ok)),
    }
    (ROOT / "stats.json").write_text(json.dumps(stats, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"{stats['gesamt']} Bäume, davon {stats['zugaenglich']} zugänglich")

if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else None)
