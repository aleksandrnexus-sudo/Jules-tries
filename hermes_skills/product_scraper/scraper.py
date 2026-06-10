"""Оркестратор скилла: единая точка входа для агента Hermes.

Реализует адаптивный пайплайн:

* **Сценарий А** — на вход прямой URL карточки: открываем, извлекаем.
* **Сценарий Б** — на вход ``"Ozon: запрос"`` (или ``query`` + ``marketplace``):
  идём во внутренний поиск площадки, берём первую карточку, парсим её.

Каскад извлечения для любой карточки: JSON-LD → CSS-селекторы → regex/meta →
LLM-fallback. На каждом шаге проверяем капчу/блокировку и при срабатывании
возвращаем ``status=blocked``, чтобы Hermes сменил прокси/сессию.
"""

from __future__ import annotations

import asyncio
import logging
from urllib.parse import quote_plus, urljoin, urlparse

from playwright.async_api import Error as PlaywrightError
from playwright.async_api import Page
from playwright.async_api import TimeoutError as PlaywrightTimeoutError
from pydantic import ValidationError

from .browser import StealthBrowser
from .config import ScraperConfig, normalize_host, selectors_for_host
from .extractors import (
    clean_text_for_llm,
    detect_block,
    extract_from_jsonld,
    extract_from_regex,
    extract_from_selectors,
)
from .llm_fallback import LLMCallable, extract_with_llm
from .schemas import ProductData, SkillOutput

logger = logging.getLogger("hermes.product_scraper")

# Алиасы маркетплейсов для разбора префикса "Ozon: ..." (Сценарий Б).
MARKETPLACE_ALIASES = {
    "ozon": "ozon.ru",
    "wb": "wildberries.ru",
    "wildberries": "wildberries.ru",
    "вб": "wildberries.ru",
    "озон": "ozon.ru",
    "ym": "market.yandex.ru",
    "yandex": "market.yandex.ru",
    "yandex market": "market.yandex.ru",
    "яндекс маркет": "market.yandex.ru",
    "amazon": "amazon.com",
}


def _looks_like_url(text: str) -> bool:
    return text.strip().lower().startswith(("http://", "https://"))


def _parse_search_query(text: str) -> tuple[str, str] | None:
    """Разбираем ``"Ozon: iphone 15"`` -> (хост площадки, поисковый запрос).

    Возвращает ``None``, если префикс маркетплейса не распознан.
    """
    if ":" not in text:
        return None
    prefix, _, query = text.partition(":")
    host = MARKETPLACE_ALIASES.get(prefix.strip().lower())
    query = query.strip()
    if host and query:
        return host, query
    return None


