<?php

namespace recranet\craftrecranethotelads;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use recranet\craftrecranethotelads\models\Settings;
use recranet\craftrecranethotelads\services\Offers;
use recranet\craftrecranethotelads\twig\Extension;

/**
 * Server-rendered price for Google Hotel Ads landing pages.
 *
 * Google's Hotel Ads verifier often gives up before Recranet Elements has
 * loaded the price with JavaScript. With dateFrom and dateTo in the URL the
 * accommodation page carries the price in its HTML as JSON-LD instead.
 *
 * @property-read Offers $offers
 * @method Settings getSettings()
 */
class HotelAds extends Plugin
{
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'offers' => Offers::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('_recranet-hotel-ads/settings.twig', [
            'settings' => $this->getSettings(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile($this->handle)),
        ]);
    }
}
