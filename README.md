# Turnstile bundle

Strict [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) verification for Symfony
firewalls and forms.

- **Refuses whenever it cannot verify.** A timeout, a transport error, a non-2xx answer or a malformed
  one means the check failed, never that it passed.
- **Checks more than `success`.** The token must have been issued on the request host, or on a hostname
  you allow, for the action you expect. The visitor's IP is sent to Cloudflare, and the request is
  bounded to 5 seconds idle and 10 seconds in total.
- **Plugs into the firewall.** `form_login` keeps its CSRF check, login throttling and user checker. The
  Turnstile check runs after CSRF and throttling and before the user is loaded or the password hashed,
  so a bot costs no database lookup or password hash.
- **Has no off switch.** Tests run with Turnstile on, against a mock of Cloudflare's answer.

Requires PHP 8.3 or later and Symfony 7.4 or 8.

## Installation

```bash
composer require azymuthia/turnstile-bundle
```

Add the bundle to `config/bundles.php` if Flex did not:

```php
return [
    // ...
    Azymuthia\TurnstileBundle\AzymuthiaTurnstileBundle::class => ['all' => true],
];
```

Create a widget in the Cloudflare dashboard (Turnstile → Add widget). Use one widget per application and
environment, and Managed mode unless you have a reason to pick another. Then configure the bundle:

```yaml
# config/packages/azymuthia_turnstile.yaml
azymuthia_turnstile:
    site_key: '%env(CLOUDFLARE_TURNSTILE_SITE_KEY)%'
    secret_key: '%env(CLOUDFLARE_TURNSTILE_SECRET_KEY)%'

    # Hostnames a token may be issued on besides the request host: a list, or an env var.
    allowed_hostnames: '%env(csv:CLOUDFLARE_TURNSTILE_ALLOWED_HOSTNAMES)%'

    # The id of a service implementing Azymuthia\TurnstileBundle\VisitorIp\VisitorIpResolver.
    # Defaults to Request::getClientIp().
    visitor_ip_resolver: ~

    # Firewalls whose form_login requires a Turnstile token.
    firewalls:
        main: { action: login, field: cf-turnstile-response }
```

### The visitor's IP behind Cloudflare

