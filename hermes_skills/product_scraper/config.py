"""Конфигурация скилла: селекторы по доменам, User-Agent'ы, маркеры блокировок.

Селекторы намеренно вынесены в декларативный словарь, а не зашиты в код, чтобы
их можно было править без изменения логики (CSS-классы маркетплейсов меняются
едва ли не еженедельно). Основной механизм извлечения — JSON-LD (см.
``extractors.py``); CSS-селекторы — лишь дополнение для площадок, которые не
отдают валидный schema.org.
"""

from __future__ import annotations

from dataclasses import dataclass, field

# --- Настройки сети/браузера -------------------------------------------------

# Пул реалистичных User-Agent'ов для ротации. Имитируем свежие десктоп-браузеры.
USER_AGENTS: list[str] = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) "
    "Gecko/20100101 Firefox/125.0",
]

# Базовые заголовки реального пользователя (Accept-Language под RU-площадки).
DEFAULT_HEADERS: dict[str, str] = {
    "Accept": (
        "text/html,application/xhtml+xml,application/xml;q=0.9,"
        "image/avif,image/webp,*/*;q=0.8"
    ),
    "Accept-Language": "ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7",
    "Sec-Fetch-Dest": "document",
    "Sec-Fetch-Mode": "navigate",
    "Sec-Fetch-Site": "none",
    "Sec-Fetch-User": "?1",
    "Upgrade-Insecure-Requests": "1",
}

# Таймауты (мс) — на навигацию и на ожидание сетевого простоя.
NAVIGATION_TIMEOUT_MS = 45_000
NETWORK_IDLE_TIMEOUT_MS = 15_000
SELECTOR_TIMEOUT_MS = 8_000

# Текстовые маркеры капчи/антибота (lowercase). Если встречаем в HTML —
# считаем сессию заблокированной и возвращаем status=blocked.
CAPTCHA_MARKERS: list[str] = [
    "captcha",
    "recaptcha",
    "hcaptcha",
    "are you a robot",
    "i'm not a robot",
    "проверка безопасности",
    "проверьте, что запросы отправляли вы",
    "подтвердите, что вы не робот",
    "доступ ограничен",
    "access denied",
    "just a moment",  # Cloudflare challenge
    "cf-chl",  # Cloudflare challenge token
    "ddos-guard",
    "antibot",
    "слишком много запросов",
    "too many requests",
]

# HTTP-коды, которые трактуем как блокировку.
BLOCKED_HTTP_STATUSES = {403, 429, 503}


# --- Селекторы по доменам ----------------------------------------------------


@dataclass(frozen=True)
class DomainSelectors:
    """Набор CSS-селекторов и URL-шаблона поиска для конкретного домена.

    Любое поле может быть ``None`` — тогда извлечение этого поля целиком ложится
    на JSON-LD / regex / LLM-fallback.
    """

    # Шаблон URL внутреннего поиска площадки. ``{query}`` подставляется
    # URL-энкодед строкой запроса.
    search_url_template: str | None = None
    # Селектор ссылки на первую карточку в выдаче (Сценарий Б).
    search_result_link: str | None = None

    title: str | None = None
    price: str | None = None
    description: str | None = None
    rating: str | None = None
    reviews_count: str | None = None
    availability: str | None = None
    # Селектор контейнера, по которому понимаем, что карточка прогрузилась.
    ready_selector: str | None = None
    # Селекторы строк таблицы характеристик: (контейнер, ключ, значение).
    attributes_rows: str | None = None
    attribute_key: str | None = None
    attribute_value: str | None = None
    currency: str = "RUB"


