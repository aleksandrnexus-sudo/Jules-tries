"""Pydantic-схемы выходных данных скилла.

Схема строго валидирует данные перед возвратом агенту Hermes. Любой источник
извлечения (CSS-селекторы, JSON-LD, regex, LLM-fallback) обязан привести данные
к :class:`ProductData`, иначе результат не пройдёт валидацию и будет помечен как
ошибка — так агент всегда получает предсказуемый контракт.
"""

from __future__ import annotations

from enum import StrEnum

from pydantic import BaseModel, Field, HttpUrl, field_validator


class ScrapeStatus(StrEnum):
    """Статус выполнения скилла.

    ``BLOCKED`` — критичный статус: означает, что площадка отдала капчу или
    403/503. Агент Hermes использует его как сигнал к смене прокси/сессии.
    """

    SUCCESS = "success"
    BLOCKED = "blocked"
    NOT_FOUND = "not_found"
    ERROR = "error"


class ProductData(BaseModel):
    """Нормализованная карточка товара."""

    title: str = Field(description="Полное наименование товара")
    price: float | None = Field(None, description="Текущая цена без копеек")
    currency: str = Field("RUB", description="Валюта цены (ISO 4217)")
    attributes: dict[str, str] = Field(
        default_factory=dict,
        description="Характеристики товара (ключ-значение)",
    )
    description: str | None = Field(None, description="Описание товара")
    rating: float | None = Field(None, description="Рейтинг товара (0.0 - 5.0)")
    reviews_count: int = Field(0, description="Количество отзывов")
    source_url: HttpUrl = Field(description="Ссылка на источник данных")
    is_available: bool = Field(True, description="Статус наличия товара")

    @field_validator("rating")
    @classmethod
    def _clamp_rating(cls, value: float | None) -> float | None:
        """Рейтинг площадок иногда приходит по 10-балльной шкале или с мусором.

        Приводим к диапазону 0..5; явно некорректные значения отбрасываем
        (``None``), чтобы не отдавать агенту ложные данные.
        """
        if value is None:
            return None
        if value < 0:
            return None
        # Нормализуем 10-балльную шкалу к 5-балльной.
        if value > 5:
            value = value / 2
        return round(min(value, 5.0), 2)

    @field_validator("reviews_count")
    @classmethod
    def _non_negative_reviews(cls, value: int) -> int:
        return max(value, 0)


class SkillOutput(BaseModel):
    """Единый контракт ответа скилла для агента Hermes.

    Поля ``status`` / ``reason`` / ``url`` дают агенту структурированную ошибку
    в формате ``{"status": "blocked", "reason": "...", "url": "..."}`` (см.
    :meth:`blocked`), не ломая базовый контракт ``success``/``data``/``error``.
    """

    success: bool
    data: ProductData | None = None
    error: str | None = None
    status: ScrapeStatus = ScrapeStatus.SUCCESS
    reason: str | None = Field(None, description="Причина блокировки/ошибки")
    url: str | None = Field(None, description="URL, на котором произошло событие")
    # Какой механизм извлёк данные: selectors | json-ld | regex | llm.
    extraction_method: str | None = None

    @classmethod
    def ok(cls, data: ProductData, *, method: str) -> SkillOutput:
        return cls(
            success=True,
            data=data,
            status=ScrapeStatus.SUCCESS,
            url=str(data.source_url),
            extraction_method=method,
        )

    @classmethod
    def blocked(cls, reason: str, url: str) -> SkillOutput:
        return cls(
            success=False,
            status=ScrapeStatus.BLOCKED,
            reason=reason,
            error=reason,
            url=url,
        )

    @classmethod
    def not_found(cls, reason: str, url: str | None = None) -> SkillOutput:
        return cls(
            success=False,
            status=ScrapeStatus.NOT_FOUND,
            reason=reason,
            error=reason,
            url=url,
        )

    @classmethod
    def failed(cls, error: str, url: str | None = None) -> SkillOutput:
        return cls(
            success=False,
            status=ScrapeStatus.ERROR,
            error=error,
            reason=error,
            url=url,
        )


__all__ = ["ProductData", "SkillOutput", "ScrapeStatus"]
