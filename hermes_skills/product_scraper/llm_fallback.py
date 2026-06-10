"""LLM-fallback для нестандартной вёрстки.

Если ни JSON-LD, ни CSS-селекторы, ни regex не дали названия товара, мы
передаём очищенный текст страницы в LLM и просим извлечь поля строго в JSON.
Сам вызов модели НЕ зашит в модуль — агент Hermes инжектит свою реализацию
через протокол :class:`LLMExtractor`. Так скилл остаётся автономным и не тянет
зависимость от конкретного провайдера (OpenAI/Anthropic/локальная модель).
"""

from __future__ import annotations

import json
import logging
import re
from collections.abc import Awaitable, Callable
from typing import Any, Protocol, runtime_checkable

logger = logging.getLogger("hermes.product_scraper.llm")

# Промпт для извлечения. Просим вернуть только JSON указанной формы.
LLM_EXTRACTION_PROMPT = """\
Ты — парсер карточек товаров. Из текста страницы извлеки данные о товаре и верни
СТРОГО валидный JSON без пояснений и markdown по схеме:
{{
  "title": str,                // обязательно
  "price": number | null,      // только число, без валюты
  "currency": str,             // ISO-код, по умолчанию "RUB"
  "attributes": {{"ключ": "значение"}},
  "description": str | null,
  "rating": number | null,     // 0..5
  "reviews_count": int,        // 0 если нет
  "is_available": bool
}}
Если поля нет — ставь null (или 0 для reviews_count, true для is_available).

URL страницы: {url}

ТЕКСТ СТРАНИЦЫ:
{page_text}
"""


@runtime_checkable
class LLMExtractor(Protocol):
    """Контракт LLM-функции. Принимает промпт, возвращает текст ответа модели."""

    async def __call__(self, prompt: str) -> str:  # pragma: no cover - протокол
        ...


# Допускаем и простую async-функцию, и объект, реализующий протокол.
LLMCallable = Callable[[str], Awaitable[str]]


def _strip_code_fences(text: str) -> str:
    """Срезаем ```json ...``` обёртки, которые любят добавлять модели."""
    text = text.strip()
    if text.startswith("```"):
        text = re.sub(r"^```[a-zA-Z]*\n?", "", text)
        text = re.sub(r"\n?```$", "", text)
    return text.strip()


def _coerce_json(raw: str) -> dict | None:
    """Пытаемся распарсить JSON из ответа модели, в т.ч. если есть лишний текст."""
    candidate = _strip_code_fences(raw)
    try:
        return json.loads(candidate)
    except json.JSONDecodeError:
        # Достаём первый сбалансированный {...}-блок.
        match = re.search(r"\{.*\}", candidate, re.DOTALL)
        if match:
            try:
                return json.loads(match.group(0))
            except json.JSONDecodeError:
                return None
    return None


async def extract_with_llm(
    page_text: str,
    source_url: str,
    llm: LLMCallable | None,
) -> dict[str, Any] | None:
    """Извлекаем поля товара через LLM. Возвращает dict под ProductData или None.

    Если ``llm`` не передан (агент не сконфигурировал fallback), возвращаем
    ``None`` — это штатная ситуация, скилл просто отдаст ошибку извлечения.
    """
    if llm is None:
        logger.debug("LLM-fallback не сконфигурирован — пропускаем")
        return None
    prompt = LLM_EXTRACTION_PROMPT.format(url=source_url, page_text=page_text)
    try:
        raw_response = await llm(prompt)
    except Exception:  # noqa: BLE001 — изолируем сбои внешней модели
        logger.warning("LLM-fallback завершился ошибкой", exc_info=True)
        return None

    parsed = _coerce_json(raw_response or "")
    if not parsed or not parsed.get("title"):
        return None
    parsed["source_url"] = source_url
    return parsed


__all__ = ["LLMExtractor", "LLMCallable", "extract_with_llm", "LLM_EXTRACTION_PROMPT"]
