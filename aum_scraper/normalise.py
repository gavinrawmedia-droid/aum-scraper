"""Turn a dealer's raw listing into a clean, comparable record.

Rule of thumb: only record what the dealer actually says. Anything we can't find
is left empty (shown as "Not stated" on the site) rather than guessed.
"""
from __future__ import annotations

import html as html_lib
import re

# ---------------------------------------------------------------- text helpers

def html_to_text(html: str | None) -> str:
    if not html:
        return ""
    h = re.sub(r"<(script|style)[^>]*>.*?</\1>", " ", html, flags=re.S | re.I)
    h = re.sub(r"<br\s*/?>|</(p|li|h\d|tr|div|ul|ol|table)>", "\n", h, flags=re.I)
    h = re.sub(r"</t[dh]>", " | ", h, flags=re.I)
    h = re.sub(r"<[^>]+>", " ", h)
    h = html_lib.unescape(h).replace(" ", " ")
    h = re.sub(r"[ \t]+", " ", h)
    return re.sub(r"\n\s*\n+", "\n", h).strip()


def money(value) -> float | None:
    if value in (None, ""):
        return None
    try:
        return round(float(str(value).replace("£", "").replace(",", "").strip()), 2)
    except ValueError:
        return None


# ---------------------------------------------------------------- brands

# Canonical brand -> pattern that finds the brand name itself.
BRANDS: list[tuple[str, str]] = [
    ("TGA", r"\btga\b"),
    ("Pride", r"\bpride\b"),
    ("Kymco", r"\bkymco\b"),
    ("Rascal", r"\brascal\b|\belectric mobility\b"),
    ("Invacare", r"\binvacare\b"),
    ("Quickie", r"\bquickie\b"),
    ("Sterling", r"\bsterling\b"),
    ("Quantum", r"\bquantum\b"),
    ("Motion Healthcare", r"\bmotion[- ]?healthcare\b|\bmotion\b"),
    ("Drive", r"\bdrive\s+(devilbiss|medical)\b|^\s*drive\b|\bdrive\b(?=\s+(scout|envoy|royale|aerocarbon|autofold|auto ?fold|sport rider|valiant|zoome|st\d|compact|manual fold))"),
    ("Freerider", r"\bfreerider\b|\bluggie\b"),
    ("Monarch", r"\bmonarch\b"),
    ("Excel", r"\bexcel\b"),
    ("Shoprider", r"\bshoprider\b"),
    ("Karma", r"\bkarma\b"),
    ("Ottobock", r"\botto ?bock\b"),
    ("Komfi Rider", r"\bkomfi ?rider\b"),
    ("Greenpower", r"\bgreen ?power\b"),
    ("Li-Tech", r"\bli-?tech\b"),
    ("Scooter Pac", r"\bscooter ?pac\b"),
    ("Ki Mobility", r"\bki mobility\b"),
    ("Permobil", r"\bpermobil\b"),
    ("WHILL", r"\bwhill\b"),
    ("eFoldi", r"\befoldi\b"),
    ("Van Os", r"\bvan ?os\b|\bvanos\b"),
    ("CareCo", r"\bcareco\b"),
    ("One Rehab", r"\bone ?rehab\b"),
    ("Mway", r"\bmway\b"),
    ("Roma Medical", r"\broma\b"),
    ("Betty & Berty", r"\bbetty ?(&|and) ?berty\b"),
    ("Sunrise Medical", r"\bsunrise( medical)?\b"),
    ("Mini Crosser", r"\bmini ?crosser\b"),
    # stairlifts
    ("Stannah", r"\bstannah\b"),
    ("Acorn", r"\bacorn\b"),
    ("Handicare", r"\bhandicare\b"),
    ("Platinum Stairlifts", r"\bplatinum\b(?=.*\bstair)"),
    ("Access BDD", r"\baccess bdd\b"),
    ("Otolift", r"\botolift\b"),
]

