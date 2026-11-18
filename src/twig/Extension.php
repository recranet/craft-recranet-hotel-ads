<?php

namespace recranet\craftrecranethotelads\twig;

use recranet\craftrecranethotelads\HotelAds;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

class Extension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('hotelAdsJsonLd', function(?string $slug): Markup {
                return new Markup(HotelAds::getInstance()->offers->getJsonLd($slug), 'UTF-8');
            }),
            new TwigFunction('hotelAdsOffer', function(?string $slug): ?array {
                return HotelAds::getInstance()->offers->getOffer($slug);
            }),
        ];
    }
}
