<?php

namespace App\Support\Serialization;

use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Serializes a model to the frontend TypeScript shape: snake_case → camelCase keys, dates → ISO strings,
 * nulls dropped (front optional fields are `?: undefined`). Models list hidden keys in $frontHidden
 * and may rename keys through $frontRenames.
 */
trait FrontSerializable
{
    public function toFront(): array
    {
        $out = [];
        $hidden = property_exists($this, 'frontHidden') ? $this->frontHidden : [];
        $renames = property_exists($this, 'frontRenames') ? $this->frontRenames : [];
        foreach ($this->attributesToArray() as $key => $value) {
            if (in_array($key, $hidden, true) || $value === null) {
                continue;
            }
            $raw = $this->getAttribute($key);
            if ($raw instanceof DateTimeInterface) {
                $value = iso($raw);
            } elseif ($raw instanceof \BackedEnum) {
                $value = $raw->value;
            } elseif (is_array($raw)) {
                $value = $raw;
            }
            $out[$renames[$key] ?? Str::camel($key)] = $value;
        }

        return $out;
    }
}