# Model name -> brand, for titles that name only the model ("Colt Executive").
MODELS: list[tuple[str, str]] = [
    ("Pride", r"\b(colt|apex (rapid|alumalite|sprint|lite)|go ?go|elite traveller|jazzy|quest|i-go|go chair|atmos|fusion|alumalite|victory|baja|maxima)\b"),
    ("TGA", r"\b(breeze|vita|minimo|supersport|zest|eclipse|scoozy)\b"),
    ("Kymco", r"\b(agility|maxer|midi xls|maxi xls|mini comfort|k-lite|komfy|k-activ|k-chair|super 8)\b"),
    ("Rascal", r"\b(veo|vista dx|vecta|388s?|vie|wego|p327|ultralite|liberty|crusader|imagine|pronto)\b"),
    ("Invacare", r"\b(tdx|storm ?\d|aviva|comet|leo|orion|action 3|pegasus|colibri)\b"),
    ("Quickie", r"\b(q\d{2,3}\s?[rm]\b|q\d{2,3}r|sedeo)"),
    ("Sterling", r"\b(sapphire|s400|s425|s700|little gem)\b"),
    ("Quantum", r"\b(edge (2|3|hd|z)|4front|stretto|q6)\b"),
    ("Motion Healthcare", r"\b(alumina|airium|e-?drive|xcite|xcursion|xquisite|connex|mlite|evolite|foldalite|aerolite|photon|emove)\b"),
    ("Excel", r"\b(galaxy|audax)\b"),
    ("Monarch", r"\b(smarti|genie|vision sport)\b"),
    ("Freerider", r"\b(land ranger|fr1|city ranger|mayfair)\b"),
    ("Drive", r"\b(royale|envoy|scout|aerocarbon|autofold|sport rider|valiant|zoome)\b"),
    ("Shoprider", r"\b(cadiz|sovereign|cameo|bolero|navigator)\b"),
    ("Ottobock", r"\bjuvo\b"),
    ("Karma", r"\b(blazer|leon r|ergo)\b(?!.*\bstair)"),
    ("Stannah", r"\b(siena|starla|solus|sofia)\b(?=.*\bstair)"),
]


def detect_brand(title: str, vendor: str = "", tags: list[str] | None = None,
                 dealer_name: str = "") -> str | None:
    t = (title or "").lower()
    hits = []
    for brand, pat in BRANDS:
        if brand == "Sunrise Medical":
            continue  # parent company: only used if no sub-brand (Quickie, Sterling) is named
        m = re.search(pat, t)
        if not m:
            continue
        if brand == "Motion Healthcare" and not re.search(r"\bmotion[- ]?healthcare\b", t) \
                and not re.search(dict(MODELS)["Motion Healthcare"], t):
            continue  # bare "Motion" only counts alongside a known Motion model
        hits.append((m.start(), brand))
    if hits:
        return min(hits)[1]
    for brand, pat in MODELS:
        if re.search(pat, t):
            return brand
    v = (vendor or "").lower()
    if v and dealer_name and v.strip() in dealer_name.lower():
        v = ""  # vendor is just the dealer's own name
    for brand, pat in BRANDS:
        if v and re.search(pat, v):
            return brand
    for tag in tags or []:
        m = re.match(r"brand[_:-](.+)", tag, re.I)
        if m:
            name = m.group(1).replace("-", " ").strip().lower()
            for brand, pat in BRANDS + MODELS:
                if re.search(pat, name):
                    return brand
    if re.search(r"\bsunrise( medical)?\b", t) or re.search(r"\bsunrise\b", v):
        return "Sunrise Medical"
    return None


# ---------------------------------------------------------------- year, condition

def detect_year(title: str) -> int | None:
    m = re.search(r"\((20[0-3]\d)\)", title or "")
    if not m:
        m = re.search(r"(?<![\w£])(20[0-3]\d)(?![\w%])", title or "")
    return int(m.group(1)) if m else None


def _condition_in(t: str) -> str | None:
    if re.search(r"ex[- ]?demo|ex[- ]?display|showroom model|ex[- ]?showroom|demonstrator|\bdemo\b", t):
        return "Ex-demo"
    if re.search(r"recondition|refurbish", t):
        return "Reconditioned"
    if re.search(r"\bused\b|pre[- ]?owned|preowned|pre[- ]?loved|pre[- ]?used|second[- ]hand|2nd hand", t):
        return "Used"
    return None


