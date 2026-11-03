<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\MediaLibrary;

use Illuminate\Support\Facades\Config;
use Mozex\Iminify\Laravel\IminifyOptimizer;
use Spatie\ImageOptimizer\Optimizer;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\OptimizerChainFactory;
use Spatie\ImageOptimizer\Optimizers\Cwebp;
use Spatie\ImageOptimizer\Optimizers\Gifsicle;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Optipng;
use Spatie\ImageOptimizer\Optimizers\Pngquant;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImage;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator as BaseGenerator;
use Spatie\MediaLibrary\Support\ImageFactory;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Media Library's generator, with each responsive width optimized through Iminify. Its own
 * calls `optimize()` with no chain, which always runs Spatie's binaries whatever the config
 * lists; this one runs the chain `image_optimizers` builds, so an AVIF width still reaches
 * avifenc. When that chain has no Iminify (the list leaves it out, or holds it alone, which the
 * factory answers with its defaults), Iminify takes the place of the binaries it replaces, so
 * no width is compressed twice. The provider binds it when
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

    /**
     * Spatie's optimizers for the formats Iminify takes, which it replaces in a chain.
     */
    protected const REPLACED = [Jpegoptim::class, Pngquant::class, Optipng::class, Gifsicle::class, Cwebp::class];

    protected function optimizerChain(): OptimizerChain
    {
        $optimizers = Config::get('media-library.image_optimizers');
        $optimizers = is_array($optimizers) ? $optimizers : [];
        $chain = OptimizerChainFactory::create($optimizers);

        foreach ($chain->getOptimizers() as $optimizer) {
            if ($optimizer instanceof IminifyOptimizer) {
                return $chain;
            }
        }

        $options = $optimizers[IminifyOptimizer::class] ?? [];

        return $chain
            ->setOptimizers(array_values(array_filter($chain->getOptimizers(), fn (Optimizer $optimizer): bool => ! in_array($optimizer::class, static::REPLACED, true))))
            ->addOptimizer(new IminifyOptimizer(is_array($options) ? $options : []));
    }
}
