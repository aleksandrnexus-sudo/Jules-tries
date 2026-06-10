# Hermes Skill: `product_scraper`

Отказоустойчивый скилл извлечения данных о товарах для ИИ-агента **Hermes**.
Собирает: наименование, цену, характеристики, описание, рейтинг, отзывы и статус
наличия — и возвращает строго валидированный Pydantic-JSON.

## Архитектура

Скилл автономен и собран из независимых слоёв:

| Модуль | Ответственность |
|---|---|
| `schemas.py` | Pydantic-контракт (`ProductData`, `SkillOutput`, `ScrapeStatus`). Финальная валидация. |
| `config.py` | Декларативные селекторы по доменам, пул User-Agent, заголовки, таймауты, маркеры капчи. |
| `browser.py` | `StealthBrowser` — управление async-сессией Playwright + анти-детект init-скрипт, ротация UA, точка интеграции прокси. |
| `extractors.py` | Каскад парсинга: **JSON-LD → CSS-селекторы → regex/meta**; детект блокировок; очистка HTML для LLM. |
| `llm_fallback.py` | Протокол `LLMExtractor` и `extract_with_llm` — извлечение из «грязной» вёрстки моделью агента. |
| `scraper.py` | Оркестратор: выбор Сценария А/Б, навигация, каскад извлечения, обработка ошибок. |

### Пайплайн извлечения

```
вход (URL | "Ozon: запрос")
        │
   ┌────┴─────────────────────────┐
Сценарий А (URL)          Сценарий Б (поиск площадки)
   │                          │  внутренний поиск → первая карточка
   └──────────┬───────────────┘
              ▼
   stealth-навигация + детект блокировки (403/503/429, капча-маркеры)
              ▼
   JSON-LD ──нет──▶ CSS-селекторы ──нет──▶ regex/meta ──нет──▶ LLM-fallback
              ▼
   Pydantic-валидация → SkillOutput(success | blocked | not_found | error)
```

**Почему JSON-LD первым.** `<script type="application/ld+json">` со schema.org
`Product` содержит цену, наличие, рейтинг и отзывы в стандартизированном виде и
не зависит от CSS-классов (которые на маркетплейсах меняются еженедельно).
Селекторы — лишь страховка для площадок без валидного schema.org.

### Обход блокировок

- Ротация User-Agent + реалистичные заголовки (`Accept-Language: ru-RU`).
- Анти-детект init-скрипт (`navigator.webdriver`, plugins, WebGL, `window.chrome`).
- Флаги запуска `--disable-blink-features=AutomationControlled` и пр.
- Таймауты на навигацию/ожидание/селекторы.
- При капче/403/503/429 → `SkillOutput.blocked(reason, url)` — сигнал Hermes
  сменить прокси/сессию.

> Для боя Ozon/WB чистого Playwright недостаточно. Точки усиления:
> `playwright-stealth`, внешние Scraping API (Bright Data, Scrapeit) и ротация
> прокси через `ScraperConfig.proxy`.

### Работа БЕЗ прокси (редкие запросы)

HTTP 403 от Ozon/WB на сервере — это **блокировка по IP** (датацентровые IP
режутся мгновенно), а не баг скилла. Без покупки прокси для редких запросов
решают три фактора:

1. **Residential IP.** Запускайте скилл с домашнего/офисного IP (ваш ноут,
   домашний сервер), а не с VPS/датацентра. Это решающий фактор — никакой
   stealth не спасёт датацентровый IP против Ozon/WB.
2. **Постоянный профиль** (`ScraperConfig.user_data_dir`). Cookies, сессия и
   решённая капча сохраняются между запусками и переиспользуются.
3. **`playwright-stealth`** (опционально: `pip install playwright-stealth`).
   Если пакет установлен — подключается автоматически; если нет — скилл
   работает на встроенном init-скрипте.

Одноразовый «прогрев» сессии (вручную пройти антибот, дальше сессия живёт):

```python
from hermes_skills.product_scraper import prepare_session, scrape_product, ScraperConfig

PROFILE = "/opt/hermes/.cache/product_scraper_profile"

# 1) ОДИН раз, headful: открыть площадку и пройти капчу руками.
cfg = ScraperConfig(user_data_dir=PROFILE, headless=False)
await prepare_session("https://www.ozon.ru/", cfg, wait_timeout_s=180)

# 2) Дальше — обычные запросы с тем же профилем (можно headless).
result = await scrape_product(
    "Ozon: кофемашина delonghi",
    config=ScraperConfig(user_data_dir=PROFILE, headless=True, max_retries=2),
)
```

Ретраи (`max_retries`, `retry_backoff_s`) с экспоненциальным backoff помогают с
«плавающими» антибот-проверками без смены IP: при `blocked`/таймауте скилл
повторяет попытку в новой сессии (новый UA).

#### Прогрев antibot-cookies (по умолчанию вкл.)

Даже с **рабочим RU residential IP** Ozon отдаёт `403` на прямой заход в
`https://www.ozon.ru/search/?text=...`, если у браузера нет antibot-cookies,
которые площадка выставляет на главной странице. Поэтому перед поиском/карточкой
скилл сначала открывает homepage площадки в той же сессии и только потом идёт на
целевой URL (`ScraperConfig.warmup_origin`, по умолчанию `True`). Для имитации
живого пользователя можно добавить случайные паузы: `human_jitter_ms=300`.