def detect_condition(title: str, tags: list[str] | None = None, product_type: str = "",
                     default: str | None = None) -> str:
    """What the title says wins; tags and the product type come next (a shop may tag a whole
    collection "ex-display" while the title says "Approved Used")."""
    found = (_condition_in((title or "").lower())
             or _condition_in(" ".join([" ".join(tags or []), product_type or ""]).lower()))
    if found:
        return found
    if re.match(r"\s*new\b(?!\s*batt)", (title or "").lower()) or re.search(_END_OF_LINE, (title or "").lower()):
        return "New"
    return default or "Not stated"


# ---------------------------------------------------------------- clearance

# "Ground clearance" is a scooter spec, not a sale, so it never counts.
_CLEARANCE = r"(?<!ground )(?<!ground-)\bclearance\b"
_END_OF_LINE = r"\bend[- ]of[- ](?:line|range)\b|\bdiscontinued\b"
USED_CONDITIONS = ("Used", "Reconditioned", "Ex-demo")


def detect_clearance(title: str, tags: list[str] | None = None, product_type: str = "") -> bool:
    """The dealer marks this item itself as clearance or end of line (in its title, tags or type).
    A dealer's description is not read for this: it mentions ground clearance too often."""
    hay = " ".join([title or "", " ".join(tags or []), product_type or ""]).lower()
    return bool(re.search(_CLEARANCE, hay) or re.search(_END_OF_LINE, hay))


def condition_from_text(text: str) -> str | None:
    """For clearance stock only, where the title often names no condition: what the description
    says, if it says one thing clearly. "Brand new batteries" on a used scooter isn't "new"."""
    t = re.sub(r"\s+", " ", (text or "").lower())
    new = re.search(r"\bbrand[- ]new\b(?!\s+(?:batter|tyre|tire|seat|charger|cover|basket|parts?|motor|controller|"
                    r"upholster|fabric|handset|inner))|\bnew (?:and|&) unused\b|\bunused\b|\bboxed new\b|"
                    r"\bnew in (?:the )?box\b|" + _END_OF_LINE, t)
    used = _condition_in(re.sub(r"\b(?:can|could|be|is|are|was|being|been|get|gets|getting) used\b|"
                                r"\bused (?:to|for|by|as|with|on|in)\b|\beasy to use\b", " ", t))
    if new and not used:
        return "New"
    if used and not new:
        return used
    return None


# ---------------------------------------------------------------- category

CATEGORIES = [
    "Road scooter (8mph)",
    "Pavement scooter (4mph)",
    "Mobility scooter (speed not stated)",
    "Boot / folding scooter",
    "Powerchair",
    "Manual wheelchair",
    "Stairlift",
    "Riser recliner chair",
    "Profiling bed",
    "Patient hoist",
    "Bath lift",
    "Other",
]

_STAIRLIFT = r"stair ?-?lift|stair ?-?climber lift|perch stairlift"
_RISER = (r"riser[- ]?recliner|rise[- ]?(and|&|n)[- ]?recline|riser chair|lift chair|"
          r"\b(single|dual|twin|\d|four|five) motor\b.*\b(chair|recliner|sofa)\b|recliner chair")
_BED = r"profiling bed|care bed|hospital bed|nursing bed|homecare bed|adjustable bed|community bed"
_HOIST = (r"(patient|mobile|ceiling|standing|folding|compact|portable|transfer|gantry) hoist|"
          r"stand(ing)? aid|sara stedy|birdie")
_NOT_PATIENT_HOIST = r"\b(boot|car|vehicle|scooter|wheelchair|autochair|rear seats?)\b|smart ?lifter"
_BATH_LIFT = r"bath ?-?lift|bath ?lifter|bathing cushion|bath cushion"

