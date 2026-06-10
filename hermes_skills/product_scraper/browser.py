"""Управление stealth-сессией Playwright.

Чистый Playwright детектируется крупными маркетплейсами (Ozon/WB) за секунды по
ряду признаков автоматизации: ``navigator.webdriver``, отсутствие плагинов,
аномалии WebGL и т.д. Здесь мы:

* поднимаем persistent/ephemeral контекст с реалистичным fingerprint'ом;
* инъектируем init-скрипт, маскирующий признаки headless-автоматизации;
* поддерживаем ротацию User-Agent и прокси.

Для боевого Hermes сюда же подключается ``playwright-stealth`` и/или внешний
Scraping API (Bright Data, Scrapeit) — точка интеграции отмечена ниже.
"""

from __future__ import annotations

import logging
import random
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from playwright.async_api import (
    Browser,
    BrowserContext,
    Page,
    Playwright,
    async_playwright,
)

from .config import ScraperConfig

logger = logging.getLogger("hermes.product_scraper.browser")

# JS, который выполняется ДО любого скрипта страницы (add_init_script).
# Маскирует самые явные маркеры headless-Chromium. Это базовый набор; для
# продакшена усильте его playwright-stealth.
_STEALTH_INIT_JS = """
// navigator.webdriver -> undefined
Object.defineProperty(navigator, 'webdriver', { get: () => undefined });

// Правдоподобный список языков.
Object.defineProperty(navigator, 'languages', { get: () => ['ru-RU', 'ru', 'en-US', 'en'] });

// Непустой список плагинов (headless обычно отдаёт 0).
Object.defineProperty(navigator, 'plugins', {
  get: () => [1, 2, 3, 4, 5].map((i) => ({ name: 'Plugin ' + i })),
});

// window.chrome присутствует в обычном Chrome, но не в headless.
window.chrome = window.chrome || { runtime: {} };

// Обходим наивную проверку прав на нотификации.
const originalQuery = window.navigator.permissions && window.navigator.permissions.query;
if (originalQuery) {
  window.navigator.permissions.query = (parameters) =>
    parameters && parameters.name === 'notifications'
      ? Promise.resolve({ state: Notification.permission })
      : originalQuery(parameters);
}

// Подменяем WebGL vendor/renderer на значения реальной видеокарты.
const getParameter = WebGLRenderingContext.prototype.getParameter;
WebGLRenderingContext.prototype.getParameter = function (parameter) {
  if (parameter === 37445) return 'Intel Inc.';            // UNMASKED_VENDOR_WEBGL
  if (parameter === 37446) return 'Intel Iris OpenGL Engine'; // UNMASKED_RENDERER_WEBGL
  return getParameter.call(this, parameter);
};
"""


class StealthBrowser:
    """Обёртка над Playwright с управлением жизненным циклом ресурсов.

    Используется как async context manager, чтобы гарантированно закрывать
    браузер даже при исключениях::

        async with StealthBrowser(config) as browser:
            page = await browser.new_page()
            ...
    """

    def __init__(self, config: ScraperConfig | None = None) -> None:
        self._config = config or ScraperConfig()
        self._pw: Playwright | None = None
        self._browser: Browser | None = None
        self._context: BrowserContext | None = None
        self._user_agent: str = random.choice(self._config.user_agents)

    @property
    def user_agent(self) -> str:
        return self._user_agent

    async def __aenter__(self) -> StealthBrowser:
        await self.start()
        return self

    async def __aexit__(self, *exc_info: object) -> None:
        await self.close()

    async def start(self) -> None:
        """Поднимаем Playwright, браузер и контекст с stealth-настройками."""
        self._pw = await async_playwright().start()

        launch_kwargs: dict = {
            "headless": self._config.headless,
            # Эти флаги дополнительно снижают детектируемость автоматизации.
            "args": [
                "--disable-blink-features=AutomationControlled",
                "--no-sandbox",
                "--disable-dev-shm-usage",
            ],
        }
        # Точка интеграции прокси (ротация прокси выполняется агентом Hermes
        # снаружи — он пересоздаёт сессию с новым значением config.proxy).
        if self._config.proxy:
            launch_kwargs["proxy"] = {"server": self._config.proxy}

        self._browser = await self._pw.chromium.launch(**launch_kwargs)

        # Контекст с правдоподобным fingerprint'ом: UA, локаль, таймзона,
        # вьюпорт и заголовки реального пользователя.
        self._context = await self._browser.new_context(
            user_agent=self._user_agent,
            locale=self._config.locale,
            timezone_id=self._config.timezone_id,
            viewport={"width": 1920, "height": 1080},
            extra_http_headers=self._config.extra_headers,
        )
        self._context.set_default_navigation_timeout(self._config.navigation_timeout_ms)
        self._context.set_default_timeout(self._config.selector_timeout_ms)
        # Инъекция stealth-скрипта во все страницы контекста.
        await self._context.add_init_script(_STEALTH_INIT_JS)
        logger.debug("Stealth-контекст поднят (UA=%s)", self._user_agent)

    async def new_page(self) -> Page:
        if self._context is None:
            raise RuntimeError(
                "StealthBrowser не запущен: вызовите start() / используйте async with"
            )
        return await self._context.new_page()

    async def close(self) -> None:
        """Аккуратно освобождаем ресурсы в обратном порядке."""
        for closer in (
            getattr(self._context, "close", None),
            getattr(self._browser, "close", None),
            getattr(self._pw, "stop", None),
        ):
            if closer is None:
                continue
            try:
                await closer()
            except Exception:  # noqa: BLE001 — на закрытии ошибки не критичны
                logger.debug("Ошибка при закрытии ресурса браузера", exc_info=True)
        self._context = self._browser = self._pw = None


@asynccontextmanager
async def stealth_page(config: ScraperConfig | None = None) -> AsyncIterator[Page]:
    """Удобный хелпер: отдаёт готовую stealth-страницу и сам всё закрывает."""
    async with StealthBrowser(config) as browser:
        page = await browser.new_page()
        yield page


__all__ = ["StealthBrowser", "stealth_page"]
