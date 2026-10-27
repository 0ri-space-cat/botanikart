"""Conservative storefront groups. Match product titles, never incidental description words."""
import re
import unicodedata

GROUPS = {"florarium", "composition", "potted", "plant", "material"}


def normalize(value):
    value = unicodedata.normalize("NFKC", str(value or "")).lower().replace("ё", "е")
    return re.sub(r"\s+", " ", value).strip()


def classify(product, override=None):
    if override:
        if override not in GROUPS:
            raise ValueError(f"Unknown catalog group: {override}")
        return override
    title = normalize(product.get("title") or product.get("name"))
    # An empty container or assembly kit is a material, even if named "for a florarium".
    if re.search(r"^(?:набор\s+(?:материал|для\s+(?:сборки|создания))|(?:пуст(?:ой|ая|ое|ые|ого|ую)\s+)?(?:форма|емкость|сосуд)\b|пуст(?:ой|ая|ое|ые|ого|ую)\s+флорариум)", title):
        return "material"
    if re.search(r"^(?:грунт\w*|субстрат\w*|дренаж\w*|керамзит\w*|перлит\w*|вермикулит\w*|коряг\w*|фигурк\w*|декор\w*|кора|песок|камни|гравий|пинцет\w*)\b", title):
        return "material"
    if re.search(r"\bмох\s+стабилизирован\w*|\bстабилизирован\w*\s+мох", title):
        return "material"
    if re.search(r"\b(?:флорариум\w*|террариум\w*|палюдариум\w*)\b", title):
        return "florarium"
    if re.search(r"\b(?:композици\w*|мини[ -]?сад\w*)\b", title):
        return "composition"
    if re.search(r"\b(?:черен\w*|деленк\w*|срез\w*|отвод\w*|без горшка|тех\.?\s*горш\w*|техническ\w*\s+горш\w*|транспортировочн\w*\s+горш\w*)\b", title):
        return "plant"
    if re.search(r"\b(?:кашпо\w*|горш\w*)\b", title):
        # Empty pots and a plant in a pot must not share the same group.
        if re.search(r"^(?:кашпо|горшок|горшки)\b", title) and not re.search(r"\b(?:с\s+(?:жив\w*\s+)?|растени\w*)", title):
            return "material"
        return "potted"
    legacy = product.get("category")
    if legacy in ("florarium", "composition", "material"):
        return legacy
    source_category = normalize(product.get("sourceCategory"))
    if re.search(r"грунт|субстрат|расходн|материал|дренаж|декор", source_category):
        return "material"
    return "plant"


def enrich(product, overrides=None):
    entry = dict(product)
    entry["catalogGroup"] = classify(entry, (overrides or {}).get(str(entry["id"])))
    # Botanical tags are independent of merchandising groups.
    title = normalize(entry.get("title"))
    tags = []
    if re.search(r"суккулент|кактус|эхевери|алоэ", title):
        tags.append("succulent")
    if "фикус" in title:
        tags.append("ficus")
    entry["tags"] = tags
    return entry
