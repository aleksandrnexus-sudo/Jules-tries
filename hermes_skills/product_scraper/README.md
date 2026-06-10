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

## Тесты

Юнит-тесты не требуют сети/браузера:

```bash
# из каталога скилла, с корнем репозитория в PYTHONPATH
PYTHONPATH="$(git rev-parse --show-toplevel)" pytest -q
ruff check .
MYPYPATH="$(git rev-parse --show-toplevel)" \
  mypy --explicit-package-bases --namespace-packages -p hermes_skills.product_scraper
```
