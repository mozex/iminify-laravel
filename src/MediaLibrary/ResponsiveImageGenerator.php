<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\MediaLibrary;

use Illuminate\Support\Facades\Config;
use Mozex\Iminify\Laravel\IminifyOptimizer;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\OptimizerChainFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImage;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator as BaseGenerator;
use Spatie\MediaLibrary\Support\ImageFactory;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Media Library's generator, with each responsive width optimized through Iminify. Its own
 * calls `optimize()` with no chain, which always runs Spatie's binaries whatever the config
 * lists; this one runs the chain `image_optimizers` builds, with Iminify added when the list
 * lacks it, so an AVIF width still reaches avifenc. The provider binds it when
 * `iminify.media_library.responsive_images` is on.
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
            ->optimize($this->optimizerChain())
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

    protected function optimizerChain(): OptimizerChain
    {
        $optimizers = Config::get('media-library.image_optimizers');
        $chain = OptimizerChainFactory::create(is_array($optimizers) ? $optimizers : []);

        foreach ($chain->getOptimizers() as $optimizer) {
            if ($optimizer instanceof IminifyOptimizer) {
                return $chain;
            }
        }

        return $chain->addOptimizer(new IminifyOptimizer);
    }
}