`403` после прогрева — это уже **fingerprint headless-браузера**, а не IP. Для
Ozon при редких запросах надёжнее всего: один раз `prepare_session(...)` в
headful (пройти антибот руками) + постоянный профиль `user_data_dir` + по
возможности `pip install playwright-stealth`. Тогда последующие `scrape_product`
переиспользуют решённую сессию. Чистый ephemeral headless Ozon почти всегда режет
независимо от IP — и это ожидаемый, корректно обрабатываемый `blocked`, а не
падение скилла.

### Маршрутизация через ваш российский IP (WireGuard)

Чтобы выходить под вашим RU residential IP, скрапер можно гонять через WireGuard
к вашему домашнему роутеру. В вашем конфиге `AllowedIPs = 0.0.0.0/0` (full-tunnel),
поэтому поднимать его системно (`wg-quick up`) **опасно** — через тоннель уйдёт
весь трафик хоста, включая управляющий канал агента Hermes. Решение —
изолированный **network namespace**: через RU-IP идёт только процесс скрапера.

**Шаги (нужен root на машине, где крутится скрапер):**

```bash
cd hermes_skills/product_scraper

# 1. Конфиг с вашими ключами (НЕ коммитится — в .gitignore).
cp wireguard/hermes-ru.conf.example wireguard/hermes-ru.conf
#   впишите PrivateKey клиента и PublicKey роутера (остальное уже заполнено:
#   Address 10.0.0.7/32, DNS 10.0.0.1, MTU 1200, Endpoint 92.248.252.204:51820)

# 2. Поднять тоннель в namespace 'hermes_wg'.
sudo ./scripts/wg_netns.sh up

# 3. Убедиться, что выход реально через ваш RU-IP.
sudo ./scripts/wg_netns.sh exec curl -s https://ifconfig.co/json

# 4. Запустить скрапер ВНУТРИ тоннеля.
sudo PYTHONPATH="$(git rev-parse --show-toplevel)" \
  ./scripts/wg_netns.sh exec /opt/hermes/.venv/bin/python /opt/hermes/run_scrape.py

# 5. Снять тоннель.
sudo ./scripts/wg_netns.sh down
```

Внутри Python можно дополнительно проверить egress перед сбором:

```python
from hermes_skills.product_scraper import check_egress_ip, scrape_product
info = await check_egress_ip()           # {"ip": "...", "country": "Russia"}
assert info.get("country") == "Russia", f"Не RU IP: {info}"
result = await scrape_product("Ozon: кофемашина delonghi")
```

> WireGuard-интерфейс создаётся в namespace, поэтому в `scrape_product` **прокси
> не нужен** (`ScraperConfig.proxy` оставьте пустым) — маршрутизацию делает namespace.
> Параметры скрипта переопределяются через env: `WG_CONF`, `WG_NS`, `WG_IF`,
> `WG_ADDRESS`, `WG_DNS`, `WG_MTU`.

## Установка

Скилл оформлен как namespace-пакет `hermes_skills.product_scraper`, поэтому
запускается из корня репозитория (без editable-установки):

```bash
uv venv && source .venv/bin/activate
uv pip install playwright beautifulsoup4 pydantic
playwright install chromium          # один раз — скачать Chromium
export PYTHONPATH="$(git rev-parse --show-toplevel)"   # корень репозитория
```

Dev-зависимости (тесты/линт/типы): `uv pip install pytest pytest-asyncio ruff mypy`.

## Использование

```python
import asyncio
from hermes_skills.product_scraper import scrape_product, ScraperConfig

async def main():
    # Сценарий Б: поиск → первая карточка → извлечение
    result = await scrape_product("Ozon: кофемашина delonghi")
    print(result.model_dump_json(indent=2))

    # Сценарий А: прямой URL
    result = await scrape_product("https://www.ozon.ru/product/...-123/")

    # С прокси и LLM-fallback (инжектируются агентом)
    cfg = ScraperConfig(proxy="http://user:pass@host:port")
    result = await scrape_product("WB: наушники", config=cfg, llm=my_async_llm)

asyncio.run(main())
```

`my_async_llm` — любая `async def (prompt: str) -> str`, возвращающая ответ
модели (желательно чистый JSON).

## Поля результата (`ProductData`)

Модель использует именно эти имена полей (важно при чтении `result.data`):

| Поле | Значение |
|---|---|
| `result.data.title` | наименование товара (**не** `name`) |
| `result.data.price` | цена (float) |
| `result.data.currency` | валюта (по умолчанию `RUB`) |
| `result.data.attributes` | характеристики (dict) |
| `result.data.description` | описание |
| `result.data.rating` | рейтинг 0.0–5.0 |
| `result.data.reviews_count` | число отзывов (**не** `review_count`) |
| `result.data.is_available` | статус наличия (bool) |
| `result.data.source_url` | ссылка на источник |

## Тесты

Юнит-тесты не требуют сети/браузера:

```bash
# из каталога скилла, с корнем репозитория в PYTHONPATH
PYTHONPATH="$(git rev-parse --show-toplevel)" pytest -q
ruff check .
MYPYPATH="$(git rev-parse --show-toplevel)" \
  mypy --explicit-package-bases --namespace-packages -p hermes_skills.product_scraper
```
