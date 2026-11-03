<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('iminify.api_key', 'test-key');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('filesystems.disks.media', ['driver' => 'local', 'root' => mediaRoot()]);
        $app['config']->set('media-library.disk_name', 'media');
        $app['config']->set('media-library.image_driver', 'gd');
        $app['config']->set('media-library.queue_conversions_by_default', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        (include __DIR__.'/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub')->up();
    }
}
