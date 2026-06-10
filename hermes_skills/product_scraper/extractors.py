"""Извлечение данных из HTML: JSON-LD -> CSS-селекторы -> regex/текстовые маркеры.

Порядок неслучаен. Согласно best practice, наиболее устойчивый источник —
``<script type="application/ld+json">`` со schema.org Product: там лежат цена,
наличие, рейтинг и отзывы в стандартизированном виде, не зависящем от вёрстки.
CSS-селекторы вторичны (классы меняются), а regex/маркеры — последний рубеж
перед LLM-fallback.
"""

from __future__ import annotations

import json
import logging
import re
from typing import Any

from bs4 import BeautifulSoup

from .config import CAPTCHA_MARKERS, DomainSelectors

logger = logging.getLogger("hermes.product_scraper.extractors")

# Маппинг schema.org availability -> bool наличия.
_IN_STOCK_TOKENS = {"instock", "preorder", "limitedavailability", "onlineonly", "instoreonly"}


# --- Низкоуровневые парсеры значений ----------------------------------------


def parse_price(raw: Any) -> float | None:
    """Достаём число из «грязной» цены: ``"1 299,00 ₽"`` -> ``1299.0``.

    Логика: убираем валюту/пробелы/неразрывные пробелы; решаем, что является
    десятичным разделителем (последняя ``,`` или ``.`` с 1-2 цифрами после).
    """
    if raw is None:
        return None
    if isinstance(raw, (int, float)):
        return float(raw)
    text = str(raw)
    # Оставляем только цифры и разделители.
    text = text.replace("\xa0", " ").strip()
    cleaned = re.sub(r"[^\d.,]", "", text)
    if not cleaned:
        return None
    # Если есть и точка, и запятая — последний из них считаем десятичным.
    if "," in cleaned and "." in cleaned:
        if cleaned.rfind(",") > cleaned.rfind("."):
            cleaned = cleaned.replace(".", "").replace(",", ".")
        else:
            cleaned = cleaned.replace(",", "")
    elif "," in cleaned:
        # Запятая как десятичный разделитель только если после неё 1-2 цифры.
        if re.search(r",\d{1,2}$", cleaned):
            cleaned = cleaned.replace(",", ".")
        else:
            cleaned = cleaned.replace(",", "")
    try:
        return float(cleaned)
    except ValueError:
        return None


def parse_int(raw: Any) -> int | None:
    """Достаём целое число из текста: ``"1 234 отзыва"`` -> ``1234``."""
    if raw is None:
        return None
    if isinstance(raw, (int, float)):
        return int(raw)
    digits = re.sub(r"[^\d]", "", str(raw))
    return int(digits) if digits else None


def parse_rating(raw: Any) -> float | None:
    """Достаём рейтинг: ``"4,8 из 5"`` -> ``4.8`` (валидация диапазона — в схеме)."""
    if raw is None:
        return None
    if isinstance(raw, (int, float)):
        return float(raw)
    match = re.search(r"(\d+[.,]?\d*)", str(raw).replace("\xa0", " "))
    if not match:
        return None
    try:
        return float(match.group(1).replace(",", "."))
    except ValueError:
        return None


def detect_block(html: str, http_status: int | None = None) -> str | None:
    """Определяем капчу/блокировку. Возвращает причину или ``None``.

    Проверяем как HTTP-код, так и текстовые маркеры в первых ~20k символах
    (капча-страницы обычно короткие, проверять весь DOM не нужно).
    """
    from .config import BLOCKED_HTTP_STATUSES

    if http_status in BLOCKED_HTTP_STATUSES:
        return f"HTTP {http_status}"
    sample = html[:20_000].lower()
    for marker in CAPTCHA_MARKERS:
        if marker in sample:
            return f"Обнаружен маркер блокировки: '{marker}'"
    return None


# --- JSON-LD (приоритетный источник) ----------------------------------------


def _iter_jsonld_objects(soup: BeautifulSoup) -> list[dict]:
    """Собираем все JSON-LD объекты со страницы, разворачивая @graph и массивы."""
    objects: list[dict] = []
    for tag in soup.find_all("script", attrs={"type": "application/ld+json"}):
        raw = tag.string or tag.get_text() or ""
        if not raw.strip():
            continue
        try:
            parsed = json.loads(raw)
        except json.JSONDecodeError:
            # Иногда внутри несколько объектов или мусорные хвосты —
            # пытаемся вытащить хотя бы первый валидный объект.
            try:
                parsed = json.loads(raw[: raw.rindex("}") + 1])
            except (ValueError, json.JSONDecodeError):
                continue
        if isinstance(parsed, list):
            objects.extend(o for o in parsed if isinstance(o, dict))
        elif isinstance(parsed, dict):
            if "@graph" in parsed and isinstance(parsed["@graph"], list):
                objects.extend(o for o in parsed["@graph"] if isinstance(o, dict))
            else:
                objects.append(parsed)
    return objects


def _is_product_node(node: dict) -> bool:
    node_type = node.get("@type")
    types = node_type if isinstance(node_type, list) else [node_type]
    return any(isinstance(t, str) and t.lower() == "product" for t in types)


