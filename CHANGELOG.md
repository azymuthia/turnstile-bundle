# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-07

### Added

- `TurnstileVerifier`, verifying a token with Cloudflare's siteverify: the token must be issued on the
  request host (or an allowed hostname) for the expected action, the visitor's IP is sent, and the
  request is bounded (5 s idle, 10 s total). It refuses whenever it cannot verify, with the error code
  `unreachable`.
- Cloudflare's dummy test secrets skip the hostname and action match, and refuse every check in `prod`.
- `VisitorIpResolver`, to send the right visitor IP from behind a proxy or Cloudflare.
- Firewall integration: `form_login` on the configured firewalls requires a token, checked before the
  user is loaded or the password hashed, after CSRF and login throttling. `TurnstileBadge` for custom
  authenticators. A refusal is a `TurnstileAuthenticationException` carrying the error codes.
- `TurnstileType` form type and `Turnstile` constraint; the widget as a form theme block and as the
  `azymuthia_turnstile_widget()` Twig function.
- `TurnstileMockResponseFactory`, so applications' tests run with Turnstile on and never call Cloudflare.
- Translations: English and Polish.

[0.1.0]: https://github.com/azymuthia/turnstile-bundle/releases/tag/v0.1.0
