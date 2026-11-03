<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\MediaLibrary;

use Mozex\Iminify\Laravel\IminifyOptimizer;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImage;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator as BaseGenerator;
use Spatie\MediaLibrary\Support\ImageFactory;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Media Library's generator, with each responsive width optimized through Iminify. Its own
 * calls `optimize()` with no chain, which always runs Spatie's binaries whatever the config
 * lists. The provider binds this one when `iminify.media_library.responsive_images` is on.
 */
class ResponsiveImageGenerator extends BaseGenerator
{
    public function generateResponsiveImage(
        Media $media,
        string $baseImage,
        string $conversionName,
        int $targetWidth,
        TemporaryDirectory $temporaryDirectory,
        int $conversionQuality = self::DEFAULT_CONVERSION_QUALITY
    ): void {
        $extension = $this->fileNamer->extensionFromBaseImage($baseImage);
        $responsiveImagePath = $this->fileNamer->temporaryFileName($media, $extension);

        $tempDestination = $temporaryDirectory->path($responsiveImagePath);

        ImageFactory::load($baseImage)
            ->optimize(IminifyOptimizer::chain())
            ->width($targetWidth)
            ->quality($conversionQuality)
            ->save($tempDestination);

        $responsiveImageHeight = ImageFactory::load($tempDestination)->getHeight();

        $fileName = $this->addPropertiesToFileName($responsiveImagePath, $conversionName, $targetWidth, $responsiveImageHeight, $extension);

        $responsiveImagePath = $temporaryDirectory->path($fileName);

        rename($tempDestination, $responsiveImagePath);

        $this->filesystem->copyToMediaLibrary($responsiveImagePath, $media, 'responsiveImages');

        ResponsiveImage::register($media, $fileName, $conversionName);
    }
}