def extract_from_jsonld(html: str, source_url: str) -> dict[str, Any] | None:
    """Извлекаем поля Product из JSON-LD. Возвращает dict под ProductData или None."""
    soup = BeautifulSoup(html, "html.parser")
    product_node = next((n for n in _iter_jsonld_objects(soup) if _is_product_node(n)), None)
    if product_node is None:
        return None

    result: dict[str, Any] = {"source_url": source_url}
    if name := product_node.get("name"):
        result["title"] = str(name).strip()
    if description := product_node.get("description"):
        result["description"] = str(description).strip()

    # offers может быть объектом или списком офферов.
    offers = product_node.get("offers")
    if isinstance(offers, list):
        offers = offers[0] if offers else None
    if isinstance(offers, dict):
        if (price := parse_price(offers.get("price"))) is not None:
            result["price"] = price
        if currency := offers.get("priceCurrency"):
            result["currency"] = str(currency)
        availability = str(offers.get("availability", "")).lower()
        if availability:
            result["is_available"] = any(token in availability for token in _IN_STOCK_TOKENS)

    # aggregateRating: рейтинг и количество отзывов.
    rating_node = product_node.get("aggregateRating")
    if isinstance(rating_node, dict):
        if (rating := parse_rating(rating_node.get("ratingValue"))) is not None:
            result["rating"] = rating
        count = parse_int(rating_node.get("reviewCount") or rating_node.get("ratingCount"))
        if count is not None:
            result["reviews_count"] = count

    # additionalProperty -> attributes.
    attributes: dict[str, str] = {}
    for prop in product_node.get("additionalProperty", []) or []:
        if isinstance(prop, dict) and prop.get("name") and prop.get("value") is not None:
            attributes[str(prop["name"]).strip()] = str(prop["value"]).strip()
    if brand := product_node.get("brand"):
        brand_name = brand.get("name") if isinstance(brand, dict) else brand
        if brand_name:
            attributes.setdefault("Бренд", str(brand_name).strip())
    if attributes:
        result["attributes"] = attributes

    # Без названия карточка бессмысленна — пусть отработает следующий источник.
    return result if result.get("title") else None


# --- CSS-селекторы (вторичный источник) -------------------------------------


def _text_or_none(soup: BeautifulSoup, selector: str | None) -> str | None:
    if not selector:
        return None
    el = soup.select_one(selector)
    if el is None:
        return None
    text = el.get_text(separator=" ", strip=True)
    return text or None


def extract_from_selectors(
    html: str, source_url: str, selectors: DomainSelectors
) -> dict[str, Any] | None:
    """Извлекаем поля по CSS-селекторам домена. Возвращает dict или None."""
    soup = BeautifulSoup(html, "html.parser")
    title = _text_or_none(soup, selectors.title)
    if not title:
        return None  # нет заголовка — селекторы, вероятно, устарели

    result: dict[str, Any] = {
        "source_url": source_url,
        "title": title,
        "currency": selectors.currency,
    }
    if (price := parse_price(_text_or_none(soup, selectors.price))) is not None:
        result["price"] = price
    if description := _text_or_none(soup, selectors.description):
        result["description"] = description
    if (rating := parse_rating(_text_or_none(soup, selectors.rating))) is not None:
        result["rating"] = rating
    if (reviews := parse_int(_text_or_none(soup, selectors.reviews_count))) is not None:
        result["reviews_count"] = reviews
    if selectors.availability:
        avail_text = (_text_or_none(soup, selectors.availability) or "").lower()
        out_of_stock_markers = ("нет в наличии", "распродан", "unavailable", "out of stock")
        result["is_available"] = not any(m in avail_text for m in out_of_stock_markers)

    # Таблица характеристик ключ-значение.
    if selectors.attributes_rows and selectors.attribute_key and selectors.attribute_value:
        attributes: dict[str, str] = {}
        for row in soup.select(selectors.attributes_rows):
            key_el = row.select_one(selectors.attribute_key)
            val_el = row.select_one(selectors.attribute_value)
            if key_el and val_el:
                key = key_el.get_text(strip=True)
                val = val_el.get_text(separator=" ", strip=True)
                if key and val:
                    attributes[key] = val
        if attributes:
            result["attributes"] = attributes
    return result


# --- Regex / текстовые маркеры (последний рубеж перед LLM) -------------------

_META_PRICE_RE = re.compile(
    r'<meta[^>]+(?:itemprop|property)=["\'](?:product:price:amount|og:price:amount|price)["\']'
    r'[^>]+content=["\']([^"\']+)["\']',
    re.IGNORECASE,
)
_META_TITLE_RE = re.compile(
    r'<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']',
    re.IGNORECASE,
)
_TITLE_TAG_RE = re.compile(r"<title[^>]*>(.*?)</title>", re.IGNORECASE | re.DOTALL)


def extract_from_regex(html: str, source_url: str) -> dict[str, Any] | None:
    """Грубое извлечение из meta-тегов/``<title>``, когда всё остальное молчит."""
    title_match = _META_TITLE_RE.search(html) or _TITLE_TAG_RE.search(html)
    if not title_match:
        return None
    title = re.sub(r"\s+", " ", title_match.group(1)).strip()
    if not title:
        return None
    result: dict[str, Any] = {"source_url": source_url, "title": title}
    if price_match := _META_PRICE_RE.search(html):
        if (price := parse_price(price_match.group(1))) is not None:
            result["price"] = price
    return result


def clean_text_for_llm(html: str, max_chars: int = 12_000) -> str:
    """Готовим компактный текст для LLM-fallback.

    Удаляем ``script``/``style``/``svg`` и схлопываем пробелы, чтобы влезть в
    контекст модели и не платить за разметку. Обрезаем до ``max_chars``.
    """
    soup = BeautifulSoup(html, "html.parser")
    for tag in soup(["script", "style", "noscript", "svg", "header", "footer", "nav"]):
        tag.decompose()
    text = soup.get_text(separator="\n")
    text = re.sub(r"\n{2,}", "\n", text)
    text = re.sub(r"[ \t]{2,}", " ", text).strip()
    return text[:max_chars]


__all__ = [
    "parse_price",
    "parse_int",
    "parse_rating",
    "detect_block",
    "extract_from_jsonld",
    "extract_from_selectors",
    "extract_from_regex",
    "clean_text_for_llm",
]
