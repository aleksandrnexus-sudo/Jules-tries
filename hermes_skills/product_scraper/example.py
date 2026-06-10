"""Примеры вызова скилла.

Запуск::

    python -m hermes_skills.product_scraper.example

Сценарий Б (поиск -> переход на первую карточку -> извлечение) показан первым,
как просил пользователь. Сценарий А (прямой URL) — ниже.
"""

from __future__ import annotations

import asyncio

from .config import ScraperConfig
from .scraper import scrape_product


# --- Опциональный пример LLM-fallback ---------------------------------------
# В проде сюда подставляется реальный вызов модели Hermes. Сигнатура — async
# функция: (prompt: str) -> str (текст ответа модели, желательно чистый JSON).
async def example_llm(prompt: str) -> str:  # pragma: no cover - демонстрация
    # return await my_llm_client.complete(prompt)
    raise NotImplementedError("Подключите реальную LLM агента Hermes")


async def scenario_b_search() -> None:
    """Сценарий Б: 'Ozon: <запрос>' -> первая карточка выдачи -> данные."""
    config = ScraperConfig(
        headless=True,
        # proxy="http://user:pass@proxy-host:port",  # ротация прокси — снаружи
    )
    result = await scrape_product("Ozon: кофемашина delonghi", config=config)

    # SkillOutput строго валидирован Pydantic; отдаём агенту как JSON.
    print(result.model_dump_json(indent=2))

    if result.success and result.data:
        print("Название:", result.data.title)
        print("Цена:", result.data.price, result.data.currency)
        print("Рейтинг:", result.data.rating, "| отзывов:", result.data.reviews_count)
    elif result.status.value == "blocked":
        # Сигнал агенту Hermes сменить прокси/сессию и повторить.
        print("Заблокировано:", result.reason, "| URL:", result.url)


async def scenario_a_direct_url() -> None:
    """Сценарий А: прямой URL карточки товара."""
    url = "https://www.ozon.ru/product/primer-tovara-123456789/"
    result = await scrape_product(url)
    print(result.model_dump_json(indent=2))


async def main() -> None:
    await scenario_b_search()
    # await scenario_a_direct_url()


if __name__ == "__main__":
    asyncio.run(main())
