# Zangra Sylius Barcode Print Plugin

Sylius plugin (`zangra/sylius-barcode-print-plugin`) that prints product labels in ZPL on a TSC TTP-2410MU label printer (203 dpi, 50.8 x 25.4 mm, raw TCP port 9100).

## Documentation

### What it does

- **Label templates**: a ZPL file with `{{variable}}` tokens plus a table of variable definitions (key, label, type `text` / `ean13` / `icons`, default, required). Managed in the admin under Configuration > Label templates.
- **Printers**: host, port, transport and agent URL, managed in the admin under Configuration > Label printers.
- **Print button**: a hookable Twig component (`zangra_barcode_print:print_button`) that opens a modal with the template list (only when more than one template is enabled), the printer, the variable fields and the quantity.
- **Print agent**: a small Go program (`agent/`) that runs on each Windows PC and forwards the ZPL to the printer.

### How a label gets printed

Sylius usually runs remotely and cannot reach the printer, and a browser cannot open raw TCP. So:

```
Browser --1 XHR--> Sylius (validates, renders ZPL) --2 zpl--> Browser
Browser --3 POST http://127.0.0.1:9101/print--> agent on the PC --4 TCP 9100--> printer
```

- Transport `local_agent`: the endpoint `POST /admin/barcode-print/render` returns `{zpl, agentUrl, host, port}` and the browser sends it to the agent.
- Transport `server_tcp`: Sylius opens the TCP connection to the printer itself and returns `{status: sent}`. Use it only when Sylius can reach the printer (dev, VPN).

See [agent/README.md](agent/README.md) for the Windows agent (build, `agent.json`, running it as a service).

### Template format

Use a ZPL file with tokens, for example `resources/labels/cable-label.zpl`:

```
^FO15,8
^A0N,18,18
^FD{{code}}^FS
...
^PQ{{quantity}},0,1,N
```

- `{{quantity}}` is built in (1 to 999). Every other token needs a variable definition, which is validated when the template is saved.
- `ean13` values must be 13 digits with a valid check digit.
- `^`, `~` and control characters are stripped from text values.
- `icons` accepts `ce` and `weee`. They are placed in slots starting at x=275.
- The template path is relative to the templates directory and cannot leave it.

### Using the print button

```twig
{# the product variant page is already wired in zangra-sylius #}
{{ component('zangra_barcode_print:print_button', {
    defaults: {code: resource.code, ean13: resource.ean.code},
    templateCode: 'cable'
}) }}
```

`defaults` (variable key => value) prefills the form and `templateCode` (optional) limits the choice to one template. In Sylius admin, hook it into a twig hook such as `sylius_admin.product_variant.update.content.header.title_block.actions`.

## Installation

Requirements: PHP ^8.2, Sylius ^2.0 (tested with ^2.2).

1. Install the package. While it is not on Packagist, add a repository to the `composer.json` of your Sylius project, for example a path repository:

    ```json
    "repositories": [
        {"type": "path", "url": "../sylius-barcode-print-plugin", "options": {"symlink": true}}
    ]
    ```

    ```bash
    composer require zangra/sylius-barcode-print-plugin:@dev
    ```

2. Register the plugin in `config/bundles.php`:

    ```php
    Zangra\SyliusBarcodePrintPlugin\ZangraSyliusBarcodePrintPlugin::class => ['all' => true],
    ```

3. Import the plugin configuration in `config/packages/_sylius.yaml`:

    ```yaml
    imports:
        - { resource: "@ZangraSyliusBarcodePrintPlugin/config/config.yaml" }
    ```

4. Import the admin routes in `config/routes/admin/barcode_print.yaml` (or any routes file loaded under the admin prefix):

    ```yaml
    zangra_barcode_print_admin:
        resource: "@ZangraSyliusBarcodePrintPlugin/config/routes/admin.yaml"
    ```

5. Set the templates directory in `config/packages/zangra_barcode_print.yaml` (default `%kernel.project_dir%/labels`), and copy `resources/labels/cable-label.zpl` into it:

    ```yaml
    zangra_sylius_barcode_print:
        templates_path: '%kernel.project_dir%/labels'
    ```

6. Run the migration (creates the `zangra_barcode_*` tables):

    ```bash
    bin/console doctrine:migrations:migrate
    ```

7. Seed the example `cable` template and the `tsc-atelier` printer (safe to run twice):

    ```bash
    bin/console zangra:barcode-print:install-example
    # options: --printer-host=172.16.0.22 --printer-port=9100 --transport=local_agent|server_tcp
    ```

8. For `local_agent` printers, build and install the agent on each Windows PC. See [agent/README.md](agent/README.md).

## Setup and run the tests

### Setup

The tests use the Sylius test application from `sylius/test-application`.

```bash
composer install
```

With Docker instead of a local PHP (needs the `dom`, `xmlwriter`, `intl` and `pdo` extensions):

```bash
make init
```

Functional tests and Behat need a database. Set your credentials in `tests/TestApplication/.env` and `tests/TestApplication/.env.test`, then:

```bash
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console assets:install
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
```

or run `composer test-app-init`, or `make database-init` with Docker.

### Run the tests

- PHPUnit, unit tests only (renderer, icons, EAN13, transport, validators; no database needed):

    ```bash
    vendor/bin/phpunit --testsuite unit
    ```

- PHPUnit, everything (adds the functional tests for the admin CRUD and the print endpoint):

    ```bash
    vendor/bin/phpunit
    ```

- Behat (non-JS scenarios):

    ```bash
    vendor/bin/behat --strict --tags="~@javascript&&~@mink:chromedriver"
    ```

- Static analysis and coding standard:

    ```bash
    vendor/bin/phpstan analyse -c phpstan.neon
    vendor/bin/ecs check
    ```

- Go agent (Go 1.21 or newer, standard library only):

    ```bash
    cd agent && go vet ./... && go test ./...
    ```

With Docker, prefix commands with `docker compose run --rm php`, for example `docker compose run --rm php vendor/bin/phpunit --testsuite unit`.

### Try it in the test application

```bash
APP_ENV=test vendor/bin/console sylius:fixtures:load -n
APP_ENV=test symfony server:start -d
```

Then open the admin, go to Configuration > Label templates and Label printers.

## License

MIT, see [LICENSE](LICENSE).
