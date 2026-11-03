<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Mozex\Iminify\Laravel\IminifyOptimizer;
use Mozex\Iminify\Laravel\IminifyServiceProvider;
use Mozex\Iminify\Laravel\MediaLibrary\ResponsiveImageGenerator;
use Mozex\Iminify\Laravel\Tests\Fixtures\Post;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\OptimizerChainFactory;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Svgo;
use Spatie\LaravelImageOptimizer\Middlewares\OptimizeImages;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator as MediaLibraryGenerator;

/**
 * The classes in a chain, in order.
 *
 * @return list<class-string>
 */
function optimizerClasses(OptimizerChain $chain): array
{
    return array_values(array_map(get_class(...), $chain->getOptimizers()));
}

/**
 * The chain a conversion will optimize with.
 */
function conversionChain(Conversion $conversion): OptimizerChain
{
    return $conversion->getManipulations()->toArray()['optimize'][0];
}

beforeEach(function () {
    Post::$queued = false;
    Post::$ownChain = false;
});

describe('laravel-image-optimizer', function () {
    beforeEach(function () {
        Config::set('image-optimizer.optimizers', [IminifyOptimizer::class => [], Svgo::class => []]);
    });

    it('runs the optimizer from its config list', function () {
        $path = jpeg();
        $before = filesize($path);
        $api = fakeApi();

        app(OptimizerChain::class)->optimize($path);

        expect(optimizerClasses(app(OptimizerChain::class)))->toBe([IminifyOptimizer::class, Svgo::class])
            ->and($api->uploadedNames())->toBe(['image.jpg'])
            ->and(filesize($path))->toBeLessThan($before);
    });

    it('optimizes an upload through its middleware before the controller reads it', function () {
        $api = fakeApi();
        $path = jpeg();
        $original = file_get_contents($path);
        Route::post('upload', fn (Request $request): int => strlen((string) file_get_contents((string) $request->file('photo')?->getRealPath())))->middleware(OptimizeImages::class);

        $size = (int) $this->post('upload', ['photo' => new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true)])->getContent();

        expect($api->uploadedNames())->toBe(['image.jpg'])
            ->and($size)->toBeGreaterThan(0)
            ->and($size)->toBeLessThan(strlen((string) $original));
    });
});

describe('Media Library', function () {
    it('misses a config holding only the optimizer, and finds it beside one of its own', function () {
        expect(optimizerClasses(OptimizerChainFactory::create([IminifyOptimizer::class => []])))->not->toContain(IminifyOptimizer::class)
            ->and(optimizerClasses(OptimizerChainFactory::create([IminifyOptimizer::class => [], Svgo::class => []])))->toBe([IminifyOptimizer::class, Svgo::class]);
    });

    it('optimizes a conversion through the config\'s list', function () {
        Config::set('media-library.image_optimizers', [IminifyOptimizer::class => [], Svgo::class => []]);
        $api = fakeApi();

        $media = Post::create()->addMedia(jpeg(400, 300))->toMediaCollection();
        $thumb = $media->getPath('thumb');

        $uploaded = $api->images['image-1'] ?? null;

        expect($api->uploadedNames())->toBe(['image.jpg'])
            ->and($thumb)->toBeFile()
            ->and(file_get_contents($thumb))->toBe($uploaded['optimized'] ?? null)
            ->and(strlen((string) ($uploaded['optimized'] ?? '')))->toBeLessThan(strlen((string) ($uploaded['original'] ?? '')));
    });

    it('runs its own binaries on responsive images unless told otherwise', function () {
        expect(app(MediaLibraryGenerator::class))->not->toBeInstanceOf(ResponsiveImageGenerator::class);
    });

    it('optimizes each responsive width through Iminify once turned on, by the job Media Library queues', function () {
        Config::set('iminify.media_library.responsive_images', true);
        Config::set('media-library.image_optimizers', [Svgo::class => []]);
        (new IminifyServiceProvider(app()))->packageBooted();
        $api = fakeApi();

        $media = Post::create()->addMedia(jpeg(400, 300))->withResponsiveImages()->toMediaCollection();
        $api->images = [];
        $api->requests = [];

        (new GenerateResponsiveImagesJob($media))->handle();

        $widths = $media->refresh()->responsive_images['media_library_original']['urls'] ?? [];

        expect(app(MediaLibraryGenerator::class))->toBeInstanceOf(ResponsiveImageGenerator::class)
            ->and($widths)->not->toBeEmpty()
            ->and($api->uploadedNames())->toHaveCount(count($widths));
    });

    it('carries the chain through a queued conversion with no key in it', function () {
        Config::set('iminify.api_key', 'a-secret-key');
        Config::set('media-library.image_optimizers', [IminifyOptimizer::class => [], Svgo::class => []]);
        Config::set('queue.default', 'sync');
        Post::$queued = true;
        $api = fakeApi();
        $payloads = [];
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$payloads): void {
            $payloads[] = $event->job->getRawBody();
        });

        $media = Post::create()->addMedia(jpeg(400, 300))->toMediaCollection();
        $conversions = array_values(array_filter($payloads, fn (string $payload): bool => str_contains($payload, 'PerformConversionsJob')));

        expect($conversions)->toHaveCount(1)
            ->and(str_contains($conversions[0], 'a-secret-key'))->toBeFalse()
            ->and(str_contains($conversions[0], 'IminifyOptimizer'))->toBeTrue()
            ->and($api->uploadedNames())->toBe(['image.jpg'])
            ->and($media->refresh()->hasGeneratedConversion('thumb'))->toBeTrue();
    });

    it('optimizes a conversion through its own chain while the config lists only Spatie\'s binaries', function () {
        Config::set('media-library.image_optimizers', [Jpegoptim::class => []]);
        Post::$ownChain = true;
        $api = fakeApi();

        $media = Post::create()->addMedia(jpeg(400, 300))->toMediaCollection();

        expect($api->uploadedNames())->toBe(['image.jpg'])
            ->and(file_get_contents($media->getPath('thumb')))->toBe($api->images['image-1']['optimized'] ?? null);
    });
});
