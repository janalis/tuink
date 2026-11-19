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
| `in_process` | in the Behat process, on a `VirtualTerminal`       | speed, reaching into the app from your own steps      |
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
| `process.probe_timeout` | `2.0`                | Seconds to wait for the application's `TuiProbe`                         |

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
| `When I click "selector"`                                     | The middle of the one match; see [Mouse](#mouse)                |
| `When I click on the text "Save"`                             | Its first character, where the screen first shows it            |
| `When I click at line 5, column 12`                           |                                                                 |
| `When I double-click …`, `triple-click`, `right-click`, `middle-click` | Any of the three forms above                           |
| `When I click … with "Ctrl+Shift"`                            | Shift, Alt, Ctrl held                                           |
| `When I scroll down 3 times over "selector"`                  | `up`, `down`, `left`, `right`; or `at line 5, column 12`        |
| `When I drag "selector" to "selector"`                        | Or `from line 2, column 1 to line 9, column 40`                 |
| `When I resize the terminal to 60x20`                         |                                                                 |
| `When I wait 1.5 seconds`                                     | Lets timers run                                                 |
| `When I stop the app`                                         |                                                                 |
| `Then I should see "text"` / `should not see`                 |                                                                 |
| `Then I should eventually see "text"`                         | Same as `should see`, which already waits; reads better         |
| `Then I should see text matching "/\d+ files/"`               |                                                                 |
| `Then line 3 should contain "text"` / `line 3 should be "…"`  | Lines count from 1; trailing spaces ignored                     |
| `Then the screen should be:` / `should match:` + doc string   | Trailing spaces and blank lines ignored                         |
| `Then the region at line 5, column 3 should be:` + doc string | A rectangle as wide and tall as the doc string, by display cell |
| `Then the screen should match [the] snapshot "name"`          | Written on first run; see below                                 |
| `Then the app should be running`                              |                                                                 |
| `Then the app should have exited [with code 2]`               | Exit codes need the `process` driver                            |
| `Then print the screen`                                       | For debugging                                                   |
| `Then I should see "text" in "#sidebar .title"`               | See [CSS selectors](#css-selectors)                             |
| `Then I should not see "text" in "selector"`                  | CSS selector                                                    |
| `Then I should [not] see an element matching "selector"`      | Drawn in the last frame                                         |
| `Then there should be 3 elements matching "selector"`         | Drawn or not                                                    |
| `Then the element "selector" should show:` + doc string       | Exactly one match                                               |
| `When I focus [the element] "selector"`                       | Exactly one match                                               |
| `Then the element "selector" should be focused`               | Any match                                                       |
| `Then the element "selector" should have the value "text"`    | Exactly one match whose `getValue()` returns a string           |

Every action waits until the app's output has been quiet for `quiet_period`. Every check retries until `timeout`, so a frame that comes late, after an HTTP call or a timer, does not fail the step. A failure prints the screen with its row numbers:

```
The text "Goodbye" was not found on the screen.

Screen (80x24, in_process driver, running):
1 | Tuink demo
2 | Status: ready
…
```

### Mouse

Symfony Tui has no mouse events of its own: it hands the SGR reports a terminal sends in mode 1006 (`ESC [ < 0 ; 12 ; 5 M`) to the application as `InputEvent` data, and the application decides what a click means, usually by testing `$tui->getWidgetRect($widget)->contains($row, $column)`. The mouse steps send those reports, the same bytes a terminal would, so they work on both drivers:

- a click is a press and a release; a double or triple click repeats them;
- the wheel sends presses of buttons 64 to 67;
- a drag presses, moves one cell at a time with the button held (motion reports), and releases;
- Shift, Alt and Ctrl add 4, 8 and 16 to the button code.

Clicking text needs nothing from the application. Clicking an element needs CSS selectors, so on the `process` driver the application must install the probe. A real terminal only reports the mouse once the application enables it (`ESC [ ? 1000 h` and `ESC [ ? 1006 h`); tuink sends reports either way, so enable them in your entry point or users will not get what your tests click. The demo's `example/bin/demo` shows both, and `DemoApp::handleMouse()` a hit test.

### CSS selectors

Widgets can be found with CSS selectors. tuink mirrors the widget tree into an XML document and queries it with [symfony/css-selector](https://symfony.com/doc/current/components/css_selector.html), so the whole selector grammar works: combinators, `:not()`, `:nth-child()`, attribute operators. Each widget is an element:

| CSS                        | Matches                                                                                  |
|----------------------------|------------------------------------------------------------------------------------------|
| `input`, `select-list`     | The class name without `Widget`, in kebab-case; `App\Ui\FileTree` is `file-tree`         |
| `#name`                    | `setId('name')`                                                                          |
| `.sidebar`                 | `addStyleClass('sidebar')`                                                               |
| `[label="Path"]`           | `setLabel('Path')`                                                                       |
| `[value="Ada"]`            | Widgets whose `getValue()` returns a string                                              |
| `[widget$="FileTree"]`     | The fully qualified class name                                                           |
| `:focus`                   | The focused widget                                                                       |
| `:visible`                 | Widgets drawn in the last frame                                                          |
| `:contains("text")`        | Widgets showing the text, children included                                              |
| `:root`                    | The root container `Tui::add()` adds to                                                  |

A widget's text is what the screen shows inside the rectangle it was drawn in, so `I should see "Saved" in "#status"` reads the screen, not the widget's properties.

```gherkin
@in_process
Scenario: The status bar
  Given I start the app
  When I press "Ctrl+S"
  Then I should see "Saved" in "#status-bar .message"
  And the element "tree:focus" should show:
    """
    ▾ src/
      Kernel.php
    """
```

In process, the driver reads the widgets itself. On the `process` driver, the application has to share them: install the probe in its entry point, before `run()`:

```php
use Janalis\Tuink\Probe\TuiProbe;

if (class_exists(TuiProbe::class)) { // tuink is a dev dependency
    TuiProbe::install($tui);
}
$tui->run();
```

The probe does nothing unless the process driver started the application. The driver then sets `TUINK_PROBE` to a Unix socket in a private temporary directory; the probe connects to it and answers the driver's requests for the widget tree, and to focus a widget, from the Revolt event loop. Element text still comes from the screen the driver decoded, so it is what a user would see. Without the probe, selector steps on the `process` driver wait `probe_timeout` once, then fail and say what to do.

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

Ask for the `Tuink` manager in the constructor:

```php
use Janalis\Tuink\Tuink;

final class StatusContext implements Behat\Behat\Context\Context
{
    public function __construct(private readonly Tuink $tuink) {}

    #[Behat\Step\Then('the status line should say :text')]
    public function statusLine(string $text): void
    {
        $this->tuink->getSession()->assertLineContains(2, $text);
    }
}
```

Or implement `TuinkAwareContext` (or use `TuinkAwareTrait`):

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

`Session` also has `type()`, `press()`, `send()`, `paste()`, `resize()`, `settle()`, `getScreenText()`, `getLines()`, `getLine()`, `getRegion()`, `snapshot()`, `find()`, `findOne()`, `dump()` and the `assert*()` methods behind the steps. On the `in_process` driver, `$session->getDriver()->getTui()` returns the application's `Tui`.

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