_POWERCHAIR = r"electric wheelchair|power ?chair|power(ed)? wheelchair|electric-wheelchair"
_POWERCHAIR_MODELS = (r"\b(q\d{2,3}\s?[rm]|q\d{2,3}r|sedeo|edge (2|3|hd|z)|4front|stretto|q6 edge|tdx|storm ?\d|"
                      r"aviva|juvo|blazer|leon r|foldalite|photon|aerolite|i-go|go chair|k-chair|wego|p327|whill)\b")
_FOLDING = r"fold|portable|boot|travel|transportable|car[- ]boot|luggie|airline|lightweight"
_ROAD = r"8\s?mph|class[- ]?3|road|all[- ]terrain|6-8 ?mph"


def detect_category(title: str, tags: list[str] | None = None, product_type: str = "",
                    extra: str = "", top_speed_mph: float | None = None, text: str = "") -> str:
    title_l = (title or "").lower()
    meta = " ".join([" ".join(tags or []), product_type or "", extra or ""]).lower()
    both = title_l + " " + meta
    is_scooter_title = "scooter" in title_l
    if re.search(_STAIRLIFT, title_l) or (not is_scooter_title and re.search(_STAIRLIFT, meta)):
        return "Stairlift"
    if not is_scooter_title and re.search(_RISER, both):
        return "Riser recliner chair"
    if not is_scooter_title and re.search(_BATH_LIFT, title_l):
        return "Bath lift"
    if not is_scooter_title and re.search(_BED, both):
        return "Profiling bed"
    if not is_scooter_title and re.search(_HOIST, title_l) and not re.search(_NOT_PATIENT_HOIST, title_l):
        return "Patient hoist"
    if not is_scooter_title and (re.search(_POWERCHAIR, both) or re.search(_POWERCHAIR_MODELS, title_l)):
        return "Powerchair"
    if not is_scooter_title and re.search(r"wheelchair", both):
        return "Manual wheelchair"
    if is_scooter_title or "scooter" in meta or re.search(r"pavement|mph", both):
        if re.search(_FOLDING, both):
            return "Boot / folding scooter"
        if re.search(_ROAD, both) or (top_speed_mph and top_speed_mph >= 6):
            return "Road scooter (8mph)"
        if (top_speed_mph and top_speed_mph < 6) or re.search(r"pavement|4\s?mph|class[- ]?2", both):
            return "Pavement scooter (4mph)"
        # Last, the description, but only when it names one class (some compare the two).
        desc = (text or "").lower()
        road, pavement = re.search(r"class[- ]?3|road[- ]legal", desc), re.search(r"class[- ]?2\b", desc)
        if road and not pavement:
            return "Road scooter (8mph)"
        if pavement and not road:
            return "Pavement scooter (4mph)"
        if (re.search(r"dismantl|\bfold(?:s|ing)?\b|car boot|into (?:the |a |your )?(?:car )?boot|transportable|portable", desc)
                and not re.search(r"not (?:suitable|designed|made) for (?:a |the )?(?:car )?boot|too (?:big|large|heavy) for", desc)):
            return "Boot / folding scooter"
        return "Mobility scooter (speed not stated)"
    return "Other"


# ---------------------------------------------------------------- type details

def detect_type_detail(category: str, title: str, text: str = "") -> str | None:
    """The one detail that matters most for a type, when the dealer states it:
    straight or curved for a stairlift, how many motors for a riser recliner."""
    t = (title or "").lower()
    if category == "Stairlift":
        if re.search(r"\bcurved?\b|\bcurve\b", t):
            return "Curved"
        if re.search(r"\bstraight\b|slide track|perch", t):
            return "Straight"
        if re.search(r"\boutdoor\b", t):
            return "Outdoor"
        return None
    if category == "Riser recliner chair":
        hay = t + " " + (text or "").lower()[:600]
        words = {"single": 1, "one": 1, "1": 1, "dual": 2, "twin": 2, "two": 2, "2": 2,
                 "three": 3, "3": 3, "four": 4, "4": 4, "five": 5, "5": 5}
        m = (re.search(r"\b(single|one|dual|twin|two|three|four|five|[1-5])[- ]motors?\b", hay)
             or re.search(r"\b(single|dual|twin)[- ](?:riser|rise|lift)\b", hay))
        if m:
            n = words[m.group(1)]
            return "Single motor" if n == 1 else f"{'Dual' if n == 2 else n} motor"
        if re.search(r"tilt[- ]in[- ]space", hay):
            return "Tilt in space"
        return None
    return None


