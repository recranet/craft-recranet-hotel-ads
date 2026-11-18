# Recranet Hotel Ads

Craft CMS 5 plugin that puts the price of a Google Hotel Ads landing URL in the page's HTML as
JSON-LD, so Google's price verifier doesn't have to wait for Recranet Elements to load it with
JavaScript.

With `dateFrom` and `dateTo` in the URL, the accommodation page gets a `Hotel` with hotel ID
`<Hotel Center prefix>_<accommodation ID>`, a `HotelRoom` with the number of persons, and an
`Offer`/`LodgingReservation` with the total price including VAT. The hotel and the room carry
the accommodation's main image from Recranet, which Google's merchant listings check asks for
because the room is also a `Product`. The structure follows
[Google's hotel price structured data](https://developers.google.com/hotels/hotel-prices/structured-data/hotel-price-structured-data)
and passes the Rich Results Test.

The plugin needs no other Recranet plugin. It looks up the accommodation by slug in the
Recranet API and asks the pricing API the same question Elements asks.

## Requirements

- Craft CMS 5, PHP 8.2+
- A Hotel Center partner prefix for the site (e.g. `4298` for Beaujean)

## Install

```bash
composer require recranet/craft-recranet-hotel-ads
```

```bash
php craft plugin/install _recranet-hotel-ads
```

## Settings

In the control panel under Settings → Plugins → Recranet Hotel Ads, or in
`config/_recranet-hotel-ads.php`, which overrides the control panel:

```php
<?php

return [
    // Recranet organization ID, used by every site that doesn't set its own.
    'organizationId' => '$RECRANET_ORGANIZATION_ID',

    // Per site handle. A site without a hotelIdPrefix gets no output.
    'sites' => [
        'nl' => ['hotelIdPrefix' => '4298'],
        'de' => ['hotelIdPrefix' => '4298'],
        'en' => ['hotelIdPrefix' => '4298'],
        'fr' => ['hotelIdPrefix' => '4298'],
        // 'other' => ['hotelIdPrefix' => '1234', 'organizationId' => '5678'],
    ],
];
```

All values accept environment variables.

The hotel ID must match the hotel IDs in Hotel Center (Hotel list feed). Check one in
Hotel Center before switching a site on.

## Twig

Call the function in the accommodation detail template, in the `<head>` or anywhere in the page:

```twig
{{ hotelAdsJsonLd(craft.app.request.getSegment(2)) }}
```

The argument is the accommodation slug as Recranet knows it in the current site's language.
Which URL segment that is depends on the site's routes.

`hotelAdsOffer(slug)` returns the same data as an array (`hotelId`, `offerId`,
`accommodationId`, `name`, `image`, `checkin`, `checkout`, `numOfPersons`, `totalPrice`, `currency`) or
`null`, for a site that wants to render its own markup.

## Behaviour

- Output only when the URL has valid `dateFrom` and `dateTo` (`YYYY-MM-DD`, from before to).
- `ageGroupLines[]` and `accommodationPriceComponentGroup` from the URL are passed on to the
  pricing API. Malformed values mean no output.
- No output when there is no price, the accommodation isn't available, the party is larger than
  the accommodation takes, or the pricing API offers an alternative period.
- Both API calls time out after 1.5 s. A slow API means no output, never a slow page beyond that.
- Prices are cached for 15 minutes, misses for 5 minutes. Slug lookups for 24 hours, unknown
  slugs for 1 hour.
- Rendered for every visitor. It is never limited to Google's user agent, which Google treats
  as cloaking.
- Without a number of persons from the pricing API, the offer goes on the hotel (`makesOffer`)
  instead of on a room.

## Development

```bash
composer check-cs
```

```bash
composer phpstan
```
