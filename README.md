# tuink

End-to-end tests for [Symfony Tui](https://github.com/symfony/tui) applications, written in Gherkin and run by [Behat](https://behat.org).

```gherkin
Feature: Signing in

  Scenario: A greeting after the name
    Given I start the app in a 80x24 terminal
    When I type "Ada"
    And I press "Enter"
    Then I should see "Hello, Ada!"
    And the screen should match the snapshot "greeted"
```

The same scenario runs on two drivers:

| Driver       | Runs the app                                       | Good for                                              |
|--------------|----------------------------------------------------|-------------------------------------------------------|
| `in_process` | in the Behat process, on a `VirtualTerminal`       | speed, checking widgets and focus                     |
| `process`    | as its own process, in a pseudo-terminal           | the real entry point: boot, raw mode, stdin, signals  |

## Install

```bash
composer require --dev janalis/tuink
```

PHP 8.4+, Behat 4, `symfony/tui` 8.2+. (No Behat 3 release accepts the Symfony 8 components Symfony Tui needs.) The `process` driver needs pseudo-terminals (Linux, macOS).

## Configure

`behat.dist.php`:

```php
use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Janalis\Tuink\Context\TuiContext;
use Janalis\Tuink\ServiceContainer\TuinkExtension;

return (new Config())
    ->withProfile((new Profile('default'))
        ->withSuite((new Suite('default'))->withContexts(TuiContext::class))
        ->withExtension(new Extension(TuinkExtension::class, [
            'in_process' => ['factory' => 'tests/tui-factory.php'],
            'process' => ['command' => ['php', 'bin/app']],
        ])));
```

| Option              | Default                  | Meaning                                                                   |
|---------------------|--------------------------|---------------------------------------------------------------------------|
| `default_driver`    | `in_process` if set      | Driver of untagged scenarios                                              |
| `columns`, `rows`   | `80`, `24`               | Terminal size at start                                                    |
| `timeout`           | `2.0`                    | Seconds a step waits for the screen to show what it expects               |
| `quiet_period`      | `0.02` / `0.05`          | Seconds without output after which the app counts as settled             |
| `snapshots`         | `features/__snapshots__` | Snapshot directory                                                        |
| `in_process.factory`| —                        | PHP file returning `callable(VirtualTerminal $terminal): Tui`             |
| `process.command`   | —                        | Command, as a list or a string split on spaces                            |
| `process.cwd`       | Behat base path          | Working directory                                                         |
| `process.env`       | `{}`                     | Extra environment variables                                               |

Relative paths are resolved from the Behat base path.

### The in-process factory

Build the application on the terminal you are given, and do not start it:

```php
// tests/tui-factory.php
use App\Ui\Application;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

return static fn (VirtualTerminal $terminal): Tui => Application::build($terminal);
```

Your entry point does the same with the real terminal and calls `run()`. The driver starts the `Tui` and turns the Revolt event loop in short slices, so timers and async work run as they do under `run()`.

## Steps

| Step                                                          | Notes                                                           |
|---------------------------------------------------------------|-----------------------------------------------------------------|
| `Given I start the app`                                       |                                                                 |
| `Given I start the app in a 100x30 terminal`                  |                                                                 |
| `When I type "text"`                                          | Characters, literally                                           |
| `When I press "Enter"` / `When I press "Down" 3 times`        | See key names below                                             |
| `When I send "Ada<Tab>Lovelace<Enter>"`                       | Text and `<Key>` names mixed                                    |
| `When I paste "text"` / `When I paste:` + doc string          | Bracketed paste                                                 |
| `When I resize the terminal to 60x20`                         |                                                                 |
| `When I wait 1.5 seconds`                                     | Lets timers run                                                 |
| `When I stop the app`                                         |                                                                 |
| `Then I should see "text"` / `should not see`                 |                                                                 |
| `Then I should see text matching "/\d+ files/"`               |                                                                 |
| `Then line 3 should contain "text"` / `line 3 should be "…"`  | Lines count from 1; trailing spaces ignored                     |
| `Then the screen should be:` + doc string                     | Trailing spaces and blank lines ignored                         |
| `Then the screen should match the snapshot "name"`            | Written on first run; see below                                 |
| `Then the app should be running`                              |                                                                 |
| `Then the app should have exited [with code 2]`               | Exit codes need the `process` driver                            |
| `Then print the screen`                                       | For debugging                                                   |
| `When I focus the widget "id"`                                | `in_process` only                                               |
| `Then the widget "id" should be focused`                      | `in_process` only                                               |
| `Then the widget "id" should have the value "text"`           | `in_process` only, widgets with `getValue()`                    |

Every action waits until the app's output has been quiet for `quiet_period`. Every check retries until `timeout`, so a frame that comes late, after an HTTP call or a timer, does not fail the step. A failure prints the screen with its row numbers:

```
The text "Goodbye" was not found on the screen.

Screen (80x24, in_process driver, running):
1 | Tuink demo
2 | Status: ready
…
```

### Keys

Names are case-insensitive, with optional angle brackets: `Enter`, `Escape`, `Tab`, `Space`, `Backspace`, `Delete`, `Insert`, `Up`, `Down`, `Left`, `Right`, `Home`, `End`, `PageUp`, `PageDown`, `F1`–`F12`, or any single character. Modifiers join with `+`: `Ctrl+S`, `Shift+Tab`, `Alt+Left`, `Ctrl+Shift+Up`. A combination a terminal cannot send, like `Ctrl+Tab`, is an error rather than a plain `Tab`. In `I send`, a key is a word of two letters or more (`<Enter>`, `<F5>`) or carries a modifier (`<Alt+x>`); `<b>` stays text.

### Snapshots

A missing snapshot is written from the current screen and the step passes; commit it. When the `CI` environment variable is set, a missing snapshot fails instead. To rewrite snapshots after an intended change:

```bash
TUINK_UPDATE_SNAPSHOTS=1 vendor/bin/behat
```

### Choosing the driver

Tag a feature or scenario `@in_process` or `@process`; a scenario tag wins over a feature tag. To run the whole suite on the other driver, add a profile:

```php
->withProfile((new Profile('process'))
    ->withExtension(new Extension(TuinkExtension::class, ['default_driver' => 'process'] + $settings)))
```

```bash
vendor/bin/behat --profile=process
```

## Your own steps

Implement `TuinkAwareContext` (or use `TuinkAwareTrait`) to get the session:

```php
use Behat\Step\Then;
use Janalis\Tuink\Context\TuinkAwareContext;
use Janalis\Tuink\Context\TuinkAwareTrait;

final class EditorContext implements TuinkAwareContext
{
    use TuinkAwareTrait;

    #[Then('the status bar should say :text')]
    public function statusBar(string $text): void
    {
        $session = $this->getSession();
        $session->waitUntil(
            static fn () => str_contains($session->getLine($session->getRows()), $text),
            sprintf('The status bar does not say "%s".', $text),
        );
    }
}
```

`Session` also has `type()`, `press()`, `send()`, `paste()`, `resize()`, `settle()`, `getScreenText()`, `getLines()`, `getLine()`, `dump()` and the `assert*()` methods behind the steps. On the `in_process` driver, `$session->getDriver()->getTui()` returns the application's `Tui`.

## Gotchas

- A change made outside input handling and ticks, in a timer or a promise callback, needs `$tui->requestRender()` to reach the screen. tuink shows exactly what the terminal would, so a missing request shows up as a step that times out.
- The `in_process` driver shares the Revolt event loop with Behat. When the application stops, the callbacks it registered on the loop are cancelled, so its timers cannot fire in the next scenario.
- The `process` driver reads the screen through `ScreenBuffer`, which understands what Symfony Tui writes. Programs that rely on other escape sequences (alternate screen, scroll regions) may not be decoded faithfully.

## Developing

```bash
composer install
composer test        # PHPUnit, then Behat on both drivers
composer analyze
composer cs
```

`example/` holds the demo app the self-tests drive.

## License

MIT, see [LICENSE](LICENSE).
