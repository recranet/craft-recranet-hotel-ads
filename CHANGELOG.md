# Release Notes for Recranet Hotel Ads

## 1.1.1

- Fixed the hotel ID on Craft CMS 4 when the prefix is an environment variable that is not set: the variable's name ended up in the hotel ID. Such a site now gets no output, as on Craft CMS 5.

## 1.1.0

- Craft CMS 4 support, next to Craft CMS 5. PHP 8.1 is now the minimum.

## 1.0.0

- Server-rendered Hotel Ads price as JSON-LD, from the Hotel Ads module of beaujean-vacances.
- Hotel Center prefix and Recranet organization ID per site.
- Accommodation looked up by slug in the Recranet API; no other Recranet plugin needed.
- Main image of the accommodation on the hotel and the room.
