<?php

namespace facilo\faro\Craft;

use craft\elements\Asset;

/**
 * craft.seo, answering Ether SEO's template call while Ether is gone (F9).
 */
class EtherVariable
{
    /**
     * Ether's craft.seo.custom(title, description, image, social). The result
     * goes in a variable named "seo", which {% hook "seo" %} reads, as with Ether.
     *
     * @param array<string, mixed>|null $social twitter/facebook => title, description, image
     * @return array<string, mixed>
     */
    public function custom(string $title = '', string $description = '', Asset|int|null $image = null, ?array $social = null): array
    {
        $out = array_filter(['title' => $title, 'description' => $description], fn($v) => $v !== '');
        if ($image !== null) {
            $out['imageId'] = $image instanceof Asset ? (int)$image->id : $image;
        }
        foreach (['facebook' => 'og', 'twitter' => 'x'] as $from => $to) {
            $s = $social[$from] ?? null;
            if (!is_array($s)) {
                continue;
            }
            $img = $s['image'] ?? $s['imageId'] ?? null;
            $out['social'][$to] = array_filter([
                'title' => (string)($s['title'] ?? ''),
                'description' => (string)($s['description'] ?? ''),
                'imageId' => $img instanceof Asset ? (int)$img->id : (is_numeric($img) ? (int)$img : null),
            ], fn($v) => $v !== '' && $v !== null);
        }
        return $out;
    }
}
