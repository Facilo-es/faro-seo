<?php

namespace facilo\faro\Craft;

use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;

/**
 * craft.schema: Spatie's schema builder, as SEOMate offers it, while SEOMate
 * isn't installed. craft.schema.localBusiness.name('…') returns a type whose
 * toScript() prints JSON-LD; craft.faro.schema(type.toArray()) adds it to the head.
 */
class SchemaVariable
{
    public function __call(string $name, array $args): Type
    {
        if (!method_exists(Schema::class, $name)) {
            throw new \BadMethodCallException("Unknown schema type: $name");
        }
        return Schema::$name(...$args);
    }

    public function __get(string $name): Type
    {
        return $this->__call($name, []);
    }

    public function __isset(string $name): bool
    {
        return method_exists(Schema::class, $name);
    }
}
