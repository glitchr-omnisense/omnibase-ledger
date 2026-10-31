<?php

namespace Base\Ledger\Bank;

use Base\Database\Attribute\Vault;
use Base\Service\SettingBagInterface;
use Omnibank\GatewayInterface;
use Omnibank\Http\PublicUrl;
use Omnibank\Model\Webhook;
use Omnibank\Registry;
use Omnibank\Request\FetchWebhooks;
use Omnibank\Request\RegisterWebhook;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The bank aggregators' webhooks, registered by the application on
 * ledger_bank_webhook - for each gateway that can (omnibank's optional
 * RegisterWebhook; neither Powens nor Bridge documents an API for it today,
 * their webhook stays in their console). The secret a provider answers is
 * kept in omnibase's settings, sealed by its #[Vault] (as /admin/api-key
 * keeps the API keys), and read when a webhook comes (notifier()). A
 * webhook_secret configured (an env value) wins. The webhook is optional:
 * ledger:bank:sync, scheduled, reads the banks without it.
 */
class Webhooks
{
    public const ROUTE = 'ledger_bank_webhook';
    public const SETTINGS = 'api.webhook.'.self::ROUTE;

    public const OFF = 'off';
    public const UNSUPPORTED = 'unsupported';
    public const CONFIGURED = 'configured';
    public const UNREACHABLE = 'unreachable';
    public const NO_VAULT = 'no_vault';
    public const KEPT = 'kept';
    public const REGISTERED = 'registered';

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly ?Registry $registry = null,
        private readonly ?SettingBagInterface $settings = null,
        private readonly ?Vault $vault = null,
    ) {
    }

    /** @return list<string> the gateways configured */
    public function gateways(): array
    {
        return null === $this->registry ? [] : $this->registry->names();
    }

    /** Where the gateway's provider posts: the route as the router builds it outside a request (its hosts, or framework.router.default_uri). */
    public function url(string $gateway): string
    {
        return $this->router->generate(self::ROUTE, ['gateway' => $gateway], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function unreachable(string $gateway): ?string
    {
        return !class_exists(PublicUrl::class) ? null : PublicUrl::unreachable($this->url($gateway));
    }

    public function configuredSecret(string $gateway): ?string
    {
        if (null === $this->registry || !$this->registry->has($gateway)) {
            return null;
        }
        $secret = $this->registry->options($gateway)['webhook_secret'] ?? null;

        return \is_string($secret) && '' !== $secret ? $secret : null;
    }

    /** The secret the provider answered when the application registered its webhook, unsealed; null when none. */
    public function secret(string $gateway): ?string
    {
        $secret = $this->stored($gateway, 'secret');

        return '' === (string) $secret ? null : (string) $secret;
    }

    /**
     * The gateway that checks a webhook: over the configured options, with
     * the secret registered here - unless a webhook_secret is configured,
     * which wins.
     */
    public function notifier(string $gateway): GatewayInterface
    {
        if (null === $this->registry) {
            throw new \LogicException('omnibank is not installed.');
        }
        if (null !== $this->configuredSecret($gateway) || null === $secret = $this->secret($gateway)) {
            return $this->registry->get($gateway);
        }

        return $this->registry->create($gateway, ['webhook_secret' => $secret]);
    }

    /**
     * Makes sure the gateway's provider posts to url($gateway), signed with a
     * secret kept here: KEPT when it already does, REGISTERED once registered
     * (the provider replaces the one on the same address) and its secret
     * sealed; every other answer says why nothing was done.
     *
     * @return self::*
     */
    public function ensure(string $gateway, bool $force = false): string
    {
        if (null === $this->registry || !$this->registry->has($gateway)) {
            return self::OFF;
        }
        if (!class_exists(RegisterWebhook::class)) {
            return self::UNSUPPORTED;   // an omnisense/omnibank older than webhook registration
        }
        $url = $this->url($gateway);
        if (null !== PublicUrl::unreachable($url)) {
            return self::UNREACHABLE;
        }
        $provider = $this->registry->get($gateway);
        if (!$provider->supports(RegisterWebhook::class)) {
            return self::UNSUPPORTED;
        }
        if (null !== $this->configuredSecret($gateway)) {
            return self::CONFIGURED;
        }
        if (!$force && null !== $this->secret($gateway) && $url === $this->stored($gateway, 'url') && $this->isRegistered($provider, (string) $this->stored($gateway, 'id'), $url)) {
            return self::KEPT;
        }
        // A key pair, not Vault::canSeal(): that one also answers true when clear text is allowed,
        // and omnibase's allowsPlaintext() reads its parameter as the whole bag (always true).
        if (null === $this->settings || null === ($this->vault ?? new Vault())->getMarshaller()) {
            return self::NO_VAULT;
        }

        $provider->execute($request = new RegisterWebhook($url));
        $this->store($gateway, $request->getWebhook());

        return self::REGISTERED;
    }

    /** @return array{id: ?string, url: ?string} the webhook registered, as kept here (never the secret) */
    public function registered(string $gateway): array
    {
        return ['id' => $this->stored($gateway, 'id'), 'url' => $this->stored($gateway, 'url')];
    }

    private function isRegistered(GatewayInterface $provider, string $id, string $url): bool
    {
        if ('' === $id || !$provider->supports(FetchWebhooks::class)) {
            return false;
        }
        $provider->execute($list = new FetchWebhooks());
        foreach ($list->getWebhooks() as $webhook) {
            if ($webhook->id === $id && $webhook->url === $url) {
                return true;
            }
        }

        return false;
    }

    private function store(string $gateway, ?Webhook $webhook): void
    {
        if (null === $webhook || null === $webhook->secret) {
            throw new \RuntimeException(sprintf('The "%s" gateway registered its webhook without answering its secret.', $gateway));
        }
        $path = self::SETTINGS.'.'.$gateway;
        if (method_exists($this->settings, 'setSecure')) {
            $this->settings->setSecure($path.'.secret');   // the vault set before the value: sealed when written
        }
        $this->settings->set($path.'.secret', $webhook->secret);
        $this->settings->set($path.'.id', $webhook->id);
        $this->settings->set($path.'.url', $webhook->url);
    }

    private function stored(string $gateway, string $key): ?string
    {
        if (null === $this->settings) {
            return null;
        }
        try {
            $value = $this->settings->getScalar(self::SETTINGS.'.'.$gateway.'.'.$key);
        } catch (\Throwable) {
            return null;
        }

        return \is_scalar($value) ? (string) $value : null;
    }
}
