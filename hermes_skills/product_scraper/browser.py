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

# playwright-stealth — мягкая зависимость: если установлена, усиливаем маскировку
# поверх нашего init-скрипта. Если нет — молча работаем без неё.
try:  # pragma: no cover - зависит от окружения
    from playwright_stealth import stealth_async as _stealth_async
except Exception:  # noqa: BLE001
    _stealth_async = None

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
        """Поднимаем Playwright и контекст с stealth-настройками.

        Два режима:

        * **persistent** (``config.user_data_dir`` задан) — постоянный профиль:
          cookies/сессия/решённая капча сохраняются между запусками. Это ключ к
          работе без прокси для редких запросов.
        * **ephemeral** — обычный одноразовый контекст.
        """
        self._pw = await async_playwright().start()

        # Общие флаги запуска: снижают детектируемость автоматизации.
        launch_args = [
            "--disable-blink-features=AutomationControlled",
            "--no-sandbox",
            "--disable-dev-shm-usage",
            *self._config.extra_launch_args,
        ]
        # Параметры контекста с правдоподобным fingerprint'ом.
        context_kwargs: dict = {
            "user_agent": self._user_agent,
            "locale": self._config.locale,
            "timezone_id": self._config.timezone_id,
            "viewport": {"width": 1920, "height": 1080},
            "extra_http_headers": self._config.extra_headers,
        }
        # Точка интеграции прокси (ротация выполняется агентом снаружи).
        if self._config.proxy:
            context_kwargs["proxy"] = {"server": self._config.proxy}

        if self._config.user_data_dir:
            # Persistent-контекст сам управляет браузером (отдельного Browser нет).
            self._context = await self._pw.chromium.launch_persistent_context(
                self._config.user_data_dir,
                headless=self._config.headless,
                slow_mo=self._config.slow_mo_ms,
                args=launch_args,
                **context_kwargs,
            )
        else:
            self._browser = await self._pw.chromium.launch(
                headless=self._config.headless,
                slow_mo=self._config.slow_mo_ms,
                args=launch_args,
                **({"proxy": context_kwargs.pop("proxy")} if "proxy" in context_kwargs else {}),
            )
            self._context = await self._browser.new_context(**context_kwargs)

        self._context.set_default_navigation_timeout(self._config.navigation_timeout_ms)
        self._context.set_default_timeout(self._config.selector_timeout_ms)
        # Наш базовый init-скрипт во все страницы контекста.
        await self._context.add_init_script(_STEALTH_INIT_JS)
        logger.debug(
            "Контекст поднят (UA=%s, persistent=%s, stealth=%s)",
            self._user_agent,
            bool(self._config.user_data_dir),
            self._config.use_stealth and _stealth_async is not None,
        )

    async def _apply_stealth(self, page: Page) -> None:
        """Накатываем playwright-stealth на страницу, если пакет доступен."""
        if self._config.use_stealth and _stealth_async is not None:
            try:
                await _stealth_async(page)
            except Exception:  # noqa: BLE001 — stealth не критичен для работы
                logger.debug("playwright-stealth не применился", exc_info=True)

    async def new_page(self) -> Page:
        if self._context is None:
            raise RuntimeError(
                "StealthBrowser не запущен: вызовите start() / используйте async with"
            )
        page = await self._context.new_page()
        await self._apply_stealth(page)
        return page

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