# ---------------------------------------------------------------- specs

_NUM = r"(\d{1,3}(?:\.\d{1,2})?)"


def _candidates(patterns: list[tuple[str, float]], text: str, lo: float, hi: float) -> list[tuple[int, float]]:
    """All (position, value) matches. Each pattern carries a multiplier (e.g. stone -> kg)."""
    found = []
    for pat, factor in patterns:
        for m in re.finditer(pat, text, re.I):
            try:
                val = round(float(m.group(1)) * factor, 1)
            except (TypeError, ValueError):
                continue
            if lo <= val <= hi:
                found.append((m.start(), val))
    return sorted(found)


def _pick(found: list[tuple[int, float]], tolerance: float = 0.06):
    """Take the first value the dealer mentions; report any that disagree with it."""
    if not found:
        return None, []
    first = found[0][1]
    others = sorted({v for _, v in found if abs(v - first) > first * tolerance})
    return first, ([first] + others if others else [])


def extract_specs(text: str) -> dict:
    t = re.sub(r"\s*\|\s*", " | ", text or "")
    t = re.sub(r"\s+", " ", t)
    stone = 6.35
    not_other_weight = r"(?!\s?(?:total|product|scooter|chair|unit|overall)? ?weight)"

    weight_kw = (r"(?:max(?:imum)?\.? (?:user )?weight|user weight|weight capacity|max(?:imum)? load|"
                 r"riders? up to|carries up to|supporting users up to|users? up to|weight limit|max user)")
    weight_kg = _candidates([
        (weight_kw + r"(?:(?!weight)[^.]){0,40}?" + _NUM + r"\s?kg\b" + not_other_weight, 1),
        # spec strips where the label is glued to the value: "200kgMaximum user weight"
        (_NUM + r"\s?kg" + weight_kw, 1),
        (_NUM + r"\s?kg\s?/\s?\d+(?:\.\d+)?\s?(?:st\.?|stone)" + weight_kw, 1),
    ], t, 80, 320)
    weight_st = _candidates([
        (weight_kw + r"[^.\d]{0,40}?" + _NUM + r"\s?(?:st\b\.?|stone)", stone),
        (_NUM + r"\s?(?:st\.?|stone)" + weight_kw, stone),
    ], t, 80, 320)
    # when one mention gives both ("21 stone (136 kg)") keep the dealer's kg figure
    weight = sorted(weight_kg + [(p, v) for p, v in weight_st
                                 if not any(abs(p - kp) < 60 for kp, _ in weight_kg)])

    range_found = _candidates([
        (r"range[^.\d]{0,40}?" + _NUM + r"\s?-?\s?miles?", 1),
        (_NUM + r"\s?-?\s?miles?\)?\s?(?:\(\d+\s?km\)\s?)?(?:\|\s?)?(?:range|travel range|on a (?:single |full )?charge)(?!\s?option)", 1),
        (r"up to (?:around |approx\.? )?" + _NUM + r"\s?-?\s?miles?", 1),
        (r"max(?:imum)? range \| " + _NUM + r" \|", 1),            # table cell with no unit
    ], t, 3, 60)

    speed_anchored = _candidates([
        (r"(?:top|max(?:imum)?) speed[^.\d]{0,25}?" + _NUM + r"\s?(?:mph|\|)", 1),
        (_NUM + r"\s?mph\s?\|?\s?(?:top|max(?:imum)?) speed", 1),
        (_NUM + r"(?=(?:top|max(?:imum)?) speed)", 1),           # "8Top speed" spec strips
    ], t, 2, 15)
    speed_any = _candidates([(_NUM + r"\s?mph", 1)], t, 2, 15)

    heaviest = _candidates([
        (r"heaviest[^.\d]{0,50}?" + _NUM + r"\s?kg", 1),
    ], t, 3, 60)

    kg, kg_conf = _pick(weight)
    rng, rng_conf = _pick(range_found)
    if speed_anchored:
        spd, spd_conf = _pick(speed_anchored, 0.01)
    else:
        spd, spd_conf = _pick(speed_any[:1])
    hv, hv_conf = _pick(heaviest)
    conflicts = {k: v for k, v in {"max_user_weight_kg": kg_conf, "range_miles": rng_conf,
                                   "top_speed_mph": spd_conf, "heaviest_part_kg": hv_conf}.items() if v}
    return {
        "max_user_weight_kg": round(kg) if kg else None,
        "range_miles": rng,
        "top_speed_mph": spd,
        "heaviest_part_kg": hv,
        "spec_conflicts": conflicts,
    }


