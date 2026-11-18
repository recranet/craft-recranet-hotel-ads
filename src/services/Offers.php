<?php

namespace recranet\craftrecranethotelads\services;

use Craft;
use craft\base\Component;
use craft\models\Site;
use GuzzleHttp\Exception\GuzzleException;
use recranet\craftrecranethotelads\HotelAds;

/**
 * Looks up the price of the stay in a Hotel Ads landing URL, so the page can
 * carry it in its HTML before Recranet Elements has loaded.
 *
 * Asks the pricing API the same question Elements asks. Imported
 * length-of-stay prices hold neither the number of persons nor the price
 * component group, and differ from the price Elements shows.
 */
class Offers extends Component
{
    private const ACCOMMODATION_URL = 'https://app.recranet.com/api/accommodations/';
    // Where the API's relative image paths live; the same base Recranet Elements uses.
    private const FILES_URL = 'https://files.recranet.app';
    private const PRICING_URL = 'https://pricing-api.recranet.com/v1/available_price/%s..%s';
    private const TIMEOUT = 1.5;
    private const CACHE_TTL = 900;
    private const CACHE_TTL_MISS = 300;
    private const ACCOMMODATION_TTL = 86400;
    private const ACCOMMODATION_TTL_MISS = 3600;

    /**
     * The offer for the stay in the current request's query string, or null
     * when the site has no Hotel Center prefix, there are no (valid) dates or
     * there is no available price.
     *
     * @return array{hotelId: string, offerId: string, accommodationId: int, name: string, image: string|null, checkin: string, checkout: string, numOfPersons: int|null, totalPrice: float, currency: string}|null
     */
    public function getOffer(?string $slug, ?Site $site = null): ?array
    {
        $site ??= Craft::$app->getSites()->getCurrentSite();
        $settings = HotelAds::getInstance()->getSettings();

        $prefix = $settings->getHotelIdPrefix($site);
        $organizationId = $settings->getOrganizationId($site);

        if ($prefix === null || $organizationId === null) {
            return null;
        }

        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return null;
        }

        $dateFrom = $request->getQueryParam('dateFrom');
        $dateTo = $request->getQueryParam('dateTo');

        if (!$slug || !$this->isDate($dateFrom) || !$this->isDate($dateTo) || $dateFrom >= $dateTo) {
            return null;
        }

        $query = [];

        $ageGroupLines = $request->getQueryParam('ageGroupLines');
        if ($ageGroupLines !== null) {
            $ageGroupLines = array_values((array) $ageGroupLines);
            foreach ($ageGroupLines as $line) {
                if (!is_string($line) || !preg_match('/^\d+,\d+$/', $line)) {
                    return null;
                }
            }
            $query['ageGroupLines'] = $ageGroupLines;
        }

        $group = $request->getQueryParam('accommodationPriceComponentGroup');
        if ($group !== null) {
            if (!is_string($group) || !ctype_digit($group)) {
                return null;
            }
            $query['accommodationPriceComponentGroup'] = $group;
        }

        $accommodation = $this->getAccommodation($organizationId, $slug, $site->getLocale()->getLanguageID());

        if ($accommodation === null) {
            return null;
        }

        $cacheKey = 'hotelAds:offer:v2:' . md5(serialize([$organizationId, $prefix, $accommodation['id'], $dateFrom, $dateTo, $query]));
        $cache = Craft::$app->getCache();

        $cached = $cache->get($cacheKey);
        if ($cached !== false) {
            return $cached ?: null;
        }

        $price = $this->fetchPrice($organizationId, $accommodation['id'], $dateFrom, $dateTo, $query);

        // The pricing API prices any party size; the accommodation does not take more than its maximum.
        if (
            $price !== null
            && $price['numOfPersons'] !== null
            && $accommodation['maxNumOfPersons'] !== null
            && $price['numOfPersons'] > $accommodation['maxNumOfPersons']
        ) {
            $price = null;
        }

        if ($price === null) {
            $cache->set($cacheKey, [], self::CACHE_TTL_MISS);

            return null;
        }

        $hotelId = $prefix . '_' . $accommodation['id'];

        $offer = [
            'hotelId' => $hotelId,
            // One offer per itinerary and rate plan (price component group).
            'offerId' => implode('_', array_filter([$hotelId, $dateFrom, $dateTo, $group])),
            'accommodationId' => $accommodation['id'],
            'name' => $accommodation['title'],
            'image' => $accommodation['image'],
            'checkin' => $dateFrom,
            'checkout' => $dateTo,
            'numOfPersons' => $price['numOfPersons'],
            'totalPrice' => $price['totalPrice'],
            'currency' => 'EUR',
        ];

        $cache->set($cacheKey, $offer, self::CACHE_TTL);

