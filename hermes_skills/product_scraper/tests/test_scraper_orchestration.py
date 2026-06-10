"""Интеграционные тесты оркестратора с фейковым Playwright Page (без сети).

Подменяем ``StealthBrowser`` на заглушку, которая отдаёт заранее заданный HTML
по каждому URL. Так проверяем Сценарий А/Б, детект блокировки и валидацию,
не поднимая реальный браузер.
"""

from __future__ import annotations

import pytest
from hermes_skills.product_scraper import scraper as scraper_module
from hermes_skills.product_scraper.scraper import ProductScraperSkill

PRODUCT_HTML = """
<html><head>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Product","name":"Тестовый товар",
 "offers":{"@type":"Offer","price":"1990","priceCurrency":"RUB",
           "availability":"https://schema.org/InStock"},
 "aggregateRating":{"@type":"AggregateRating","ratingValue":"4.6","reviewCount":"88"}}
</script></head><body><h1>Тестовый товар</h1></body></html>
"""

SEARCH_HTML = """
<html><body>
  <a class="product-card__link" href="/catalog/12345/detail.aspx">Карточка</a>
</body></html>
"""

CAPTCHA_HTML = "<html><body>Подтвердите, что вы не робот</body></html>"


class _FakeResponse:
    def __init__(self, status: int) -> None:
        self.status = status


class _FakePage:
    """Минимальная заглушка Playwright Page для оркестратора."""

    def __init__(self, pages: dict[str, tuple[str, int]]) -> None:
        self._pages = pages  # url -> (html, http_status)
        self._current = ""

    async def goto(self, url: str, **_: object) -> _FakeResponse:
        html, status = self._pages[url]
        self._current = html
        return _FakeResponse(status)

    async def wait_for_load_state(self, *_: object, **__: object) -> None:
        return None

    async def wait_for_selector(self, *_: object, **__: object) -> None:
        return None

    async def content(self) -> str:
        return self._current

    async def evaluate(self, _script: str, _selector: str) -> str:
        # Возвращаем href первой карточки (Сценарий Б).
        return "/catalog/12345/detail.aspx"


class _FakeBrowser:
    """Заглушка StealthBrowser как async context manager."""

    def __init__(self, page: _FakePage) -> None:
        self._page = page

    async def __aenter__(self) -> _FakeBrowser:
        return self

    async def __aexit__(self, *_: object) -> None:
        return None

    async def new_page(self) -> _FakePage:
        return self._page


def _patch_browser(monkeypatch: pytest.MonkeyPatch, page: _FakePage) -> None:
    monkeypatch.setattr(scraper_module, "StealthBrowser", lambda _config: _FakeBrowser(page))


@pytest.mark.asyncio
async def test_scenario_direct_url_success(monkeypatch):
    url = "https://www.ozon.ru/product/test-1/"
    page = _FakePage({url: (PRODUCT_HTML, 200)})
    _patch_browser(monkeypatch, page)

    result = await ProductScraperSkill().scrape(url)
    assert result.success is True
    assert result.data is not None
    assert result.data.title == "Тестовый товар"
    assert result.data.price == 1990.0
    assert result.data.rating == 4.6
    assert result.data.reviews_count == 88
    assert result.extraction_method == "json-ld"


@pytest.mark.asyncio
async def test_scenario_b_search_to_card(monkeypatch):
    search_url = "https://www.wildberries.ru/catalog/0/search.aspx?search=%D1%82%D0%B5%D1%81%D1%82"
    product_url = "https://www.wildberries.ru/catalog/12345/detail.aspx"
    page = _FakePage({search_url: (SEARCH_HTML, 200), product_url: (PRODUCT_HTML, 200)})
    _patch_browser(monkeypatch, page)

    result = await ProductScraperSkill().scrape("WB: тест")
    assert result.success is True
    assert result.data is not None
    assert result.data.title == "Тестовый товар"
    assert result.url == product_url


@pytest.mark.asyncio
async def test_blocked_on_captcha(monkeypatch):
    url = "https://www.ozon.ru/product/blocked/"
    page = _FakePage({url: (CAPTCHA_HTML, 200)})
    _patch_browser(monkeypatch, page)

    result = await ProductScraperSkill().scrape(url)
    assert result.success is False
    assert result.status.value == "blocked"
    assert result.url == url


@pytest.mark.asyncio
async def test_blocked_on_http_403(monkeypatch):
    url = "https://www.ozon.ru/product/forbidden/"
    page = _FakePage({url: ("<html><h1>ok</h1></html>", 403)})
    _patch_browser(monkeypatch, page)

    result = await ProductScraperSkill().scrape(url)
    assert result.status.value == "blocked"
    assert "403" in (result.reason or "")


@pytest.mark.asyncio
async def test_unknown_marketplace_returns_error(monkeypatch):
    result = await ProductScraperSkill().scrape("просто текст")
    assert result.success is False
    assert result.status.value == "error"