_PART_WARRANTY = r"frame|chassis|motor|batter|structur|gearbox|upholster|electronic|manufacturer"


def extract_warranty_months(*texts: str) -> int | None:
    """The dealer's warranty on this item, in months. A warranty on one part (a "5-year frame
    warranty") is the maker's, not the dealer's, so it doesn't count. An item the dealer says
    comes without a warranty gets none."""
    for text in texts:
        t = re.sub(r"\s+", " ", text or "")
        if re.search(r"\b(?:without|no)\s+(?:a\s+|any\s+)?warranty\b|\bsold as seen\b", t, re.I):
            return None
        for pat in (r"(\d{1,2})[\s-]*months?\b[^.|]{0,40}?warrant", r"warrant[^.|]{0,40}?(\d{1,2})[\s-]*months?"):
            for m in re.finditer(pat, t, re.I):
                if 1 <= int(m.group(1)) <= 36 and not re.search(_PART_WARRANTY, m.group(0), re.I):
                    return int(m.group(1))
        for y in re.finditer(r"(\d)[\s-]*years?\b[^.|]{0,30}?warrant", t, re.I):
            if 1 <= int(y.group(1)) <= 5 and not re.search(_PART_WARRANTY, y.group(0), re.I):
                return int(y.group(1)) * 12
    return None


# ---------------------------------------------------------------- titles

_TITLE_NOISE = [
    r"\bex[- ]?(?:demo|display|showroom) model\b", r"\bpre[- ]?used\b", r"\bapproved used\b", r"\bused approved\b",
    r"\bpre[- ]?owned\b", r"\bpreowned\b", r"\bpre[- ]?loved\b", r"\bsecond[- ]hand\b", r"\bused\b",
    r"\bex[- ]?demo\b", r"\bex[- ]?display\b", r"\bex[- ]?showroom\b", r"- ?showroom model",
    r"\breconditioned\b", r"\brefurbished\b", r"\bdemo\s*$",
    r"\b\d{1,2} months? (back to shop |on-?site )?warranty\b", r"\bnew batts?\b", r"\bnew batteries\b",
    r"\bnew (?:\d+\s?ah |lithium )batter(?:y|ies)\b", r"\bclearance\b",
    r"[-–|]\s*new\s*$",          # "... Recliner Chair - NEW": the condition label says it
]


def clean_title(title: str) -> str:
    t = title or ""
    for pat in _TITLE_NOISE:
        t = re.sub(pat, " ", t, flags=re.I)
    # "Pride GoGo | Refurbished Mobility Scooter Stockport" -> "Pride GoGo – Stockport"
    t = re.sub(r"\|\s*(?:mobility\s+)?(?:scooter|powerchair|electric wheelchair)\b", "|", t, flags=re.I)
    t = re.sub(r"\s*\|\s*", " – ", t)
    t = re.sub(r"\s*[-–,]\s*(?=[-–,]|$)", " ", t)
    t = re.sub(r"^\s*[-–,:&]+\s*", "", t)
    t = re.sub(r"\s+", " ", t).strip(" -–,:")
    return t or (title or "").strip()