        return $offer;
    }

    /**
     * The offer as schema.org data, following
     * https://developers.google.com/hotels/hotel-prices/structured-data/hotel-price-structured-data:
     * the offer is both Offer and LodgingReservation and its total is a
     * CompoundPriceSpecification. Occupancy belongs to a HotelRoom, and once a
     * room is given its rate goes on the room, not on the hotel. Without a
     * number of persons the offer stays at hotel level.
     */
    public function getStructuredData(?string $slug, ?Site $site = null): ?array
    {
        $offer = $this->getOffer($slug, $site);

        if ($offer === null) {
            return null;
        }

        $lodgingOffer = [
            '@type' => ['Offer', 'LodgingReservation'],
            'identifier' => $offer['offerId'],
            'checkinTime' => $offer['checkin'],
            'checkoutTime' => $offer['checkout'],
            'priceSpecification' => [
                '@type' => 'CompoundPriceSpecification',
                'price' => number_format($offer['totalPrice'], 2, '.', ''),
                'priceCurrency' => $offer['currency'],
                'valueAddedTaxIncluded' => true,
            ],
        ];

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Hotel',
            'name' => $offer['name'],
            'identifier' => $offer['hotelId'],
        ];

        if ($offer['image']) {
            $data['image'] = $offer['image'];
        }

        if ($offer['numOfPersons']) {
            $data['containsPlace'] = [
                '@type' => ['HotelRoom', 'Product'],
                'name' => $offer['name'],
                'identifier' => (string) $offer['accommodationId'],
            ] + ($offer['image'] ? ['image' => $offer['image']] : []) + [
                'occupancy' => [
                    '@type' => 'QuantitativeValue',
                    'value' => $offer['numOfPersons'],
                ],
                'offers' => $lodgingOffer,
            ];
        } else {
            $data['makesOffer'] = $lodgingOffer;
        }

        return $data;
    }

    /**
     * The `<script type="application/ld+json">` tag, or an empty string.
     */
    public function getJsonLd(?string $slug, ?Site $site = null): string
    {
        $data = $this->getStructuredData($slug, $site);

        if ($data === null) {
            return '';
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * Accommodation by slug from the Recranet API, in the site's language.
     *
     * @return array{id: int, title: string, maxNumOfPersons: int|null, image: string|null}|null
     */
    private function getAccommodation(int $organizationId, string $slug, string $locale): ?array
    {
        $cacheKey = 'hotelAds:accommodation:v2:' . md5(serialize([$organizationId, $slug, $locale]));
        $cache = Craft::$app->getCache();

        $cached = $cache->get($cacheKey);
        if ($cached !== false) {
            return $cached ?: null;
        }

        try {
            $response = Craft::createGuzzleClient([
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::TIMEOUT,
            ])->get(self::ACCOMMODATION_URL, [
                'query' => [
                    'organization' => $organizationId,
                    'slug' => $slug,
                    'locale' => $locale,
                    'limit' => 1,
                ],
            ]);
        } catch (GuzzleException $e) {
            Craft::warning("Hotel Ads accommodation lookup failed for $slug: {$e->getMessage()}", __METHOD__);
            $cache->set($cacheKey, [], self::CACHE_TTL_MISS);

            return null;
        }

        $data = json_decode((string) $response->getBody(), true);
        $item = is_array($data) ? ($data[0] ?? null) : null;

        if (!is_array($item) || empty($item['id']) || ($item['slug'] ?? null) !== $slug) {
            $cache->set($cacheKey, [], self::ACCOMMODATION_TTL_MISS);

            return null;
        }

        $accommodation = [
            'id' => (int) $item['id'],
            'title' => (string) ($item['title'] ?? ''),
            'maxNumOfPersons' => isset($item['maxNumOfPersons']) ? (int) $item['maxNumOfPersons'] : null,
            'image' => !empty($item['image']) && is_string($item['image'])
                ? self::FILES_URL . '/' . ltrim($item['image'], '/')
                : null,
        ];

        $cache->set($cacheKey, $accommodation, self::ACCOMMODATION_TTL);

        return $accommodation;
    }

    /**
     * @return array{totalPrice: float, numOfPersons: int|null}|null
     */
    private function fetchPrice(int $organizationId, int $accommodationId, string $dateFrom, string $dateTo, array $query): ?array
    {
        $query = [
            'organization' => $organizationId,
            'accommodation' => $accommodationId,
        ] + $query;

        // PHP would encode ageGroupLines as ageGroupLines[0]=…; the API expects ageGroupLines[]=…
        $queryString = preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($query));

        try {
            $response = Craft::createGuzzleClient([
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::TIMEOUT,
            ])->get(sprintf(self::PRICING_URL, $dateFrom, $dateTo) . '?' . $queryString);
        } catch (GuzzleException $e) {
            Craft::warning("Hotel Ads price lookup failed for accommodation $accommodationId: {$e->getMessage()}", __METHOD__);

            return null;
        }

        $data = json_decode((string) $response->getBody(), true);

        if (
            !is_array($data)
            || empty($data['totalPrice'])
            || $data['totalPrice'] <= 0
            || empty($data['numOfUnitsAvailable'])
            || !empty($data['alternativePeriod'])
        ) {
            return null;
        }

        return [
            'totalPrice' => round((float) $data['totalPrice'], 2),
            'numOfPersons' => isset($data['numOfPersons']) ? (int) $data['numOfPersons'] : null,
        ];
    }

    private function isDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
