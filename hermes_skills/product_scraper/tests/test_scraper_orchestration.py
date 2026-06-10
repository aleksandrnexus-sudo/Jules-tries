"""Интеграционные тесты оркестратора с фейковым Playwright Page (без сети).

Подменяем ``StealthBrowser`` на заглушку, которая отдаёт заранее заданный HTML
по каждому URL. Так проверяем Сценарий А/Б, детект блокировки и валидацию,
не поднимая реальный браузер.
"""

from __future__ import annotations

import pytest
from hermes_skills.product_scraper import scraper as scraper_module
from hermes_skills.product_scraper.config import ScraperConfig
from hermes_skills.product_scraper.scraper import ProductScraperSkill, prepare_session

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
        self.visited: list[str] = []

    async def goto(self, url: str, **_: object) -> _FakeResponse:
        self.visited.append(url)
        # Незарегистрированные URL (напр. homepage-прогрев) отдают пустую
        # страницу со статусом 200 — как реальный браузер, который просто
        # открыл главную ради antibot-cookies.
        html, status = self._pages.get(url, ("<html><body></body></html>", 200))
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
    # Прогрев antibot-cookies: homepage площадки открыт ДО страницы поиска.
    assert page.visited[0] == "https://www.wildberries.ru/"
    assert page.visited.index("https://www.wildberries.ru/") < page.visited.index(search_url)


@pytest.mark.asyncio
async def test_warmup_disabled_skips_homepage(monkeypatch):
    url = "https://www.ozon.ru/product/test-1/"
    page = _FakePage({url: (PRODUCT_HTML, 200)})
    _patch_browser(monkeypatch, page)

    result = await ProductScraperSkill(ScraperConfig(warmup_origin=False)).scrape(url)
    assert result.success is True
    # Без прогрева заходим только на целевой URL, homepage не трогаем.
    assert page.visited == [url]


# Без ретраев — чтобы тесты блокировки не ждали backoff.
_NO_RETRY = ScraperConfig(max_retries=0)


@pytest.mark.asyncio
async def test_blocked_on_captcha(monkeypatch):
    url = "https://www.ozon.ru/product/blocked/"
    page = _FakePage({url: (CAPTCHA_HTML, 200)})
    _patch_browser(monkeypatch, page)

    result = await ProductScraperSkill(_NO_RETRY).scrape(url)
    assert result.success is False
    assert result.status.value == "blocked"
    assert result.url == url


@pytest.mark.asyncio
async def test_blocked_on_http_403(monkeypatch):
    url = "https://www.ozon.ru/product/forbidden/"
    page = _FakePage({url: ("<html><h1>ok</h1></html>", 403)})
    _patch_browser(monkeypatch, page)

    result = await ProductScraperSkill(_NO_RETRY).scrape(url)
    assert result.status.value == "blocked"
    assert "403" in (result.reason or "")


@pytest.mark.asyncio
async def test_retries_then_blocked(monkeypatch):
    """При постоянной блокировке делаем max_retries+1 попыток, backoff=0."""
    url = "https://www.ozon.ru/product/blocked/"
    page = _FakePage({url: (CAPTCHA_HTML, 200)})
    _patch_browser(monkeypatch, page)

    calls = {"n": 0}
    skill = ProductScraperSkill(ScraperConfig(max_retries=2, retry_backoff_s=0))
    orig = skill._scrape_once

    async def counting(q):
        calls["n"] += 1
        return await orig(q)

    monkeypatch.setattr(skill, "_scrape_once", counting)
    result = await skill.scrape(url)
    assert result.status.value == "blocked"
    assert calls["n"] == 3  # 1 + 2 ретрая


@pytest.mark.asyncio
async def test_input_error_not_retried(monkeypatch):
    """Ошибка формата входа не должна ретраиться (это не сеть)."""
    skill = ProductScraperSkill(ScraperConfig(max_retries=5, retry_backoff_s=10))
    called = {"n": 0}

    async def boom(q):
        called["n"] += 1
        raise AssertionError("не должно вызываться")

    monkeypatch.setattr(skill, "_scrape_once", boom)
    result = await skill.scrape("просто текст без префикса")
    assert result.status.value == "error"
    assert called["n"] == 0


@pytest.mark.asyncio
async def test_unknown_marketplace_returns_error(monkeypatch):
    result = await ProductScraperSkill().scrape("просто текст")
    assert result.success is False
    assert result.status.value == "error"


@pytest.mark.asyncio
async def test_prepare_session_requires_user_data_dir():
    """Без user_data_dir прогрев сессии бессмыслен — должен падать явно."""
    with pytest.raises(ValueError, match="user_data_dir"):
        await prepare_session("https://www.ozon.ru/", ScraperConfig())


@pytest.mark.asyncio
async def test_prepare_session_returns_true_when_block_cleared(monkeypatch, tmp_path):
    """Капча на первом опросе, чисто — на втором: сессия считается прогретой."""
    url = "https://www.ozon.ru/"
    page = _FakePage({url: (CAPTCHA_HTML, 200)})
    _patch_browser(monkeypatch, page)

    # На втором обращении content() уже без маркеров блокировки.
    contents = iter([CAPTCHA_HTML, PRODUCT_HTML])

    async def content_seq() -> str:
        return next(contents, PRODUCT_HTML)

    page.content = content_seq  # type: ignore[method-assign]

    cfg = ScraperConfig(user_data_dir=str(tmp_path), headless=False)
    ok = await prepare_session(url, cfg, wait_timeout_s=5, poll_s=0)
    assert ok is True