class ProductScraperSkill:
    """Автономный скилл извлечения данных о товаре для агента Hermes.

    Парам. ``llm`` — опциональная async-функция LLM-fallback'а (инжектируется
    агентом). ``config`` управляет stealth-сессией и прокси.
    """

    def __init__(
        self,
        config: ScraperConfig | None = None,
        llm: LLMCallable | None = None,
    ) -> None:
        self._config = config or ScraperConfig()
        self._llm = llm

    # --- Публичный интерфейс -------------------------------------------------

    async def scrape(self, query_or_url: str) -> SkillOutput:
        """Главная точка входа с ретраями.

        При временной блокировке/таймауте делаем до ``config.max_retries``
        повторов с экспоненциальным backoff (новая сессия = новый UA). Это
        помогает с «плавающими» антибот-проверками без смены прокси. Если все
        попытки заблокированы — отдаём последний ``blocked``, чтобы агент решил
        о смене сессии/IP.
        """
        query_or_url = (query_or_url or "").strip()
        if not query_or_url:
            return SkillOutput.failed("Пустой запрос")

        # Валидируем вход ОДИН раз — ошибки формата не ретраим (это не сеть).
        if not _looks_like_url(query_or_url) and _parse_search_query(query_or_url) is None:
            return SkillOutput.failed(
                "Не распознан маркетплейс. Используйте формат "
                "'Ozon: запрос' / 'WB: запрос' / 'Yandex Market: запрос' "
                "или передайте прямой URL карточки."
            )

        last: SkillOutput | None = None
        attempts = max(1, self._config.max_retries + 1)
        for attempt in range(attempts):
            result = await self._scrape_once(query_or_url)
            # Ретраим только сетевые проблемы: блокировку и ошибки навигации.
            if result.success or result.status.value not in {"blocked", "error"}:
                return result
            last = result
            if attempt < attempts - 1:
                delay = self._config.retry_backoff_s * (2**attempt)
                logger.info(
                    "Попытка %d/%d дала status=%s; повтор через %.1fs",
                    attempt + 1,
                    attempts,
                    result.status.value,
                    delay,
                )
                await asyncio.sleep(delay)
        return last or SkillOutput.failed("Не удалось выполнить запрос", url=query_or_url)

    async def _scrape_once(self, query_or_url: str) -> SkillOutput:
        """Одна попытка: поднять сессию, выбрать сценарий, извлечь."""
        try:
            async with StealthBrowser(self._config) as browser:
                page = await browser.new_page()
                if _looks_like_url(query_or_url):
                    return await self._scenario_direct_url(page, query_or_url)
                parsed = _parse_search_query(query_or_url)
                if parsed is None:
                    return SkillOutput.failed(
                        "Не распознан маркетплейс. Используйте формат "
                        "'Ozon: запрос' / 'WB: запрос' / 'Yandex Market: запрос' "
                        "или передайте прямой URL карточки."
                    )
                host, query = parsed
                return await self._scenario_search(page, host, query)
        except PlaywrightError as exc:
            # Сбой запуска браузера/навигации, не связанный с конкретной картой.
            logger.exception("Сбой Playwright")
            return SkillOutput.failed(f"Сбой браузера: {exc}", url=query_or_url)

    # --- Сценарий А: прямой URL ---------------------------------------------

    async def _scenario_direct_url(self, page: Page, url: str) -> SkillOutput:
        nav = await self._goto(page, url)
        if isinstance(nav, SkillOutput):  # навигация вернула blocked/error
            return nav
        return await self._extract_pipeline(page, url)

    # --- Сценарий Б: поиск -> первая карточка -> парсинг --------------------

    async def _scenario_search(self, page: Page, host: str, query: str) -> SkillOutput:
        selectors = selectors_for_host(host)
        if selectors is None or not selectors.search_url_template:
            return SkillOutput.failed(f"Поиск по площадке '{host}' не сконфигурирован")

        search_url = selectors.search_url_template.format(query=quote_plus(query))
        nav = await self._goto(page, search_url)
        if isinstance(nav, SkillOutput):
            return nav

        # Берём ссылку на первую карточку выдачи.
        product_url = await self._first_search_result(
            page, search_url, selectors.search_result_link
        )
        if product_url is None:
            return SkillOutput.not_found(
                f"В выдаче '{query}' на {host} не найдено карточек товара", url=search_url
            )

        nav = await self._goto(page, product_url)
        if isinstance(nav, SkillOutput):
            return nav
        return await self._extract_pipeline(page, product_url)

    async def _first_search_result(
        self, page: Page, base_url: str, link_selector: str | None
    ) -> str | None:
        """Возвращаем абсолютный URL первой карточки из листинга."""
        if not link_selector:
            return None
        try:
            await page.wait_for_selector(link_selector, timeout=self._config.selector_timeout_ms)
        except PlaywrightTimeoutError:
            return None
        href = await page.evaluate(
            """(sel) => {
                const el = document.querySelector(sel);
                return el ? el.getAttribute('href') : null;
            }""",
            link_selector,
        )
        if not href:
            return None
        return urljoin(base_url, href)

    # --- Навигация с обработкой таймаутов и блокировок ----------------------

    async def _goto(self, page: Page, url: str):
        """Навигация + проверка блокировки. Возвращает None (ок) или SkillOutput."""
        http_status: int | None = None
        try:
            response = await page.goto(
                url, wait_until="domcontentloaded", timeout=self._config.navigation_timeout_ms
            )
            if response is not None:
                http_status = response.status
            # Даём догрузиться динамике; сетевой простой не критичен — глушим таймаут.
            try:
                await page.wait_for_load_state(
                    "networkidle", timeout=self._config.network_idle_timeout_ms
                )
            except PlaywrightTimeoutError:
                pass
        except PlaywrightTimeoutError:
            return SkillOutput.failed("Таймаут навигации", url=url)
        except PlaywrightError as exc:
            return SkillOutput.failed(f"Ошибка навигации: {exc}", url=url)

        html = await page.content()
        if (reason := detect_block(html, http_status)) is not None:
            return SkillOutput.blocked(reason, url)
        return None

    # --- Каскад извлечения ---------------------------------------------------

    async def _extract_pipeline(self, page: Page, url: str) -> SkillOutput:
        """JSON-LD → селекторы → regex → LLM. Первый успешный источник побеждает."""
        html = await page.content()

        # Повторная проверка блокировки: капча могла подгрузиться позже.
        if (reason := detect_block(html)) is not None:
            return SkillOutput.blocked(reason, url)

        host = normalize_host(urlparse(url).netloc)
        selectors = selectors_for_host(host)

        # 1) JSON-LD (schema.org) — самый устойчивый к смене вёрстки источник.
        if (data := extract_from_jsonld(html, url)) is not None:
            return self._validate(data, url, method="json-ld")

        # 2) CSS-селекторы домена (если домен известен).
        if selectors is not None:
            if (data := extract_from_selectors(html, url, selectors)) is not None:
                return self._validate(data, url, method="selectors")

        # 3) Грубый regex по meta/title.
        if (data := extract_from_regex(html, url)) is not None:
            # regex обычно даёт только title/price — пробуем дополнить через LLM,
            # но если LLM нет, отдаём хотя бы то, что есть.
            llm_data = await self._try_llm(html, url)
            return self._validate(
                llm_data or data, url, method="regex" if llm_data is None else "llm"
            )

        # 4) LLM-fallback на очищенном тексте.
        if (data := await self._try_llm(html, url)) is not None:
            return self._validate(data, url, method="llm")

        return SkillOutput.not_found("Не удалось извлечь данные ни одним из методов", url=url)

    async def _try_llm(self, html: str, url: str):
        page_text = clean_text_for_llm(html)
        return await extract_with_llm(page_text, url, self._llm)

    def _validate(self, data: dict, url: str, *, method: str) -> SkillOutput:
        """Финальная валидация через Pydantic — гарантия контракта для агента."""
        try:
            product = ProductData(**data)
        except ValidationError as exc:
            logger.warning("Валидация ProductData не прошла (%s): %s", method, exc)
            return SkillOutput.failed(f"Данные не прошли валидацию ({method}): {exc}", url=url)
        return SkillOutput.ok(product, method=method)


