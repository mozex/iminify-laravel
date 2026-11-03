<?php

declare(strict_types=1);

namespace Mozex\Iminify\Laravel\Tests\Fakes;

use LogicException;
use Mozex\Iminify\Contracts\TransporterContract;
use Mozex\Iminify\Http\ApiRequest;
use Mozex\Iminify\Http\ApiResponse;
use Mozex\Iminify\Testing\Fixtures;

/**
 * Answers as the API would for files whose size a test can't know in advance, such as a Media
 * Library conversion: an upload finishes at once, and its copy is the file again as a JPEG at
 * low quality, or the same bytes with a few cut from the end for any other type.
 */
class FakeIminifyApi implements TransporterContract
{
    /** @var list<ApiRequest> */
    public array $requests = [];

    /** @var array<string, array{name: string, format: string, original: string, optimized: string}> */
    public array $images = [];

    public function send(ApiRequest $request): ApiResponse
    {
        $this->requests[] = $request;

        if ($request->method === 'POST' && $request->path === 'images' && $request->upload !== null) {
            return $this->receive($request);
        }

        $id = explode('/', $request->path)[1] ?? '';
        $image = $this->images[$id] ?? throw new LogicException("No image [{$id}] for {$request->method} {$request->path}.");

        return match (true) {
            $request->method === 'GET' && str_ends_with($request->path, '/download') => Fixtures::file($image['optimized'], $image['name']),
            $request->method === 'GET' => Fixtures::answer(['data' => $this->record($id)]),
            $request->method === 'DELETE' => Fixtures::noContent(),
            default => throw new LogicException("Nothing answers {$request->method} {$request->path}."),
        };
    }

    /**
     * @return list<string>
     */
    public function uploadedNames(): array
    {
        return array_column($this->images, 'name');
    }

    protected function receive(ApiRequest $request): ApiResponse
    {
        $stream = fopen('php://memory', 'w+b');
        $request->upload?->copyTo($stream);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        $name = (string) ($request->upload?->name ?? 'image.jpg');
        $format = pathinfo($name, PATHINFO_EXTENSION);
        $id = 'image-'.(count($this->images) + 1);

        $this->images[$id] = ['name' => $name, 'format' => $format, 'original' => $bytes, 'optimized' => $this->smaller($bytes, $format)];

        return Fixtures::answer(['data' => Fixtures::queuedImage(['id' => $id])], 201);
    }

    /**
     * @return array<string, mixed>
     */
    protected function record(string $id): array
    {
        $image = $this->images[$id];
        $file = ['name' => $image['name'], 'format' => $image['format'], 'width' => 1, 'height' => 1, 'download_url' => "https://www.iminify.com/api/v1/images/{$id}/download"];

        return Fixtures::image([
            'id' => $id,
            'status' => 'completed',
            'original' => ['size' => strlen($image['original'])] + $file,
            'optimized' => ['size' => strlen($image['optimized'])] + $file,
        ]);
    }

    protected function smaller(string $bytes, string $format): string
    {
        $picture = $format === 'jpg' ? @imagecreatefromstring($bytes) : false;

        if ($picture === false) {
            return substr($bytes, 0, max(1, strlen($bytes) - 8));
        }

        ob_start();
        imagejpeg($picture, null, 5);

        return (string) ob_get_clean();
    }
}
