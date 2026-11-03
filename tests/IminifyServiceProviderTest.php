<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Config;
use Mozex\Iminify\Client;
use Mozex\Iminify\Laravel\Facades\Iminify;
use Mozex\Iminify\Laravel\IminifyServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * What the client's transporter holds: the HTTP client, the base address and the headers.
 *
 * @return array{client: ClientInterface, baseUri: string, headers: array<string, string>}
 */
function transporterOf(Client $client): array
{
    return (fn (): array => ['client' => $this->client, 'baseUri' => $this->baseUri, 'headers' => $this->headers])->call($client->transporter());
}

describe('the client', function () {
    it('is made once, from the config, the first time something asks for it', function () {
        Config::set('iminify.base_uri', 'https://iminify.example/api/v1');
        Config::set('iminify.timeout', 45);

        expect(app()->resolved(Client::class))->toBeFalse();

        $client = app(Client::class);
        $transporter = transporterOf($client);

        expect(app('iminify'))->toBe($client)
            ->and(Iminify::getFacadeRoot())->toBe($client)
            ->and($transporter['baseUri'])->toBe('https://iminify.example/api/v1')
            ->and($transporter['headers']['Authorization'])->toBe('Bearer test-key')
            ->and($transporter['headers']['Iminify-Client'])->toMatch('#\Alaravel/\d+\.\d+\.\d+#')
            ->and($transporter['client'])->toBeInstanceOf(GuzzleClient::class)
            ->and((fn (): array => $this->config)->call($transporter['client'])['timeout'])->toBe(45);
    });

    it('answers the live API when the base address is left blank', function () {
        Config::set('iminify.base_uri', null);

        expect(transporterOf(app(Client::class))['baseUri'])->toBe('https://www.iminify.com/api/v1');
    });

    it('refuses to start without a key, in words that name the setting', function (mixed $key) {
        Config::set('iminify.api_key', $key);

        app(Client::class);
    })->with([null, '', '   '])->throws(InvalidArgumentException::class, 'IMINIFY_API_KEY');

    it('refuses a timeout that is not a number of seconds', function (mixed $timeout) {
        Config::set('iminify.timeout', $timeout);

        app(Client::class);
    })->with([0, -5, 'soon'])->throws(InvalidArgumentException::class, 'iminify.timeout');
});

describe('the config', function () {
    it('fills a published group in from the defaults', function () {
        Config::set('iminify', ['api_key' => 'mine', 'optimizer' => ['level' => 'ultra']]);

        (new IminifyServiceProvider(app()))->packageRegistered();

        expect(Config::get('iminify.api_key'))->toBe('mine')
            ->and(Config::get('iminify.optimizer'))->toBe(['level' => 'ultra', 'keep_metadata' => false, 'timeout' => 120])
            ->and(Config::get('iminify.webhooks.tolerance'))->toBe(300);
    });
});