# --- Функциональный фасад (единая async-функция) ----------------------------


async def scrape_product(
    query_or_url: str,
    *,
    config: ScraperConfig | None = None,
    llm: LLMCallable | None = None,
) -> SkillOutput:
    """Удобная обёртка: один вызов = один результат.

    Пример::

        result = await scrape_product("Ozon: кофемашина delonghi")
        print(result.model_dump_json(indent=2, ensure_ascii=False))
    """
    skill = ProductScraperSkill(config=config, llm=llm)
    return await skill.scrape(query_or_url)


def scrape_product_sync(
    query_or_url: str,
    *,
    config: ScraperConfig | None = None,
    llm: LLMCallable | None = None,
) -> SkillOutput:
    """Синхронная обёртка для не-async окружений (например, CLI)."""
    return asyncio.run(scrape_product(query_or_url, config=config, llm=llm))


async def prepare_session(
    warmup_url: str,
    config: ScraperConfig,
    *,
    wait_timeout_s: float = 180.0,
    poll_s: float = 3.0,
) -> bool:
    """«Прогрев» постоянного профиля для работы БЕЗ прокси (one-time).

    Открывает площадку в headful + persistent-профиле и ждёт, пока со страницы
    исчезнут маркеры блокировки/капчи (т.е. пока вы решите капчу руками).
    Cookies/сессия сохраняются в ``config.user_data_dir`` и переиспользуются
    последующими вызовами ``scrape_product`` — это и позволяет редким запросам
    проходить без прокси.

    Возвращает ``True``, если блокировка снята до таймаута.
    """
    if not config.user_data_dir:
        raise ValueError(
            "prepare_session требует config.user_data_dir — иначе сессию негде сохранить"
        )
    if config.headless:
        logger.warning("Рекомендуется headless=False, чтобы вручную пройти капчу")

    loop = asyncio.get_event_loop()
    deadline = loop.time() + wait_timeout_s
    async with StealthBrowser(config) as browser:
        page = await browser.new_page()
        await page.goto(warmup_url, wait_until="domcontentloaded")
        while loop.time() < deadline:
            if detect_block(await page.content()) is None:
                logger.info("Блокировка снята — сессия прогрета")
                return True
            await asyncio.sleep(poll_s)
    logger.warning("prepare_session: таймаут ожидания снятия блокировки")
    return False


__all__ = [
    "ProductScraperSkill",
    "scrape_product",
    "scrape_product_sync",
    "prepare_session",
]
