# SavedPixel SEO Shield

Block junk search traffic, harden crawl-facing endpoints, and manage public-request protection rules from one SavedPixel settings page.

## What It Does

SavedPixel SEO Shield turns a set of common SEO and crawl-hardening rules into a configurable admin tool. It focuses on public search abuse, tracking-parameter cleanup, opt-in HTTPS enforcement, anonymous rate limiting, XML-RPC shutdown, and anonymous REST restrictions, while keeping a bounded event log of what the shield has blocked.

## Key Workflows

- Block spam-style search requests with a configurable regex and return `410 Gone`.
- Redirect or noindex normal search requests when public search should not be indexed or exposed.
- Require Google reCAPTCHA for protected search submissions.
- Strip tracking parameters from public URLs and redirect to the cleaned URL.
- Apply endpoint hardening rules such as XML-RPC disable and anonymous REST restriction.

## Features

- Spam search blocking with a configurable regex.
- Search-request redirect support for non-junk `?s=` requests.
- `noindex, follow` output for rendered search pages.
- Optional Google reCAPTCHA validation for search submissions when both keys are configured.
- Tracking-parameter stripping with a configurable comma-separated parameter list.
- Optional HTTPS enforcement for public frontend requests.
- Anonymous public-traffic rate limiting by IP.
- XML-RPC disable switch.
- Anonymous REST restriction with a configurable allowlist of public namespace prefixes.
- Built-in shield log with time, rule, request, IP, and details columns.
- Clear-log action from the admin page.

## Admin Page

The settings page is grouped into Search Shield, URL/traffic controls, endpoint hardening, and Shield Log sections. The default posture is conservative: stronger rules are opt-in where they could break public traffic if the site is not ready for them.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later

## Installation

1. Upload the `savedpixel-seo-shield` folder to `wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Open **SavedPixel > SEO Shield**.
4. Enable only the protections that match how your site handles search, anonymous traffic, and public APIs.

## Usage Notes

- Some rules can block legitimate public traffic if they are enabled without planning.
- Search reCAPTCHA is only enforced when the feature is enabled and both API keys are present.
- HTTPS forcing is intentionally opt-in so local and mixed-protocol environments are not broken by default.

## Author

**Byron Jacobs**  
[GitHub](https://github.com/savedpixel)

## License

GPL-2.0-or-later