Behind Cloudflare or another proxy, `Request::getClientIp()` is the proxy's address unless the proxy is
a [trusted proxy](https://symfony.com/doc/current/deployment/proxies.html). If it is not, implement
`VisitorIpResolver` and name the service in `visitor_ip_resolver`:

```php
use Azymuthia\TurnstileBundle\VisitorIp\VisitorIpResolver;
use Symfony\Component\HttpFoundation\Request;

final class CloudflareVisitorIp implements VisitorIpResolver
{
    public function resolve(Request $request): ?string
    {
        return $request->headers->get('CF-Connecting-IP') ?? $request->getClientIp();
    }
}
```

## Login (`form_login`)

List the firewall under `firewalls` and render the widget inside the login form:

```twig
<form method="post" action="{{ path('login') }}">
    {# _username, _password, _csrf_token ... #}
    {{ azymuthia_turnstile_widget('login') }}
    <button type="submit">Log in</button>
</form>
```

`azymuthia_turnstile_widget(action, options)` accepts `field_name` (default `cf-turnstile-response`),
`theme` (`auto`, `light` or `dark`; default `auto`), `size` (`flexible`, `normal` or `compact`; default
`flexible`) and `language` (default: the request locale). The action must match the firewall's `action`,
and the field name must match its `field`.

A refused login throws `Azymuthia\TurnstileBundle\Security\TurnstileAuthenticationException`. Its message
key, `Verification failed. Refresh the page and try again.`, is translated in the `security` domain and
is the same whether the check failed or Cloudflare could not be reached. `getErrorCodes()` tells the two
apart, for a `LoginFailureEvent` listener to log:

```php
use Azymuthia\TurnstileBundle\Security\TurnstileAuthenticationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

#[AsEventListener]
final class LogTurnstileFailures
{
    public function __invoke(LoginFailureEvent $event): void
    {
        $exception = $event->getException();

        if ($exception instanceof TurnstileAuthenticationException) {
            // $exception->getErrorCodes(), e.g. ['timeout-or-duplicate'] or ['unreachable']
        }
    }
}
```

A Turnstile failure counts towards login throttling like any other failed login.

Only `form_login` is covered: remember-me, HTTP basic and other authenticators on the same firewall are
left alone. A custom authenticator can require a token itself, on any firewall, by adding a badge to
its passport:

```php
use Azymuthia\TurnstileBundle\Security\TurnstileBadge;

return new Passport(
    new UserBadge($username),
    new PasswordCredentials($password),
    [new TurnstileBadge($request->request->getString('cf-turnstile-response'), 'login')],
);
```

## Forms

```php
use Azymuthia\TurnstileBundle\Form\TurnstileType;

$builder->add('turnstile', TurnstileType::class, [
    'turnstile_action' => 'password_reset',
]);
```

The field renders the widget and validates the token with the `Turnstile` constraint. The violation
message is translated in the `validators` domain. The field is not mapped. Options:

| Option                 | Default      | Meaning                                                |
|------------------------|--------------|--------------------------------------------------------|
| `turnstile_action`     | (required)   | The widget's action, which the token must match.       |
| `turnstile_validation` | `true`       | Validate the token when the form is submitted.         |
| `turnstile_theme`      | `auto`       | `auto`, `light` or `dark`.                             |
| `turnstile_size`       | `flexible`   | `flexible`, `normal` or `compact`.                     |
| `turnstile_language`   | request locale | The widget's language.                               |

The widget is rendered by the `turnstile_widget` block, which the bundle adds to `twig.form_themes`.

### Verifying later

Tokens are single-use. If something has to be checked before Turnstile, for example a rate limit, render
the field with `'turnstile_validation' => false` and verify the token yourself afterwards:

```php
use Azymuthia\TurnstileBundle\Verifier\TurnstileVerifier;

$result = $verifier->verify($form->get('turnstile')->getData(), 'password_reset', $request);

if (!$result->isSuccess()) {
    // $result->getErrorCodes()
}
```

`TurnstileVerifier` is a public service (also `azymuthia_turnstile.verifier`).

## Error codes

A refused check carries Cloudflare's [error codes](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/#error-codes),
sanitized, or one of these (constants on `Azymuthia\TurnstileBundle\Verifier\ErrorCode`):

| Code                     | Meaning                                                                         |
|--------------------------|---------------------------------------------------------------------------------|
| `unreachable`            | Siteverify timed out, could not be reached, or answered non-2xx or malformed.  |
| `missing-input-response` | No token was posted.                                                            |
| `invalid-input-response` | The token is longer than any Turnstile token.                                   |
| `invalid-input-secret`   | The secret is empty, or is a dummy secret in `prod`.                            |
| `hostname-mismatch`      | The token was issued on another hostname.                                       |
| `action-mismatch`        | The token was issued for another action.                                        |

Tokens and secrets are never logged. The bundle logs on the `turnstile` channel when Monolog is installed.

## Development: Cloudflare's dummy keys

Cloudflare publishes [dummy keys](https://developers.cloudflare.com/turnstile/troubleshooting/testing/)
for development. The secrets `1x0000000000000000000000000000000AA` (always passes),
`2x0000000000000000000000000000000AA` (always fails) and `3x0000000000000000000000000000000AA` (token
already spent) answer with the hostname `localhost` and the action `test`. For these secrets alone the
hostname and action match is skipped, so the dummy site keys work on any development host:

```dotenv
# .env
CLOUDFLARE_TURNSTILE_SITE_KEY=1x00000000000000000000AA
CLOUDFLARE_TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA
```

When `kernel.environment` is `prod`, a dummy secret refuses every check and logs at `critical`. An
always-passing secret in production would silently switch Turnstile off. Set the real keys in
production.

## Testing your application

The bundle never calls Cloudflare in tests if you swap its HTTP client, `azymuthia_turnstile.http_client`,
for a `MockHttpClient` answering through `TurnstileMockResponseFactory`:

```yaml
# config/services.yaml
when@test:
    services:
        Azymuthia\TurnstileBundle\Test\TurnstileMockResponseFactory: ~

        azymuthia_turnstile.http_client:
            class: Symfony\Component\HttpClient\MockHttpClient
            arguments: ['@Azymuthia\TurnstileBundle\Test\TurnstileMockResponseFactory']
```

`TurnstileMockResponseFactory::token($action)` returns a token that passes for that action. Anything else
is refused with `invalid-input-response`, as Cloudflare would refuse it:

```php
use Azymuthia\TurnstileBundle\Test\TurnstileMockResponseFactory;

$client->submitForm('Log in', [
    '_username' => 'alice',
    '_password' => 'secret',
    'cf-turnstile-response' => TurnstileMockResponseFactory::token('login'),
]);
```

The response's hostname is `localhost`, the test client's default. For another host, pass it to the
factory: `arguments: ['app.example.com']`. Use a secret that is not one of the dummy secrets in `test`
(e.g. `CLOUDFLARE_TURNSTILE_SECRET_KEY=test-secret` in `.env.test`). Otherwise the hostname and action
are not checked, and a token for the wrong action would pass.

## Licence

MIT, see [LICENSE](LICENSE).
