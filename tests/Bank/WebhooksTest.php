<?php

namespace Tests\Base\Ledger\Bank;

use Base\Database\Attribute\Vault;
use Base\Ledger\Bank\Webhooks;
use Base\Service\SettingBagInterface;
use Omnibank\Action\ActionInterface;
use Omnibank\Config;
use Omnibank\GatewayFactory;
use Omnibank\Model\Consent;
use Omnibank\Model\Notification;
use Omnibank\Model\Webhook;
use Omnibank\Registry;
use Omnibank\Request\DeleteWebhook;
use Omnibank\Request\FetchWebhooks;
use Omnibank\Request\Notify;
use Omnibank\Request\RegisterWebhook;
use Omnibank\Request\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Each bank gateway that can register its webhook does, its secret sealed in
 * the settings; the others are left to their console. No real provider can
 * today (Powens, Bridge): a provider made up for the test can.
 */
final class WebhooksTest extends TestCase
{
    private object $settings;

    protected function setUp(): void
    {
        if (!class_exists(RegisterWebhook::class)) {
            self::markTestSkipped('omnisense/omnibank with webhook registration is needed.');
        }
        HookedBank::$endpoints = [];
        HookedBank::$made = 0;
        $this->settings = new class implements SettingBagInterface {
            public array $values = [];
            public array $sealed = [];

            public function clearAll(): void {}
            public function all(?string $locale = null): array { return $this->values; }
            public function get(null|string|array $path = null, ?string $locale = null): array { return ['_self' => $this->values[$path] ?? null]; }
            public function getScalar(null|string|array $path, ?string $locale = null): mixed { return $this->values[$path] ?? null; }
            public function getBagParameters(): array { return []; }
            public function set(string $path, $value, ?string $locale = null): static
            {
                $this->values[$path] = $value;

                return $this;
            }
            public function setSecure(string $path, bool $flag = true): static
            {
                $this->sealed = array_values(array_unique([...$this->sealed, $path]));

                return $this;
            }
        };
    }

    private function webhooks(array $hookedOptions = [], string $host = 'https://books.example.org'): Webhooks
    {
        $registry = new Registry([new HookedBank(), new UnhookedBank()], [
            'hooked' => ['factory' => 'hooked', 'options' => $hookedOptions],
            'unhooked' => ['factory' => 'unhooked'],
        ]);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters) => $host.'/ledger/bank/'.$parameters['gateway'].'/webhook');

        return new Webhooks($router, $registry, $this->settings, new class extends Vault {
            public function __construct()
            {
            }

            public function getMarshaller(?string $vault = null): ?\Symfony\Component\Cache\Marshaller\MarshallerInterface
            {
                return new \Symfony\Component\Cache\Marshaller\SodiumMarshaller([sodium_crypto_box_keypair()]);
            }
        });
    }

    public function testTheGatewayThatCanIsRegisteredOnceTheOtherLeftAlone(): void
    {
        $webhooks = $this->webhooks();
        self::assertSame(['hooked', 'unhooked'], $webhooks->gateways());

        self::assertSame(Webhooks::REGISTERED, $webhooks->ensure('hooked'));
        self::assertSame(['https://books.example.org/ledger/bank/hooked/webhook'], array_column(HookedBank::$endpoints, 'url'));
        self::assertSame(['api.webhook.ledger_bank_webhook.hooked.secret'], $this->settings->sealed);
        self::assertSame('hmac_1', $webhooks->secret('hooked'));
        self::assertSame(['id' => 'wh_1', 'url' => 'https://books.example.org/ledger/bank/hooked/webhook'], $webhooks->registered('hooked'));

        self::assertSame(Webhooks::KEPT, $webhooks->ensure('hooked'));
        self::assertSame(Webhooks::REGISTERED, $webhooks->ensure('hooked', force: true));
        self::assertSame(['wh_2'], array_keys(HookedBank::$endpoints), 'replaced, never doubled');

        self::assertSame(Webhooks::UNSUPPORTED, $webhooks->ensure('unhooked'));
        self::assertSame(Webhooks::OFF, $webhooks->ensure('nowhere'));
    }

    public function testNothingRegisteredOnALocalHostNorOverAConfiguredSecret(): void
    {
        self::assertSame(Webhooks::UNREACHABLE, $this->webhooks(host: 'https://meyers.local')->ensure('hooked'));
        self::assertNotNull($this->webhooks(host: 'https://localhost:8463')->unreachable('hooked'));
        self::assertSame(Webhooks::CONFIGURED, $this->webhooks(['webhook_secret' => 'from_env'])->ensure('hooked'));
        self::assertSame([], HookedBank::$endpoints);
        self::assertSame([], $this->settings->values);
    }

    public function testAWebhookIsCheckedWithTheSecretRegisteredUnlessOneIsConfigured(): void
    {
        $webhooks = $this->webhooks();
        $webhooks->ensure('hooked');
        self::assertSame('hmac_1', $webhooks->notifier('hooked')->notify('{}')->raw['checked_with']);
        self::assertSame('from_env', $this->webhooks(['webhook_secret' => 'from_env'])->notifier('hooked')->notify('{}')->raw['checked_with']);
        self::assertNull($webhooks->notifier('unhooked')->notify('{}')->raw['checked_with'], 'nothing registered: the configured options as they are');
    }
}

/** A bank whose provider registers webhooks, in memory. */
final class HookedBank extends GatewayFactory
{
    /** @var array<string, array{id: string, url: string}> */
    public static array $endpoints = [];
    public static int $made = 0;

    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibank.factory_name' => 'hooked',
            'omnibank.factory_title' => 'Hooked',
            'webhook_secret' => null,
            'omnibank.action.notify' => static fn (Config $c) => new CheckedWith($c->get('webhook_secret')),
            'omnibank.action.webhooks' => new class implements ActionInterface {
                public function supports(Request $request): bool
                {
                    return $request instanceof RegisterWebhook || $request instanceof FetchWebhooks || $request instanceof DeleteWebhook;
                }

                public function execute(Request $request): void
                {
                    if ($request instanceof RegisterWebhook) {
                        HookedBank::$endpoints = array_filter(HookedBank::$endpoints, static fn (array $e) => $e['url'] !== $request->url);
                        $id = 'wh_'.++HookedBank::$made;
                        HookedBank::$endpoints[$id] = ['id' => $id, 'url' => $request->url];
                        $request->setResult(new Webhook($id, $request->url, ['CONNECTION_SYNCED'], 'hmac_'.HookedBank::$made));
                    } elseif ($request instanceof FetchWebhooks) {
                        $request->setResult(array_values(array_map(static fn (array $e) => new Webhook($e['id'], $e['url']), HookedBank::$endpoints)));
                    } else {
                        unset(HookedBank::$endpoints[$request->id]);
                        $request->setResult(true);
                    }
                }
            },
        ]);
    }
}

/** A bank whose provider has no API for its webhooks. */
final class UnhookedBank extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibank.factory_name' => 'unhooked',
            'omnibank.factory_title' => 'Unhooked',
            'webhook_secret' => null,
            'omnibank.action.notify' => static fn (Config $c) => new CheckedWith($c->get('webhook_secret')),
        ]);
    }
}

/** notify(): says which secret it was built with. */
final class CheckedWith implements ActionInterface
{
    public function __construct(private readonly ?string $secret)
    {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        $request->setResult(new Notification('TEST', null, Consent::none(), ['checked_with' => $this->secret]));
    }
}
