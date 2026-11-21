<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    foreach (['facilo\\faro\\tests\\' => __DIR__ . '/../', 'facilo\\faro\\Core\\' => __DIR__ . '/../../src/Core/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
