<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit4cf85faa20a071d2df675605b5784e2d
{
    public static $prefixLengthsPsr4 = array (
        'M' => 
        array (
            'Monolog\\' => 8,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Monolog\\' => 
        array (
            0 => __DIR__ . '/..' . '/monolog/monolog/src/Monolog',
        ),
    );

    public static $prefixesPsr0 = array (
        'P' => 
        array (
            'Psr\\Log\\' => 
            array (
                0 => __DIR__ . '/..' . '/psr/log',
            ),
            'PayPal\\Service' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal\\PayPalAPI' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal\\EnhancedDataTypes' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal\\EBLBaseComponents' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal\\CoreComponentTypes' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/merchant-sdk-php/lib',
            ),
            'PayPal' => 
            array (
                0 => __DIR__ . '/..' . '/paypal/sdk-core-php/lib',
            ),
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit4cf85faa20a071d2df675605b5784e2d::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit4cf85faa20a071d2df675605b5784e2d::$prefixDirsPsr4;
            $loader->prefixesPsr0 = ComposerStaticInit4cf85faa20a071d2df675605b5784e2d::$prefixesPsr0;

        }, null, ClassLoader::class);
    }
}
