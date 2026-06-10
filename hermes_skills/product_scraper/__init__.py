"""Hermes skill: отказоустойчивое извлечение данных о товарах.

Публичный API::

    from hermes_skills.product_scraper import scrape_product, ProductData, SkillOutput

    result = await scrape_product("Ozon: наушники sony wh-1000xm5")
"""

from __future__ import annotations

from .config import ScraperConfig
from .llm_fallback import LLMExtractor
from .schemas import ProductData, ScrapeStatus, SkillOutput
from .scraper import ProductScraperSkill, scrape_product, scrape_product_sync

__version__ = "0.1.0"

__all__ = [
    "scrape_product",
    "scrape_product_sync",
    "ProductScraperSkill",
    "ProductData",
    "SkillOutput",
    "ScrapeStatus",
    "ScraperConfig",
    "LLMExtractor",
]
