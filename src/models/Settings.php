<?php

namespace recranet\craftrecranethotelads\models;

use craft\base\Model;
use craft\helpers\App;
use craft\models\Site;

class Settings extends Model
{
    /**
     * Recranet organization ID for every site, unless a site sets its own.
     * Accepts an environment variable, e.g. `$RECRANET_ORGANIZATION_ID`.
     */
    public string $organizationId = '';

    /**
     * Per site handle: the Hotel Center partner prefix (`hotelIdPrefix`) and
     * optionally its own `organizationId`. A site without a prefix gets no
     * output.
     *
     * @var array<string, array{hotelIdPrefix?: string, organizationId?: string}>
     */
    public array $sites = [];

    public function getOrganizationId(Site $site): ?int
    {
        $value = App::parseEnv($this->sites[$site->handle]['organizationId'] ?? '') ?: App::parseEnv($this->organizationId);

        return is_numeric($value) ? (int) $value : null;
    }

    public function getHotelIdPrefix(Site $site): ?string
    {
        $value = trim((string) App::parseEnv($this->sites[$site->handle]['hotelIdPrefix'] ?? ''));

        return $value !== '' ? $value : null;
    }

    protected function defineRules(): array
    {
        return [
            [['organizationId'], 'string'],
            [['sites'], 'validateSites'],
        ];
    }

    public function validateSites(string $attribute): void
    {
        foreach ($this->$attribute as $handle => $site) {
            foreach (['hotelIdPrefix', 'organizationId'] as $key) {
                $value = trim((string) ($site[$key] ?? ''));
                if ($value !== '' && !str_starts_with($value, '$') && !ctype_digit($value)) {
                    $this->addError($attribute, "$handle: $key must be a number or an environment variable.");
                }
            }
        }
    }
}
