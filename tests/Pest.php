<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Mozex\Iminify\Client;
use Mozex\Iminify\Laravel\Tests\Fakes\FakeIminifyApi;
use Mozex\Iminify\Laravel\Tests\TestCase;

uses(TestCase::class)
    ->afterEach(function (): void {
        File::deleteDirectory(mediaRoot());

        foreach (temporaryFiles() as $path) {
            @unlink($path);
        }

        temporaryFiles(reset: true);
    })
    ->in(__DIR__);

/**
 * Where the tests' media disk keeps its files.
 */
function mediaRoot(): string
{
    return sys_get_temp_dir().'/iminify-laravel-media-'.getmypid();
}

/**
 * The files made for the running test, to remove after it.
 *
 * @return list<string>
 */
function temporaryFiles(?string $add = null, bool $reset = false): array
{
    static $files = [];

    if ($reset) {
        $files = [];
    }

    if ($add !== null) {
        $files[] = $add;
    }

    return $files;
}

/**
 * A real JPEG in the system's temporary folder, removed after the test.
 */
function jpeg(int $width = 64, int $height = 48): string
{
    $placeholder = (string) tempnam(sys_get_temp_dir(), 'iminify-laravel-');
    $path = $placeholder.'.jpg';
    temporaryFiles($placeholder);
    temporaryFiles($path);

    $picture = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x += 4) {
        imagefilledrectangle($picture, $x, 0, $x + 3, $height, (int) imagecolorallocate($picture, ($x * 7) % 256, ($x * 3) % 256, ($x * 11) % 256));
    }

    imagejpeg($picture, $path, 100);

    return $path;
}

/**
 * Has the app's client answer as the API would, for files whose size the test can't know.
 */
function fakeApi(): FakeIminifyApi
{
    $api = new FakeIminifyApi;

    app()->instance(Client::class, new Client($api, static function (float $seconds): void {}));

    return $api;
}