# Реестр доменов. Ключ — нормализованный хост (без ``www.``).
# ВНИМАНИЕ: значения CSS-классов маркетплейсов нестабильны; это «best effort».
# Главная опора — JSON-LD, поэтому отсутствие/устаревание селекторов не ломает
# скилл, а лишь переключает его на fallback.
DOMAIN_SELECTORS: dict[str, DomainSelectors] = {
    "ozon.ru": DomainSelectors(
        search_url_template="https://www.ozon.ru/search/?text={query}&from_global=true",
        search_result_link="a[href*='/product/']",
        title="h1",
        ready_selector="h1",
        currency="RUB",
    ),
    "wildberries.ru": DomainSelectors(
        search_url_template="https://www.wildberries.ru/catalog/0/search.aspx?search={query}",
        search_result_link="a.product-card__link, a[href*='/catalog/'][href*='/detail.aspx']",
        title="h1.product-page__title, h1",
        price="ins.price-block__final-price, .price-block__final-price",
        rating=".product-review__rating, .address-rate-mini",
        reviews_count=".product-review__count-review, .count-review",
        ready_selector="h1",
        currency="RUB",
    ),
    "market.yandex.ru": DomainSelectors(
        search_url_template="https://market.yandex.ru/search?text={query}",
        search_result_link="a[href*='/product--'], a[data-baobab-name='title']",
        title="h1",
        ready_selector="h1",
        currency="RUB",
    ),
    "amazon.com": DomainSelectors(
        search_url_template="https://www.amazon.com/s?k={query}",
        search_result_link="a.a-link-normal.s-no-outline, h2 a.a-link-normal",
        title="#productTitle",
        price="span.a-price span.a-offscreen, #priceblock_ourprice",
        description="#productDescription, #feature-bullets",
        rating="span[data-hook='rating-out-of-text'], #acrPopover",
        reviews_count="#acrCustomerReviewText",
        availability="#availability",
        attributes_rows="#productDetails_techSpec_section_1 tr, table.prodDetTable tr",
        attribute_key="th",
        attribute_value="td",
        ready_selector="#productTitle",
        currency="USD",
    ),
}


@dataclass
class ScraperConfig:
    """Рантайм-настройки одной сессии скрапинга.

    Прокси и LLM-fallback внедряются снаружи (агентом Hermes), чтобы модуль
    оставался автономным и тестируемым.
    """

    headless: bool = True
    proxy: str | None = None  # напр. "http://user:pass@host:port"
    locale: str = "ru-RU"
    timezone_id: str = "Europe/Moscow"
    navigation_timeout_ms: int = NAVIGATION_TIMEOUT_MS
    network_idle_timeout_ms: int = NETWORK_IDLE_TIMEOUT_MS
    selector_timeout_ms: int = SELECTOR_TIMEOUT_MS
    user_agents: list[str] = field(default_factory=lambda: list(USER_AGENTS))
    extra_headers: dict[str, str] = field(default_factory=lambda: dict(DEFAULT_HEADERS))

    # --- Режим "без прокси" для редких запросов ----------------------------
    # Постоянный профиль браузера (cookies/сессия/решённая капча сохраняются
    # между запусками). Ключ к работе без прокси: один раз вручную пройти
    # антибот в headful — дальше сессия переиспользуется.
    user_data_dir: str | None = None
    # Применять playwright-stealth, если пакет установлен (мягкая зависимость).
    use_stealth: bool = True
    # Замедление действий (мс) — имитация «человеческого» темпа. 0 = выкл.
    slow_mo_ms: int = 0
    # Ретраи при временной блокировке/таймауте (экспоненциальный backoff).
    max_retries: int = 2
    retry_backoff_s: float = 2.0
    # Доп. аргументы запуска Chromium (для тонкой настройки агентом).
    extra_launch_args: list[str] = field(default_factory=list)


def normalize_host(host: str) -> str:
    """Приводим хост к ключу реестра: lowercase, без ``www.``."""
    host = host.lower().strip()
    if host.startswith("www."):
        host = host[4:]
    return host


def selectors_for_host(host: str) -> DomainSelectors | None:
    """Находим селекторы по хосту, учитывая поддомены (``m.ozon.ru`` -> ``ozon.ru``)."""
    host = normalize_host(host)
    if host in DOMAIN_SELECTORS:
        return DOMAIN_SELECTORS[host]
    # Сопоставление по суффиксу для поддоменов/региональных зон.
    for known_host, selectors in DOMAIN_SELECTORS.items():
        if host.endswith("." + known_host) or host == known_host:
            return selectors
    return None


__all__ = [
    "ScraperConfig",
    "DomainSelectors",
    "DOMAIN_SELECTORS",
    "USER_AGENTS",
    "DEFAULT_HEADERS",
    "CAPTCHA_MARKERS",
    "BLOCKED_HTTP_STATUSES",
    "normalize_host",
    "selectors_for_host",
]
