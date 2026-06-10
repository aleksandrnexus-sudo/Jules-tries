"""Тесты Pydantic-схемы, разбора запроса и LLM-fallback."""

from __future__ import annotations

import pytest
from hermes_skills.product_scraper.llm_fallback import extract_with_llm
from hermes_skills.product_scraper.schemas import ProductData, ScrapeStatus, SkillOutput
from hermes_skills.product_scraper.scraper import _parse_search_query


def test_rating_clamped_from_ten_scale():
    product = ProductData(title="X", rating=9.0, source_url="https://example.com/p")
    assert product.rating == 4.5  # 10-балльная шкала нормализована к 5


def test_rating_invalid_dropped():
    product = ProductData(title="X", rating=-1, source_url="https://example.com/p")
    assert product.rating is None


def test_reviews_count_non_negative():
    product = ProductData(title="X", reviews_count=-5, source_url="https://example.com/p")
    assert product.reviews_count == 0


def test_skilloutput_blocked_shape():
    out = SkillOutput.blocked("HTTP 403", "https://www.ozon.ru/product/1/")
    assert out.success is False
    assert out.status is ScrapeStatus.BLOCKED
    dumped = out.model_dump()
    assert dumped["status"] == "blocked"
    assert dumped["reason"] == "HTTP 403"
    assert dumped["url"] == "https://www.ozon.ru/product/1/"


def test_skilloutput_ok():
    product = ProductData(title="X", source_url="https://example.com/p")
    out = SkillOutput.ok(product, method="json-ld")
    assert out.success is True
    assert out.status is ScrapeStatus.SUCCESS
    assert out.extraction_method == "json-ld"


@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("Ozon: iphone 15", ("ozon.ru", "iphone 15")),
        ("WB: кроссовки", ("wildberries.ru", "кроссовки")),
        ("Yandex Market: телевизор", ("market.yandex.ru", "телевизор")),
        ("просто текст без префикса", None),
        ("UnknownShop: что-то", None),
    ],
)
def test_parse_search_query(text, expected):
    assert _parse_search_query(text) == expected


@pytest.mark.asyncio
async def test_llm_fallback_parses_json():
    async def fake_llm(prompt: str) -> str:
        assert "ТЕКСТ СТРАНИЦЫ" in prompt
        return '```json\n{"title": "Товар из LLM", "price": 100, "reviews_count": 3}\n```'

    data = await extract_with_llm("page text", "https://example.com/p", fake_llm)
    assert data is not None
    product = ProductData(**data)
    assert product.title == "Товар из LLM"
    assert product.price == 100.0
    assert product.reviews_count == 3


@pytest.mark.asyncio
async def test_llm_fallback_absent_returns_none():
    assert await extract_with_llm("text", "https://example.com/p", None) is None
