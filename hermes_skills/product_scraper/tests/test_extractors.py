"""Юнит-тесты парсеров и извлечения (без сети и браузера)."""

from __future__ import annotations

import pytest
from hermes_skills.product_scraper.config import selectors_for_host
from hermes_skills.product_scraper.extractors import (
    detect_block,
    extract_from_jsonld,
    extract_from_regex,
    extract_from_selectors,
    parse_int,
    parse_price,
    parse_rating,
)
from hermes_skills.product_scraper.schemas import ProductData


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("1 299,00 ₽", 1299.0),
        ("1\xa0299 ₽", 1299.0),
        ("$1,299.99", 1299.99),
        ("19990", 19990.0),
        ("99,90", 99.90),
        ("1.299,50", 1299.50),
        (1499, 1499.0),
        ("нет цены", None),
    ],
)
def test_parse_price(raw, expected):
    assert parse_price(raw) == expected


@pytest.mark.parametrize(
    ("raw", "expected"),
    [("1 234 отзыва", 1234), ("отзывов нет", None), (42, 42), ("3 000", 3000)],
)
def test_parse_int(raw, expected):
    assert parse_int(raw) == expected


@pytest.mark.parametrize(
    ("raw", "expected"),
    [("4,8 из 5", 4.8), ("4.5", 4.5), ("", None), (5, 5.0)],
)
def test_parse_rating(raw, expected):
    assert parse_rating(raw) == expected


def test_detect_block_by_status():
    assert detect_block("<html>ok</html>", http_status=403) is not None
    assert detect_block("<html>ok</html>", http_status=200) is None


def test_detect_block_by_marker():
    assert detect_block("<html>Подтвердите, что вы не робот</html>") is not None
    assert detect_block("<html><h1>Товар</h1></html>") is None


JSONLD_HTML = """
<html><head>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Кофемашина DeLonghi Magnifica",
  "description": "Автоматическая кофемашина",
  "brand": {"@type": "Brand", "name": "DeLonghi"},
  "aggregateRating": {"@type": "AggregateRating", "ratingValue": "4.7", "reviewCount": "1532"},
  "offers": {"@type": "Offer", "price": "54990.00", "priceCurrency": "RUB",
             "availability": "https://schema.org/InStock"},
  "additionalProperty": [{"@type": "PropertyValue", "name": "Тип", "value": "Автоматическая"}]
}
</script></head><body><h1>Кофемашина</h1></body></html>
"""


def test_extract_from_jsonld():
    data = extract_from_jsonld(JSONLD_HTML, "https://www.ozon.ru/product/x-1/")
    assert data is not None
    product = ProductData(**data)
    assert product.title == "Кофемашина DeLonghi Magnifica"
    assert product.price == 54990.0
    assert product.currency == "RUB"
    assert product.rating == 4.7
    assert product.reviews_count == 1532
    assert product.is_available is True
    assert product.attributes["Тип"] == "Автоматическая"
    assert product.attributes["Бренд"] == "DeLonghi"


def test_extract_from_jsonld_graph():
    html = """
    <script type="application/ld+json">
    {"@context":"https://schema.org","@graph":[
      {"@type":"BreadcrumbList"},
      {"@type":"Product","name":"Товар X","offers":{"@type":"Offer","price":100}}
    ]}
    </script>
    """
    data = extract_from_jsonld(html, "https://market.yandex.ru/product--x/1")
    assert data is not None and data["title"] == "Товар X"
    assert data["price"] == 100.0


def test_extract_from_selectors_amazon():
    selectors = selectors_for_host("amazon.com")
    assert selectors is not None
    html = """
    <html><body>
      <span id="productTitle">Sony WH-1000XM5</span>
      <span class="a-price"><span class="a-offscreen">$399.99</span></span>
      <span id="acrCustomerReviewText">12,345 ratings</span>
      <div id="availability">In Stock</div>
    </body></html>
    """
    data = extract_from_selectors(html, "https://www.amazon.com/dp/X", selectors)
    assert data is not None
    product = ProductData(**data)
    assert product.title == "Sony WH-1000XM5"
    assert product.price == 399.99
    assert product.currency == "USD"
    assert product.reviews_count == 12345
    assert product.is_available is True


def test_extract_from_regex_meta():
    html = """
    <html><head>
    <meta property="og:title" content="Тестовый товар"/>
    <meta property="product:price:amount" content="2499.00"/>
    </head><body></body></html>
    """
    data = extract_from_regex(html, "https://example.com/p/1")
    assert data is not None
    assert data["title"] == "Тестовый товар"
    assert data["price"] == 2499.0


def test_selectors_for_host_subdomain():
    assert selectors_for_host("m.ozon.ru") is selectors_for_host("ozon.ru")
    assert selectors_for_host("unknown-shop.test") is None
